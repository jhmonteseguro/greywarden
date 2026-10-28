<?php
/**
 * @var yii\web\View $this
 * @var app\modules\inventory\models\DeviceSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\Lookup;
use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use app\modules\inventory\models\DeviceSearch;
use app\widgets\grid\ActionColumn;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;

$this->title = 'Device Inventory';
$this->params['breadcrumbs'][] = $this->title;
$this->params['actions'] = Rbac::canManage() ? Html::a('<i class="bi bi-plus-lg"></i> Add device', ['create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']) : '';
$today = date('Y-m-d');
?>
<?= QuickFilters::widget([
    'filters' => DeviceSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'placeholder' => 'Search tag, name, hostname, serial, IP, user, email…',
]) ?>
<?php if ($searchModel->control): $check = DeviceChecks::find((int) $searchModel->control); ?>
    <p class="gw-active-filter">Device check: <strong><?= Html::encode($check ? $check->reference . ' ' . $check->title : 'not in force') ?></strong>
        · <?= Html::encode(DevicePosture::resultLabels()[$searchModel->result] ?? $searchModel->result) ?>
        <?= Html::a('clear', \yii\helpers\Url::current(['control' => null, 'result' => null])) ?></p>
<?php endif; ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'filterModel' => $searchModel,
    'columns' => [
        ['class' => ReferenceColumn::class, 'attribute' => 'asset_tag'],
        [
            'attribute' => 'name',
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-col-title'],
            'value' => static function (Device $m) use ($today) {
                $flags = [];
                if ($m->isByod()) {
                    $flags[] = Badge::render('ownership', $m->ownership, 'BYOD', ['title' => 'Personally owned device']);
                }
                if ($m->isWarrantyExpiringSoon($today)) {
                    $flags[] = Badge::flag('expiring', 'Warranty expiring');
                }
                if ($m->isPastEol($today)) {
                    $flags[] = Badge::flag('eol', 'Past EOL');
                }
                $flags[] = Badge::osSupport($m->getOsSupport($today));
                $flags[] = Badge::lastSeen($m, $today);
                $flags = array_filter($flags);
                $meta = array_filter([$m->hostname !== $m->name ? $m->hostname : null, trim($m->manufacturer . ' ' . $m->model), trim($m->os . ' ' . $m->os_version)]);
                return Html::a(Html::encode($m->name), $m->getViewUrl(), ['class' => 'gw-title-link'])
                    . '<div class="gw-muted gw-small">' . Html::encode(implode(' · ', $meta)) . '</div>'
                    . ($flags ? '<div class="gw-flags">' . implode(' ', $flags) . '</div>' : '');
            },
        ],
        ['attribute' => 'device_type', 'value' => static fn(Device $m) => $m->getTypeLabel(), 'filter' => Device::typeOptions()],
        // Email is left to the record page (and the search box): it is often wider than the name
        ['attribute' => 'assigned_to', 'value' => static fn(Device $m) => $m->assigned_to ?: $m->assigned_email],
        [
            // One column for both keeps the table within the page width; each keeps its own filter
            'attribute' => 'department_id',
            'label' => 'Department / location',
            'format' => 'raw',
            'value' => static fn(Device $m) => $m->department || $m->location
                ? Html::encode($m->department->name ?? '—') . ($m->location ? '<div class="gw-muted gw-small">' . Html::encode($m->location->name) . '</div>' : '')
                : null,
            'filter' => Html::activeDropDownList($searchModel, 'department_id', Lookup::allOptions(Lookup::TYPE_DEPARTMENT), ['class' => 'form-select form-select-sm', 'prompt' => '', 'aria-label' => 'Department', 'title' => 'Department'])
                . Html::activeDropDownList($searchModel, 'location_id', Lookup::allOptions(Lookup::TYPE_LOCATION), ['class' => 'form-select form-select-sm mt-1', 'prompt' => '', 'aria-label' => 'Location', 'title' => 'Location']),
        ],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'value' => static fn(Device $m) => Badge::status('device-status', $m->status, Device::statusOptions()),
            'filter' => Device::statusOptions(),
        ],
        [
            'attribute' => 'criticality',
            'format' => 'raw',
            'value' => static fn(Device $m) => Badge::criticality($m->criticality),
            'filter' => Device::criticalityOptions(),
        ],
        [
            'label' => 'Posture',
            'format' => 'raw',
            'value' => static function (Device $m) {
                $p = $m->getPosture();
                return Badge::posture($p['state'], implode('; ', array_merge($p['failures'], $p['unknown'])) ?: null);
            },
        ],
        [
            'attribute' => 'last_patched_at',
            'format' => 'raw',
            'filter' => false,
            'value' => static function (Device $m) {
                if (!$m->last_patched_at) {
                    return null;
                }
                // Red when the device fails a patch-age check
                $fails = array_filter($m->getPosture()['items'], static fn($i) => $i['check']->isPatchAge() && $i['result'] === DevicePosture::RESULT_FAIL);
                return Html::tag('span', Yii::$app->formatter->asDate($m->last_patched_at), array_filter([
                    'class' => $fails ? 'gw-text-danger' : null,
                    'title' => $fails ? reset($fails)['detail'] : null,
                ]));
            },
        ],
        [
            'attribute' => 'last_seen_at',
            'format' => 'raw',
            'filter' => false,
            'contentOptions' => ['class' => 'gw-col-date'],
            'value' => static function (Device $m) use ($today) {
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
