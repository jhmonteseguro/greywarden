<?php
/**
 * @var yii\web\View $this
 * @var app\modules\risk\models\RiskSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\helpers\RiskMatrix;
use app\models\Lookup;
use app\models\User;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\risk\models\Risk;
use app\modules\risk\models\RiskSearch;
use app\widgets\grid\ActionColumn;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;

$this->title = 'Risk Register';
$this->params['breadcrumbs'][] = $this->title;
$this->params['actions'] = Html::a('<i class="bi bi-grid-3x3"></i> Heatmap', ['heatmap'], ['class' => 'btn btn-outline-secondary gw-btn'])
    . (Rbac::canManage() ? ' ' . Html::a('<i class="bi bi-plus-lg"></i> New risk', ['create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']) : '');
$today = date('Y-m-d');
?>
<?= QuickFilters::widget([
    'filters' => RiskSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'placeholder' => 'Search reference, title, scope…',
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
            'value' => static function (Risk $m) use ($today) {
                $flags = '';
                if ($m->isReviewOverdue($today)) {
                    $flags .= ' ' . Badge::flag('overdue', 'Review overdue');
                }
                if ($m->isTreatmentOverdue($today)) {
                    $flags .= ' ' . Badge::flag('overdue', 'Treatment overdue');
                }
                if ($issues = $m->getControlIssues($today)) {
                    $flags .= ' ' . Badge::flag('weak-control', 'Control issue', implode('; ', array_map(
                        static fn($i) => $i['control']->reference . ': ' . implode(', ', $i['reasons']), $issues)));
                }
                return Html::a(Html::encode($m->title), $m->getViewUrl(), ['class' => 'gw-title-link']) . ($flags ? '<div class="gw-flags">' . $flags . '</div>' : '');
            },
        ],
        [
            'attribute' => 'category_id',
            'value' => 'category.name',
            'filter' => Lookup::allOptions(Lookup::TYPE_RISK_CATEGORY),
        ],
        [
            'attribute' => 'technique',
            'format' => 'raw',
            'enableSorting' => false,
            'contentOptions' => ['class' => 'gw-col-attack'],
            'filterInputOptions' => ['placeholder' => 'T1486…'],
            'value' => static fn(Risk $m) => TechniqueTags::widget(['items' => $m->techniques, 'compact' => true, 'max' => 2]),
        ],
        [
            'attribute' => 'owner_id',
            'value' => 'owner.full_name',
            'filter' => User::options(),
        ],
        [
            'attribute' => 'inherent_rating',
            'format' => 'raw',
            'value' => static fn(Risk $m) => Badge::score($m->inherent_score),
            'filter' => RiskMatrix::ratingOptions(),
        ],
        [
            'attribute' => 'residual_rating',
            'format' => 'raw',
            'value' => static fn(Risk $m) => Badge::score($m->residual_score)
                . ($m->target_score ? Html::tag('span', 'Target ' . (int) $m->target_score . ' · ' . RiskMatrix::ratingLabel($m->getTargetRating()), ['class' => 'gw-score-target']) : ''),
            'filter' => RiskMatrix::ratingOptions(),
        ],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'value' => static fn(Risk $m) => Badge::status('risk-status', $m->status, Risk::statusOptions()),
            'filter' => Risk::statusOptions(),
        ],
        [
            'attribute' => 'treatment',
            'value' => static fn(Risk $m) => $m->getTreatmentLabel(),
            'filter' => Risk::treatmentOptions(),
        ],
        [
            'attribute' => 'next_review_date',
            'format' => 'date',
            'filter' => false,
        ],
        ['class' => ActionColumn::class],
    ],
]) ?>
