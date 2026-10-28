<?php
/**
 * @var yii\web\View $this
 * @var app\modules\incident\models\IncidentSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\Lookup;
use app\models\User;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\incident\models\Incident;
use app\modules\incident\models\IncidentSearch;
use app\widgets\grid\ActionColumn;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;

$this->title = 'Incident Log';
$this->params['breadcrumbs'][] = $this->title;
$this->params['actions'] = Rbac::canManage() ? Html::a('<i class="bi bi-plus-lg"></i> Log incident', ['create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']) : '';
?>
<?= QuickFilters::widget([
    'filters' => IncidentSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'placeholder' => 'Search reference, title…',
]) ?>
<?php if ($searchModel->month): ?>
    <p class="gw-active-filter">Detected in <strong><?= Html::encode(date('F Y', strtotime($searchModel->month . '-01'))) ?></strong>
        <?= Html::a('clear', \yii\helpers\Url::current(['month' => null])) ?></p>
<?php endif; ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'filterModel' => $searchModel,
    'columns' => [
        ['class' => ReferenceColumn::class, 'attribute' => 'reference'],
        [
            'attribute' => 'title',
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-col-title'],
            'value' => static function (Incident $m) {
                $flags = '';
                $state = $m->getRegulatorDeadlineState();
                if ($state === 'overdue') {
                    $flags .= Badge::flag('overdue', 'Regulator deadline overdue');
                } elseif ($state === 'pending') {
                    $flags .= Badge::flag('pending', 'Regulator notice due');
                }
                return Html::a(Html::encode($m->title), $m->getViewUrl(), ['class' => 'gw-title-link']) . ($flags ? '<div class="gw-flags">' . $flags . '</div>' : '');
            },
        ],
        [
            'attribute' => 'severity',
            'format' => 'raw',
            'value' => static fn(Incident $m) => Badge::severity($m->severity),
            'filter' => Incident::severityOptions(),
        ],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'value' => static fn(Incident $m) => Badge::status('incident-status', $m->status, Incident::statusOptions()),
            'filter' => Incident::statusOptions(),
        ],
        ['attribute' => 'category_id', 'value' => 'category.name', 'filter' => Lookup::allOptions(Lookup::TYPE_INCIDENT_CATEGORY)],
        [
            'attribute' => 'technique',
            'format' => 'raw',
            'enableSorting' => false,
            'contentOptions' => ['class' => 'gw-col-attack'],
            'filterInputOptions' => ['placeholder' => 'T1566…'],
            'value' => static fn(Incident $m) => TechniqueTags::widget(['items' => $m->techniques, 'compact' => true, 'max' => 2]),
        ],
        ['attribute' => 'assigned_to_id', 'value' => 'assignedTo.full_name', 'filter' => User::options()],
        [
            'attribute' => 'impact',
            'format' => 'raw',
            'enableSorting' => false,
            'value' => static fn(Incident $m) => Badge::impacts($m->getImpacts(), true),
            'filter' => IncidentSearch::impactOptions(),
        ],
        [
            'attribute' => 'personal_data_involved',
            'format' => 'raw',
            'value' => static fn(Incident $m) => $m->personal_data_involved ? Badge::render('flag', 'pending', 'PII') : '',
            'filter' => [1 => 'Yes', 0 => 'No'],
        ],
        ['attribute' => 'detected_at', 'format' => 'datetime', 'filter' => false],
        ['class' => ActionColumn::class],
    ],
]) ?>
