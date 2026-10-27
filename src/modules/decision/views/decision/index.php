<?php
/**
 * @var yii\web\View $this
 * @var app\modules\decision\models\DecisionSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\Lookup;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\decision\models\Decision;
use app\modules\decision\models\DecisionSearch;
use app\widgets\grid\ActionColumn;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;

$this->title = 'Decision Log';
$this->params['breadcrumbs'][] = $this->title;
$this->params['actions'] = Rbac::canManage() ? Html::a('<i class="bi bi-plus-lg"></i> New decision', ['create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']) : '';
?>
<?= QuickFilters::widget([
    'filters' => DecisionSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'placeholder' => 'Search reference, title, decision…',
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
            'value' => static fn(Decision $m) => Html::a(Html::encode($m->title), $m->getViewUrl(), ['class' => 'gw-title-link'])
                . ($m->isReviewOverdue() ? '<div class="gw-flags">' . Badge::flag('overdue', 'Review overdue') . '</div>' : ''),
        ],
        ['attribute' => 'decision_date', 'format' => 'date', 'filterInputOptions' => ['type' => 'date', 'class' => 'form-control form-control-sm']],
        ['attribute' => 'category_id', 'value' => 'category.name', 'filter' => Lookup::allOptions(Lookup::TYPE_DECISION_CATEGORY)],
        [
            'attribute' => 'mitigation',
            'format' => 'raw',
            'enableSorting' => false,
            'contentOptions' => ['class' => 'gw-col-attack'],
            'filterInputOptions' => ['placeholder' => 'M1032…'],
            'value' => static fn(Decision $m) => TechniqueTags::widget(['items' => $m->mitigations, 'compact' => true, 'max' => 2]),
        ],
        'decision_makers',
        [
            'attribute' => 'status',
            'format' => 'raw',
            'value' => static fn(Decision $m) => Badge::status('decision-status', $m->status, Decision::statusOptions()),
            'filter' => Decision::statusOptions(),
        ],
        ['attribute' => 'review_date', 'format' => 'date', 'filter' => false],
        ['class' => ActionColumn::class],
    ],
]) ?>
