<?php
/**
 * @var yii\web\View $this
 * @var app\modules\cloud\models\CloudAccount $model
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\Attachment;
use app\modules\attack\components\CloudAttack;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use app\widgets\Attachments;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use app\widgets\LinkedRecords;
use app\widgets\RecordActions;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Cloud Accounts', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->reference;
$posture = $model->getPosture();
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->reference) . '</span> '
    . Badge::status('cloud-status', $model->status, CloudAccount::statusOptions()) . ' '
    . Badge::render('environment', $model->environment, $model->getEnvironmentLabel()) . ' '
    . Badge::posture($posture['state']);
$this->params['actions'] = RecordActions::widget([
    'model' => $model,
    'before' => Rbac::canManage() && !$model->getIsArchived()
        ? Html::a('<i class="bi bi-plus-lg"></i> Add asset', ['/cloud/asset/create', 'account' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn'])
        : '',
]);

$assets = $model->getAssets()->orderBy(['archived_at' => SORT_ASC, 'reference' => SORT_ASC])->all();
$live = array_filter($assets, static fn(CloudAsset $a) => !$a->getIsArchived());
$exposed = array_filter($live, static fn(CloudAsset $a) => $a->isInternetFacing());
$failing = array_filter($live, static fn(CloudAsset $a) => $a->isInService() && $a->getPosture()['failures']);
$platforms = CloudAttack::accountPlatforms($model);
$resultIcons = [DevicePosture::RESULT_PASS => ['is-ok', 'check-circle-fill'], DevicePosture::RESULT_FAIL => ['is-fail', 'x-circle-fill'], DevicePosture::RESULT_UNKNOWN => ['is-unknown', 'question-circle']];

ob_start(); ?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Cloud account</h2>
            <div class="row">
                <div class="col-lg-6">
                    <?= DetailView::widget([
                        'model' => $model,
                        'attributes' => [
                            ['attribute' => 'reference', 'contentOptions' => ['class' => 'gw-mono']],
                            'name',
                            ['attribute' => 'provider', 'value' => $model->getProviderLabel()],
                            ['attribute' => 'account_identifier', 'contentOptions' => ['class' => 'gw-mono']],
                            ['attribute' => 'owner_id', 'value' => $model->owner->full_name ?? null],
                        ],
                    ]) ?>
                </div>
                <div class="col-lg-6">
                    <?= DetailView::widget([
                        'model' => $model,
                        'attributes' => [
                            ['attribute' => 'environment', 'format' => 'raw', 'value' => Badge::render('environment', $model->environment, $model->getEnvironmentLabel())],
                            ['attribute' => 'criticality', 'format' => 'raw', 'value' => Badge::criticality($model->criticality)],
                            ['attribute' => 'data_classification', 'format' => 'raw', 'value' => Badge::render('classification', $model->data_classification, Device::classificationOptions()[$model->data_classification] ?? null)],
                            ['label' => 'Assets', 'format' => 'raw', 'value' => Html::a(count($live) . ' in inventory', ['/cloud/asset/index', 'filter' => 'all', 'account_id' => $model->id])
                                . ($exposed ? ' · ' . count($exposed) . ' internet-facing' : '') . ($failing ? ' · <span class="gw-text-danger">' . count($failing) . ' failing checks</span>' : '')],
                        ],
                    ]) ?>
                </div>
            </div>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Notes', 'text' => $model->notes]) ?>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="gw-panel gw-posture gw-posture--<?= Html::encode($posture['state']) ?>">
            <h2 class="gw-panel__title">Account checks</h2>
            <div class="gw-posture__status"><?= Badge::posture($posture['state']) ?></div>
            <?php if (!$posture['items']): ?>
                <p class="gw-empty">No account-level cloud check is in force.</p>
            <?php else: ?>
            <ul class="gw-checklist">
                <?php foreach ($posture['items'] as $id => $item): [$cls, $icon] = $resultIcons[$item['result']]; ?>
                    <li class="gw-checklist__item <?= $cls ?>">
                        <i class="bi bi-<?= $icon ?>" aria-hidden="true" title="<?= Html::encode(DevicePosture::resultLabels()[$item['result']]) ?>"></i>
                        <span><?= Html::a(Html::encode($item['check']->title), ['/control/control/view', 'id' => $id], ['class' => 'gw-checklist__link']) ?>
                            <?php if ($item['result'] === DevicePosture::RESULT_UNKNOWN): ?><span class="gw-muted">· Not assessed</span><?php endif; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <p class="gw-muted gw-small mt-2 mb-0">Asset checks are shown on each asset. <?= count(CloudChecks::forAssets()) ?> cloud check(s) apply to assets.</p>
        </section>
        <section class="gw-panel gw-cloud-attack">
            <h2 class="gw-panel__title"><i class="bi bi-crosshair" aria-hidden="true"></i> ATT&amp;CK</h2>
            <dl class="gw-dl">
                <dt>Platform</dt>
                <dd><?= Html::encode(implode(', ', $platforms)) ?></dd>
            </dl>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">Record</h2>
            <?= $this->render('@app/views/partials/_record-meta', ['model' => $model]) ?>
        </section>
    </div>
</div>
<?php $details = ob_get_clean();

$assetList = LinkedRecords::widget(['title' => 'Assets in this account', 'icon' => 'cloud', 'models' => $assets, 'emptyText' => 'No assets recorded yet.',
    'extra' => static fn(CloudAsset $a) => '<span class="gw-muted">' . Html::encode($a->getTypeLabel()) . '</span> '
        . ($a->isInternetFacing() ? Badge::render('exposure', 'internet', 'Internet-facing', ['icon' => 'globe2']) . ' ' : '')
        . Badge::posture($a->getPosture()['state'])]);
$attachments = Attachment::listFor($model); ?>
<?= $this->render('@app/views/partials/_archived-notice', ['model' => $model]) ?>

<?= Tabs::widget([
    'options' => ['class' => 'gw-tabs'],
    'tabContentOptions' => ['class' => 'gw-tab-content'],
    'items' => [
        ['label' => 'Details', 'content' => $details, 'options' => ['id' => 'tab-details']],
        ['label' => 'Assets (' . count($live) . ')', 'content' => $assetList, 'options' => ['id' => 'tab-assets']],
        ['label' => 'Files (' . count($attachments) . ')', 'content' => Attachments::widget(['model' => $model, 'attachments' => $attachments]), 'options' => ['id' => 'tab-files']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => 'cloud_account', 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ],
]) ?>
