<?php
/**
 * @var yii\web\View $this
 * @var app\modules\control\models\ControlSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\User;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\models\Control;
use app\modules\control\models\ControlSearch;
use app\widgets\grid\ActionColumn;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;

$this->title = 'Control Register';
$this->params['breadcrumbs'][] = $this->title;
$this->params['actions'] = Html::a('<i class="bi bi-patch-check"></i> Frameworks', ['/control/framework/index'], ['class' => 'btn btn-outline-secondary gw-btn'])
    . (Rbac::canManage() ? ' ' . Html::a('<i class="bi bi-plus-lg"></i> New control', ['create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']) : '');
$today = date('Y-m-d');
?>
<?= QuickFilters::widget([
    'filters' => ControlSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'placeholder' => 'Search reference, title, description…',
]) ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'filterModel' => $searchModel,
    'columns' => [
        ['class' => ReferenceColumn::class, 'attribute' => 'reference'],
        [
            'attribute' => 'title',
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-col-title'],
            'value' => static function (Control $m) use ($today) {
                $flags = [];
                if ($m->device_check) {
                    $flags[] = Badge::render('control-type', 'device-check', 'Device check', ['title' => $m->getDeviceScopeLabel(), 'icon' => 'pc-display']);
                }
                if ($m->cloud_check) {
                    $flags[] = Badge::render('control-type', 'cloud-check', 'Cloud check', ['title' => $m->getCloudScopeLabel(), 'icon' => 'cloud']);
                }
                if ($m->supplier_check) {
                    $flags[] = Badge::render('control-type', 'supplier-check', 'Supplier check', ['title' => $m->getSupplierScopeLabel(), 'icon' => 'buildings']);
                }
                if ($flag = $m->testFlag($today)) {
                    $flags[] = $flag;
                }
                if ($m->isTargetOverdue($today)) {
                    $flags[] = Badge::flag('overdue', 'Past target date');
                }
                return Html::a(Html::encode($m->title), $m->getViewUrl(), ['class' => 'gw-title-link'])
                    . ($flags ? '<div class="gw-flags">' . implode(' ', $flags) . '</div>' : '');
            },
        ],
        ['attribute' => 'control_type', 'value' => static fn(Control $m) => $m->getTypeLabel(), 'filter' => Control::typeOptions()],
        [
            'attribute' => 'requirement',
            'label' => 'Frameworks',
            'format' => 'raw',
            'enableSorting' => false,
            'contentOptions' => ['class' => 'gw-col-attack'],
            'filterInputOptions' => ['placeholder' => 'A.8.7, CE-UA…'],
            'value' => static function (Control $m) {
                $out = [];
                foreach ($m->frameworkSummary() as $framework => $ids) {
                    $shown = array_slice($ids, 0, 3);
                    $out[] = '<span class="gw-framework-ids"><span class="gw-framework-ids__name">' . Html::encode(FrameworkCatalogue::shortLabel($framework)) . '</span> '
                        . Html::encode(implode(', ', $shown)) . (count($ids) > 3 ? ' +' . (count($ids) - 3) : '') . '</span>';
                }
                return $out ? implode('', $out) : '<span class="gw-null">&mdash;</span>';
            },
        ],
        ['attribute' => 'owner_id', 'value' => 'owner.full_name', 'filter' => User::options()],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'value' => static fn(Control $m) => Badge::status('control-status', $m->status, Control::statusOptions()),
            'filter' => Control::statusOptions(),
        ],
        [
            'attribute' => 'effectiveness',
            'format' => 'raw',
            'value' => static fn(Control $m) => Badge::effectiveness($m->effectiveness),
            'filter' => Control::effectivenessOptions(),
        ],
        [
            'attribute' => 'next_test_due',
            'format' => 'raw',
            'filter' => false,
            'contentOptions' => ['class' => 'gw-col-date'],
            'value' => static fn(Control $m) => $m->next_test_due
                ? Html::tag('span', Yii::$app->formatter->asDate($m->next_test_due), [
                    'class' => $m->isTestOverdue($today) ? 'gw-text-danger' : ($m->isTestDueSoon($today) ? 'gw-text-warning' : null),
                ])
                : null,
        ],
        ['class' => ActionColumn::class],
    ],
]) ?>
