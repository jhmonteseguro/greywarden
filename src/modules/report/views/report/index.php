<?php
/**
 * @var yii\web\View $this
 * @var app\modules\report\models\ReportSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\report\components\ReportBuilder;
use app\modules\report\components\ReportPeriod;
use app\modules\report\models\Report;
use app\modules\report\models\ReportSearch;
use app\widgets\grid\ActionColumn;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;

$this->title = 'Management Reports';
$this->params['breadcrumbs'][] = $this->title;
$this->params['actions'] = Rbac::canManage() ? Html::a('<i class="bi bi-plus-lg"></i> New report', ['create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']) : '';
$fmt = Yii::$app->formatter;
?>
<p class="gw-muted gw-small">Security posture over a month, quarter or other period, for management. Each report's figures are frozen when it is generated, so last quarter's report reads the same next year.</p>
<?= QuickFilters::widget([
    'filters' => ReportSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'placeholder' => 'Search reference, title, summary…',
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
            'value' => static fn(Report $m) => Html::a(Html::encode($m->title), $m->getViewUrl(), ['class' => 'gw-title-link']),
        ],
        [
            'attribute' => 'period_start',
            'label' => 'Period',
            'filter' => false,
            'format' => 'raw',
            'value' => static fn(Report $m) => Html::encode($m->getPeriodName()) . '<div class="gw-muted gw-small">' . Html::encode(ReportPeriod::range($m->period_start, $m->period_end)) . '</div>',
        ],
        ['attribute' => 'period_type', 'value' => static fn(Report $m) => ReportPeriod::typeOptions()[$m->period_type] ?? $m->period_type, 'filter' => ReportPeriod::typeOptions()],
        [
            'attribute' => 'rag',
            'format' => 'raw',
            'value' => static fn(Report $m) => Badge::render('rag', $m->rag, $m->getRagLabel()),
            'filter' => ReportBuilder::ragOptions(),
        ],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'value' => static fn(Report $m) => Badge::status('report-status', $m->status, Report::statusOptions())
                . ($m->isApproved() ? '<div class="gw-muted gw-small">' . Html::encode(($m->approvedBy->full_name ?? '') . ' · ' . $fmt->asDate($m->approved_at)) . '</div>' : ''),
            'filter' => Report::statusOptions(),
        ],
        ['attribute' => 'generated_at', 'format' => 'datetime', 'filter' => false],
        ['class' => ActionColumn::class, 'visibleButtons' => ['update' => static fn(Report $m) => Rbac::canManage() && !$m->getIsArchived() && $m->isDraft()]],
    ],
]) ?>
