<?php
/**
 * @var yii\web\View $this
 * @var app\modules\action\models\ActionSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\User;
use app\modules\action\models\Action;
use app\modules\action\models\ActionSearch;
use app\widgets\grid\ActionColumn;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;

$this->title = 'Actions';
$this->params['breadcrumbs'][] = $this->title;
$this->params['actions'] = Rbac::canManage() ? Html::a('<i class="bi bi-plus-lg"></i> New action', ['create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']) : '';
$fmt = Yii::$app->formatter;
?>
<?= QuickFilters::widget([
    'filters' => ActionSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'placeholder' => 'Search reference, action, outcome…',
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
            'value' => static function (Action $m): string {
                $record = $m->getRecord();
                return Html::a(Html::encode($m->title), $m->getViewUrl(), ['class' => 'gw-title-link'])
                    . ($record ? '<div class="gw-muted gw-small">' . Html::encode($record::entityLabel()) . ' '
                        . Html::a(Html::encode($record->getReferenceValue()), $record->getViewUrl(), ['class' => 'gw-ref']) . '</div>'
                        : ($m->isRecordDeleted() ? '<div class="gw-muted gw-small">' . Html::encode($m->getDeletedRecordLabel()) . '</div>' : ''));
            },
        ],
        [
            'attribute' => 'record_type',
            'label' => 'From',
            'value' => static fn(Action $m) => $m->record_type ? (Action::recordTypeOptions()[$m->record_type] ?? $m->record_type) : 'Standalone',
            'filter' => Action::recordTypeOptions() + ['none' => 'Standalone'],
        ],
        [
            'attribute' => 'priority',
            'format' => 'raw',
            'value' => static fn(Action $m) => Badge::status('action-priority', $m->priority, Action::priorityOptions()),
            'filter' => Action::priorityOptions(),
        ],
        [
            'attribute' => 'owner_id',
            'value' => static fn(Action $m) => $m->owner->full_name ?? '—',
            'filter' => User::options(),
        ],
        [
            'attribute' => 'due_date',
            'format' => 'raw',
            'filter' => false,
            'value' => static fn(Action $m) => $m->due_date ? $fmt->asDate($m->due_date)
                . ($m->isOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : ($m->isDueSoon() ? ' ' . Badge::flag('pending', 'Due soon') : '')) : '<span class="gw-null">—</span>',
        ],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'value' => static fn(Action $m) => Badge::status('action-status', $m->status, Action::statusOptions()),
            'filter' => Action::statusOptions(),
        ],
        ['class' => ActionColumn::class],
    ],
]) ?>
