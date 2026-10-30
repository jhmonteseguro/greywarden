<?php
/**
 * @var yii\web\View $this
 * @var app\modules\cloud\models\CloudAccountSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\User;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAccountSearch;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use app\widgets\grid\ActionColumn;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\db\Query;
use yii\helpers\Html;

$this->title = 'Cloud Accounts';
$this->params['breadcrumbs'][] = ['label' => 'Cloud Inventory', 'url' => ['/cloud/asset/index']];
$this->params['breadcrumbs'][] = 'Accounts';
$this->params['actions'] = Html::a('<i class="bi bi-cloud"></i> Assets', ['/cloud/asset/index'], ['class' => 'btn btn-outline-secondary gw-btn'])
    . (Rbac::canManage() ? ' ' . Html::a('<i class="bi bi-plus-lg"></i> Add account', ['create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']) : '');
// Live assets per account on this page, in one query
$ids = array_map(static fn(CloudAccount $a) => $a->id, $dataProvider->getModels());
$assetCounts = $ids ? (new Query())->select(['n' => 'COUNT(*)', 'account_id'])->from('cloud_asset')
    ->where(['account_id' => $ids, 'archived_at' => null])->groupBy('account_id')->indexBy('account_id')->column() : [];
?>
<?= QuickFilters::widget([
    'filters' => CloudAccountSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'placeholder' => 'Search reference, name, account identifier…',
]) ?>
<?php if ($searchModel->control): $check = CloudChecks::find((int) $searchModel->control); ?>
    <p class="gw-active-filter">Cloud check: <strong><?= Html::encode($check ? $check->reference . ' ' . $check->title : 'not in force') ?></strong>
        · <?= Html::encode(DevicePosture::resultLabels()[$searchModel->result] ?? $searchModel->result) ?>
        <?= Html::a('clear', \yii\helpers\Url::current(['control' => null, 'result' => null])) ?></p>
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
            'value' => static fn(CloudAccount $m) => Html::a(Html::encode($m->name), $m->getViewUrl(), ['class' => 'gw-title-link'])
                . ($m->account_identifier ? '<div class="gw-muted gw-small gw-mono">' . Html::encode($m->account_identifier) . '</div>' : ''),
        ],
        ['attribute' => 'provider', 'value' => static fn(CloudAccount $m) => $m->getProviderLabel(), 'filter' => CloudCatalogue::providerOptions()],
        [
            'attribute' => 'environment',
            'format' => 'raw',
            'value' => static fn(CloudAccount $m) => Badge::render('environment', $m->environment, $m->getEnvironmentLabel()),
            'filter' => CloudAccount::environmentOptions(),
        ],
        ['attribute' => 'owner_id', 'value' => static fn(CloudAccount $m) => $m->owner->full_name ?? null, 'filter' => User::options()],
        [
            'label' => 'Assets',
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-mono'],
            'value' => static fn(CloudAccount $m) => Html::a((string) (int) ($assetCounts[$m->id] ?? 0), ['/cloud/asset/index', 'filter' => 'all', 'account_id' => $m->id]),
        ],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'value' => static fn(CloudAccount $m) => Badge::status('cloud-status', $m->status, CloudAccount::statusOptions()),
            'filter' => CloudAccount::statusOptions(),
        ],
        [
            'attribute' => 'criticality',
            'format' => 'raw',
            'value' => static fn(CloudAccount $m) => Badge::criticality($m->criticality),
            'filter' => Device::criticalityOptions(),
        ],
        [
            'label' => 'Settings',
            'format' => 'raw',
            'value' => static fn(CloudAccount $m) => $m->finding_count
                ? Badge::render('severity', $m->finding_severity, $m->finding_count . ' finding' . ($m->finding_count === 1 ? '' : 's'),
                    ['title' => implode('; ', array_column($m->getFindings(), 'title'))])
                : ($m->hasConfiguration() ? '<span class="gw-muted">No findings</span>' : null),
        ],
        [
            'label' => 'Posture',
            'format' => 'raw',
            'value' => static function (CloudAccount $m) {
                $p = $m->getPosture();
                return Badge::posture($p['state'], implode('; ', array_merge($p['failures'], $p['unknown'])) ?: null);
            },
        ],
        ['class' => ActionColumn::class],
    ],
]) ?>
