<?php
/**
 * @var yii\web\View $this
 * @var app\modules\cloud\models\CloudAsset $model
 */

use app\helpers\Badge;
use app\models\Attachment;
use app\models\RecordNote;
use app\modules\attack\components\CloudAttack;
use app\modules\attack\models\AttackTechnique;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudCheck;
use app\modules\cloud\components\CloudChecks;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\components\OsLifecycle;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\widgets\Attachments;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use app\widgets\LinkedRecords;
use app\widgets\Notes;
use app\widgets\RecordActions;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Cloud Inventory', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->reference;
$posture = $model->getPosture();
$osSupport = $model->getOsSupport();
$hasOs = CloudCatalogue::hasOs($model->resource_type);
$flags = array_filter([
    $model->isInternetFacing() ? Badge::render('exposure', 'internet', 'Internet-facing', ['icon' => 'globe2']) : '',
    $hasOs ? Badge::osSupport($osSupport) : '',
    Badge::lastSeen($model),
]);
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->reference) . '</span> '
    . Badge::status('cloud-status', $model->status, $model::statusOptions()) . ' '
    . Badge::posture($posture['state']) . ' ' . implode(' ', $flags);
$this->params['actions'] = RecordActions::widget(['model' => $model]);
$fmt = Yii::$app->formatter;
$account = $model->account;

$risks = $model->risks;
$incidents = $model->incidents;
$decisions = $model->decisions;
$platforms = CloudAttack::assetPlatforms($model);
$observed = $incidents ? AttackTechnique::find()->with('parent')
    ->innerJoin(['l' => 'incident_attack_technique'], 'l.technique_id = attack_technique.id')
    ->where(['l.incident_id' => array_map(static fn($i) => $i->id, $incidents)])
    ->distinct()->all() : [];
// ATT&CK mitigation ids shown next to each cloud check
$attackIds = static fn(CloudCheck $check) => implode('', array_map(
    static fn($id) => Html::a(Html::encode($id), ['/attack/mitigation/view', 'id' => $id], ['class' => 'gw-checklist__attack', 'title' => 'ATT&CK mitigation ' . $id]),
    $check->mitigations
));
$resultIcons = [DevicePosture::RESULT_PASS => ['is-ok', 'check-circle-fill'], DevicePosture::RESULT_FAIL => ['is-fail', 'x-circle-fill'], DevicePosture::RESULT_UNKNOWN => ['is-unknown', 'question-circle']];
$skipped = count(CloudChecks::forAssets()) - count($posture['items']);
$codeList = static fn(array $items) => $items ? implode('<br>', array_map(static fn($v) => '<code class="gw-code">' . Html::encode($v) . '</code>', $items)) : null;

ob_start(); ?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Cloud asset</h2>
            <div class="row">
                <div class="col-lg-6">
                    <?= DetailView::widget([
                        'model' => $model,
                        'attributes' => [
                            ['attribute' => 'reference', 'contentOptions' => ['class' => 'gw-mono']],
                            'name',
                            ['attribute' => 'resource_type', 'value' => $model->getTypeLabel() . ' (' . $model->getCategoryLabel() . ')'],
                            ['attribute' => 'account_id', 'format' => 'raw', 'value' => $account
                                ? Html::a(Html::encode($account->reference), $account->getViewUrl(), ['class' => 'gw-ref']) . ' ' . Html::encode($account->name)
                                    . '<div class="gw-muted gw-small">' . Html::encode($account->getProviderSummary()) . '</div>'
                                : null],
                            ['attribute' => 'resource_id', 'format' => 'raw', 'value' => $model->resource_id ? '<code class="gw-code">' . Html::encode($model->resource_id) . '</code>' : null],
                            ['attribute' => 'region', 'contentOptions' => ['class' => 'gw-mono']],
                            ['attribute' => 'os', 'visible' => $hasOs],
                            ['attribute' => 'os_version', 'visible' => $hasOs, 'contentOptions' => ['class' => 'gw-mono']],
                            [
                                'label' => 'OS support',
                                'format' => 'raw',
                                'visible' => $hasOs && (bool) $model->os,
                                'value' => $osSupport['endDate']
                                    ? Html::encode(($osSupport['state'] === OsLifecycle::UNSUPPORTED ? 'Ended ' : 'Until ') . $fmt->asDate($osSupport['endDate']))
                                        . ($osSupport['estimated'] ? ' <span class="gw-muted">(estimated)</span>' : '') . ' ' . Badge::osSupport($osSupport)
                                    : '<span class="gw-muted">Unknown for this OS / version</span>',
                            ],
                        ],
                    ]) ?>
                </div>
                <div class="col-lg-6">
                    <?= DetailView::widget([
                        'model' => $model,
                        'attributes' => [
                            ['attribute' => 'environment', 'format' => 'raw', 'value' => Badge::render('environment', $model->environment, $model->getEnvironmentLabel())],
                            ['attribute' => 'owner_id', 'value' => $model->owner->full_name ?? null],
                            ['attribute' => 'criticality', 'format' => 'raw', 'value' => Badge::criticality($model->criticality)],
                            ['attribute' => 'data_classification', 'format' => 'raw', 'value' => Badge::render('classification', $model->data_classification, Device::classificationOptions()[$model->data_classification] ?? null)],
                            ['attribute' => 'encryption_at_rest', 'format' => 'raw', 'value' => Badge::render('encryption', $model->encryption_at_rest, $model::encryptionOptions()[$model->encryption_at_rest] ?? null)],
                        ],
                    ]) ?>
                </div>
            </div>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Notes', 'text' => $model->notes]) ?>
        </section>
        <section class="gw-panel gw-cloud-exposure">
            <h2 class="gw-panel__title"><i class="bi bi-globe2" aria-hidden="true"></i> Exposure</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'internet_facing', 'format' => 'raw', 'value' => $model->isInternetFacing()
                        ? Badge::render('exposure', 'internet', 'Internet-facing', ['icon' => 'globe2'])
                        : '<span class="gw-muted">Not reachable from the internet</span>'],
                    ['attribute' => 'public_ips', 'format' => 'raw', 'value' => $model->getIpList()
                        ? implode('<br>', array_map(static fn($ip) => Html::a('<code class="gw-code">' . Html::encode($ip) . '</code>', ['/intel/investigate/ip', 'ip' => $ip], ['title' => 'Investigate ' . $ip]), $model->getIpList()))
                        : null],
                    ['attribute' => 'public_endpoints', 'format' => 'raw', 'value' => $codeList($model->getEndpointList())],
                ],
            ]) ?>
        </section>
        <?php if ($model->source || $model->last_seen_at): ?>
        <section class="gw-panel gw-cloud-sync">
            <h2 class="gw-panel__title"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Sync</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'source', 'value' => $model->getSourceLabel()],
                    ['attribute' => 'source_id', 'contentOptions' => ['class' => 'gw-mono']],
                    'first_seen_at:datetime',
                    ['attribute' => 'last_seen_at', 'format' => 'raw', 'value' => $model->last_seen_at
                        ? $fmt->asDatetime($model->last_seen_at) . ' <span class="gw-muted">(' . $fmt->asRelativeTime($model->last_seen_at) . ')</span> '
                            . Badge::lastSeen($model)
                        : null],
                ],
            ]) ?>
        </section>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <section class="gw-panel gw-posture gw-posture--<?= Html::encode($posture['state']) ?>">
            <h2 class="gw-panel__title">Security posture</h2>
            <div class="gw-posture__status"><?= Badge::posture($posture['state']) ?></div>
            <?php if (!$posture['items']): ?>
                <p class="gw-empty">No cloud check applies to this resource type.</p>
            <?php else: ?>
            <ul class="gw-checklist">
                <?php foreach ($posture['items'] as $id => $item): [$cls, $icon] = $resultIcons[$item['result']]; ?>
                    <li class="gw-checklist__item <?= $cls ?>">
                        <i class="bi bi-<?= $icon ?>" aria-hidden="true" title="<?= Html::encode(DevicePosture::resultLabels()[$item['result']]) ?>"></i>
                        <span><?= Html::a(Html::encode($item['check']->title), ['/control/control/view', 'id' => $id], ['class' => 'gw-checklist__link']) ?>
                            <?php if ($item['detail'] || $item['result'] === DevicePosture::RESULT_UNKNOWN): ?>
                                <span class="gw-muted">· <?= Html::encode($item['detail'] ?? 'Not assessed') ?></span>
                            <?php endif; ?></span>
                        <?= $attackIds($item['check']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($skipped > 0): ?>
                <p class="gw-muted gw-small mt-2 mb-0"><?= $skipped ?> other cloud check(s) do not apply to this resource type.</p>
            <?php endif; ?>
        </section>
        <section class="gw-panel gw-cloud-attack">
            <h2 class="gw-panel__title"><i class="bi bi-crosshair" aria-hidden="true"></i> ATT&amp;CK</h2>
            <dl class="gw-dl">
                <dt>Platform</dt>
                <dd><?= Html::encode(implode(', ', $platforms)) ?></dd>
                <dt>Observed</dt>
                <dd><?= TechniqueTags::widget(['items' => $observed, 'compact' => true, 'emptyText' => 'No techniques recorded on linked incidents']) ?></dd>
            </dl>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">Record</h2>
            <?= $this->render('@app/views/partials/_record-meta', ['model' => $model]) ?>
        </section>
    </div>
</div>
<?php $details = ob_get_clean();

ob_start(); ?>
<p class="gw-muted">Links are managed from the risk, incident and decision records.</p>
<div class="row g-4">
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Linked risks', 'icon' => 'exclamation-triangle', 'models' => $risks,
        'extra' => static fn(Risk $r) => Badge::score($r->getCurrentScore()) . ' ' . Badge::status('risk-status', $r->status, Risk::statusOptions())]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Linked incidents', 'icon' => 'fire', 'models' => $incidents,
        'extra' => static fn(Incident $i) => Badge::severity($i->severity) . ' ' . Badge::status('incident-status', $i->status, Incident::statusOptions())]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Linked decisions', 'icon' => 'journal-check', 'models' => $decisions,
        'extra' => static fn(Decision $d) => Badge::status('decision-status', $d->status, Decision::statusOptions())]) ?></div>
</div>
<?php $links = ob_get_clean(); ?>

<?php $attachments = Attachment::listFor($model);
$notes = RecordNote::listFor($model); ?>
<?= $this->render('@app/views/partials/_archived-notice', ['model' => $model]) ?>

<?= Tabs::widget([
    'options' => ['class' => 'gw-tabs'],
    'tabContentOptions' => ['class' => 'gw-tab-content'],
    'items' => [
        ['label' => 'Details', 'content' => $details, 'options' => ['id' => 'tab-details']],
        ['label' => 'Notes (' . count($notes) . ')', 'content' => Notes::widget(['model' => $model, 'notes' => $notes, 'attachments' => $attachments]), 'options' => ['id' => 'tab-notes']],
        ['label' => 'Risks, incidents & decisions (' . (count($risks) + count($incidents) + count($decisions)) . ')', 'content' => $links, 'options' => ['id' => 'tab-links']],
        ['label' => 'Files (' . count($attachments) . ')', 'content' => Attachments::widget(['model' => $model, 'attachments' => $attachments]), 'options' => ['id' => 'tab-files']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => 'cloud_asset', 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ],
]) ?>
