<?php
/**
 * @var yii\web\View $this
 * @var app\modules\cloud\models\CloudAssetSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\cloud\models\CloudAssetSearch;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use app\widgets\grid\ActionColumn;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;

$this->title = 'Cloud Inventory';
$this->params['breadcrumbs'][] = $this->title;
$this->params['actions'] = Html::a('<i class="bi bi-cloud-check"></i> Accounts', ['/cloud/account/index'], ['class' => 'btn btn-outline-secondary gw-btn'])
    . (Rbac::canManage() ? ' ' . Html::a('<i class="bi bi-plus-lg"></i> Add asset', ['create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']) : '');
$today = date('Y-m-d');
?>
<?= QuickFilters::widget([
    'filters' => CloudAssetSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'placeholder' => 'Search reference, name, resource ID, region, IP, endpoint, account…',
]) ?>
<?php if ($searchModel->control): $check = CloudChecks::find((int) $searchModel->control); ?>
    <p class="gw-active-filter">Cloud check: <strong><?= Html::encode($check ? $check->reference . ' ' . $check->title : 'not in force') ?></strong>
        · <?= Html::encode(DevicePosture::resultLabels()[$searchModel->result] ?? $searchModel->result) ?>
        <?= Html::a('clear', \yii\helpers\Url::current(['control' => null, 'result' => null])) ?></p>
<?php endif; ?>
<?php if ($searchModel->category): ?>
    <p class="gw-active-filter">Category: <strong><?= Html::encode(CloudCatalogue::categoryLabel($searchModel->category)) ?></strong>
        <?= Html::a('clear', \yii\helpers\Url::current(['category' => null])) ?></p>
<?php endif; ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'filterModel' => $searchModel,
    'columns' => [
        ['class' => ReferenceColumn::class, 'attribute' => 'reference'],
        [
            'attribute' => 'name',
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-col-title'],
            'value' => static function (CloudAsset $m) use ($today) {
                $flags = [];
                if ($m->isInternetFacing()) {
                    $flags[] = Badge::render('exposure', 'internet', 'Internet-facing', ['icon' => 'globe2']);
                }
                $flags[] = Badge::osSupport($m->getOsSupport($today));
                $flags[] = Badge::lastSeen($m, $today);
                $flags = array_filter($flags);
                $meta = array_filter([$m->region, trim($m->os . ' ' . $m->os_version)]);
                return Html::a(Html::encode($m->name), $m->getViewUrl(), ['class' => 'gw-title-link'])
                    . ($meta ? '<div class="gw-muted gw-small">' . Html::encode(implode(' · ', $meta)) . '</div>' : '')
                    . ($flags ? '<div class="gw-flags">' . implode(' ', $flags) . '</div>' : '');
            },
        ],
        [
            'attribute' => 'resource_type',
            'format' => 'raw',
            'value' => static fn(CloudAsset $m) => Html::encode($m->getTypeLabel()) . '<div class="gw-muted gw-small">' . Html::encode($m->getCategoryLabel()) . '</div>',
            'filter' => CloudCatalogue::groupedTypeOptions(),
        ],
        [
            // Provider and account share a column; each keeps its own filter
            'attribute' => 'account_id',
            'label' => 'Account',
            'format' => 'raw',
            'value' => static fn(CloudAsset $m) => $m->account
                // Name only (reference and provider in the tooltip), so the narrow column doesn't wrap over four lines
                ? Html::a(Html::encode($m->account->name), $m->account->getViewUrl(),
                    ['class' => 'gw-title-link', 'title' => $m->account->reference . ' · ' . $m->account->getProviderLabel()])
                : null,
            'filter' => Html::activeDropDownList($searchModel, 'provider', CloudCatalogue::providerOptions(), ['class' => 'form-select form-select-sm', 'prompt' => '', 'aria-label' => 'Provider', 'title' => 'Provider'])
                . Html::activeDropDownList($searchModel, 'account_id', CloudAccount::selectOptions(), ['class' => 'form-select form-select-sm mt-1', 'prompt' => '', 'aria-label' => 'Account', 'title' => 'Account']),
        ],
        [
            'attribute' => 'environment',
            'format' => 'raw',
            'value' => static fn(CloudAsset $m) => Badge::render('environment', $m->environment, $m->getEnvironmentLabel()),
            'filter' => CloudAccount::environmentOptions(),
        ],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'value' => static fn(CloudAsset $m) => Badge::status('cloud-status', $m->status, CloudAsset::statusOptions()),
            'filter' => CloudAsset::statusOptions(),
        ],
        [
            'attribute' => 'criticality',
            'format' => 'raw',
            'value' => static fn(CloudAsset $m) => Badge::criticality($m->criticality),
            'filter' => Device::criticalityOptions(),
        ],
        [
            'label' => 'Posture',
            'format' => 'raw',
            'value' => static function (CloudAsset $m) {
                $p = $m->getPosture();
                return Badge::posture($p['state'], implode('; ', array_merge($p['failures'], $p['unknown'])) ?: null);
            },
        ],
        [
            'attribute' => 'last_seen_at',
            'format' => 'raw',
            'filter' => false,
            'contentOptions' => ['class' => 'gw-col-date'],
            'value' => static function (CloudAsset $m) use ($today) {
                $days = $m->getLastSeenDays($today);
                return $days === null ? null : Html::tag('span', $days === 0 ? 'Today' : $days . 'd ago', [
                    'class' => ($tone = $m->getLastSeenTone($today)) ? 'gw-text-' . $tone : null,
                    'title' => Yii::$app->formatter->asDatetime($m->last_seen_at),
                ]);
            },
        ],
        ['class' => ActionColumn::class],
    ],
]) ?>
