<?php

namespace app\widgets;

use app\components\Rbac;
use app\helpers\Badge;
use app\models\DomainRecord;
use app\modules\action\models\Action;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * "Actions" tab content on a record: the remediation actions raised from it (open ones first,
 * most urgent at the top) and, for analysts on a live record, a button to raise one.
 */
class ActionList extends Widget
{
    public DomainRecord $model;
    /** @var Action[]|null pre-loaded actions */
    public ?array $actions = null;

    /** @return Action[] open first by urgency, then closed newest first */
    public static function listFor(DomainRecord $model): array
    {
        $open = Action::find()->active()->forRecord($model)->open()->with('owner')->byUrgency()->all();
        $closed = Action::find()->active()->forRecord($model)->closed()->with('owner')->orderBy(['completed_at' => SORT_DESC, 'id' => SORT_DESC])->all();
        return array_merge($open, $closed);
    }

    /** Tab label: "Actions (2 open)" */
    public static function label(array $actions): string
    {
        $open = count(array_filter($actions, static fn(Action $a) => $a->isOpen()));
        return $open > 0 ? "Actions ($open open)" : 'Actions (' . count($actions) . ')';
    }

    public function run(): string
    {
        $actions = $this->actions ?? self::listFor($this->model);
        $fmt = Yii::$app->formatter;
        $html = '';
        if (Rbac::canManage() && !$this->model->getIsArchived()) {
            $html .= Html::tag('div', Html::a('<i class="bi bi-plus-lg"></i> Raise an action',
                ['/action/action/create', 'record_type' => $this->model::entityType(), 'record_id' => $this->model->id],
                ['class' => 'btn btn-sm btn-primary gw-btn']), ['class' => 'gw-actions-tab__bar']);
        }
        if (!$actions) {
            return Html::tag('section', $html . Html::tag('p', 'No actions raised from this record.', ['class' => 'gw-empty']), ['class' => 'gw-panel gw-actions-tab']);
        }
        $rows = '';
        foreach ($actions as $action) {
            $due = $action->due_date ? $fmt->asDate($action->due_date) : '—';
            $flag = $action->isOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : ($action->isDueSoon() ? ' ' . Badge::flag('pending', 'Due soon') : '');
            $rows .= '<tr>'
                . '<td>' . Html::a(Html::encode($action->reference), $action->getViewUrl(), ['class' => 'gw-ref']) . '</td>'
                . '<td>' . Html::a(Html::encode($action->title), $action->getViewUrl(), ['class' => 'gw-title-link']) . '</td>'
                . '<td>' . Html::encode($action->owner->full_name ?? '—') . '</td>'
                . '<td>' . ($action->isOpen() ? $due . $flag : $fmt->asDate($action->completed_at)) . '</td>'
                . '<td>' . Badge::status('action-priority', $action->priority, Action::priorityOptions()) . '</td>'
                . '<td>' . Badge::status('action-status', $action->status, Action::statusOptions()) . '</td>'
                . '</tr>';
        }
        $table = '<div class="table-responsive"><table class="table table-sm gw-table">'
            . '<thead><tr><th>Ref</th><th>Action</th><th>Owner</th><th>Due / closed</th><th>Priority</th><th>Status</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
        return Html::tag('section', $html . $table, ['class' => 'gw-panel gw-actions-tab']);
    }
}
