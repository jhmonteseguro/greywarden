<?php

namespace app\components;

use app\models\User;
use app\modules\action\models\Action;
use app\modules\control\models\Control;
use app\modules\incident\components\IncidentMetrics;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;
use Yii;
use yii\db\ActiveQuery;

/**
 * What a user has to do: their actions, and the reviews, tests and deadlines of the records they
 * own (risks, controls, suppliers) or are assigned (incidents). Shown on the My work page and
 * sent in the weekly digest.
 */
final class MyWork
{
    /** Items listed per group; the group links to the full list */
    public const LIMIT = 10;

    /**
     * Groups with something in them, most urgent first.
     *
     * @return list<array{key:string, label:string, icon:string, urgent:bool, count:int, items:array, url:array, meta:callable}>
     */
    public static function groups(User $user, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $id = (int) $user->id;
        $fmt = Yii::$app->formatter;
        $due = static fn(?string $date, string $prefix = 'Due') => $date ? $prefix . ' ' . $fmt->asDate($date) : 'No due date';

        $definitions = [
            [
                'key' => 'actions-overdue', 'label' => 'Overdue actions', 'icon' => 'exclamation-octagon', 'urgent' => true,
                'query' => Action::find()->active()->ownedBy($id)->overdue($today)->byUrgency(),
                'url' => ['/action/action/index', 'filter' => 'overdue', 'owner_id' => $id],
                'meta' => static fn(Action $a) => $due($a->due_date) . ' · ' . $a->getPriorityLabel(),
            ],
            [
                'key' => 'incidents', 'label' => 'Open incidents assigned to you', 'icon' => 'fire', 'urgent' => true,
                'query' => Incident::find()->active()->open()->andWhere(['incident.assigned_to_id' => $id])
                    ->orderBy([new \yii\db\Expression("FIELD(incident.severity, 'critical', 'high', 'medium', 'low')"), 'incident.detected_at' => SORT_ASC]),
                'url' => ['/incident/incident/index', 'filter' => 'mine'],
                'meta' => static fn(Incident $i) => ucfirst((string) $i->severity) . ' · ' . $i->getStatusLabel()
                    . ($i->getRegulatorDeadlineState() === IncidentMetrics::STATE_OVERDUE ? ' · regulator deadline passed'
                        : ($i->getRegulatorDeadlineState() === IncidentMetrics::STATE_PENDING ? ' · regulator notice due ' . $fmt->asRelativeTime($i->getRegulatorDeadline()?->getTimestamp()) : '')),
            ],
            [
                'key' => 'actions-due', 'label' => sprintf('Actions due in the next %d days', Action::DUE_SOON_DAYS), 'icon' => 'calendar-event', 'urgent' => false,
                'query' => Action::find()->active()->ownedBy($id)->dueSoon($today)->byUrgency(),
                'url' => ['/action/action/index', 'filter' => 'due-soon', 'owner_id' => $id],
                'meta' => static fn(Action $a) => $due($a->due_date) . ' · ' . $a->getPriorityLabel(),
            ],
            [
                'key' => 'risk-review', 'label' => 'Risk reviews overdue', 'icon' => 'calendar-x', 'urgent' => true,
                'query' => Risk::find()->active()->reviewOverdue($today)->andWhere(['risk.owner_id' => $id])->orderBy(['next_review_date' => SORT_ASC]),
                'url' => ['/risk/risk/index', 'filter' => 'review-overdue', 'owner_id' => $id],
                'meta' => static fn(Risk $r) => $due($r->next_review_date, 'Review was due'),
            ],
            [
                'key' => 'risk-treatment', 'label' => 'Risk treatments overdue', 'icon' => 'tools', 'urgent' => true,
                'query' => Risk::find()->active()->treatmentOverdue($today)->andWhere(['risk.owner_id' => $id])->orderBy(['target_treatment_date' => SORT_ASC]),
                'url' => ['/risk/risk/index', 'filter' => 'treatment-overdue', 'owner_id' => $id],
                'meta' => static fn(Risk $r) => $due($r->target_treatment_date, 'Target was'),
            ],
            [
                'key' => 'control-tests', 'label' => 'Control tests overdue', 'icon' => 'clipboard-x', 'urgent' => true,
                'query' => Control::find()->active()->testOverdue($today)->andWhere(['control.owner_id' => $id])->orderBy(['next_test_due' => SORT_ASC]),
                'url' => ['/control/control/index', 'filter' => 'mine'],
                'meta' => static fn(Control $c) => $due($c->next_test_due, 'Test was due'),
            ],
            [
                'key' => 'control-tests-soon', 'label' => 'Control tests due soon', 'icon' => 'clipboard-check', 'urgent' => false,
                'query' => Control::find()->active()->testDueSoon($today)->andWhere(['control.owner_id' => $id])->orderBy(['next_test_due' => SORT_ASC]),
                'url' => ['/control/control/index', 'filter' => 'mine'],
                'meta' => static fn(Control $c) => $due($c->next_test_due, 'Test due'),
            ],
            [
                'key' => 'control-targets', 'label' => 'Planned controls past their target date', 'icon' => 'hourglass-bottom', 'urgent' => true,
                'query' => Control::find()->active()->targetOverdue($today)->andWhere(['control.owner_id' => $id])->orderBy(['target_date' => SORT_ASC]),
                'url' => ['/control/control/index', 'filter' => 'mine'],
                'meta' => static fn(Control $c) => $due($c->target_date, 'Target was'),
            ],
            [
                'key' => 'supplier-review', 'label' => 'Supplier reviews overdue', 'icon' => 'buildings', 'urgent' => true,
                'query' => Supplier::find()->active()->reviewOverdue($today)->andWhere(['supplier.owner_id' => $id])->orderBy(['next_review_date' => SORT_ASC]),
                'url' => ['/supplier/supplier/index', 'filter' => 'review-overdue', 'owner_id' => $id],
                'meta' => static fn(Supplier $s) => $due($s->next_review_date, 'Review was due'),
            ],
        ];

        $groups = [];
        foreach ($definitions as $definition) {
            /** @var ActiveQuery $query */
            $query = $definition['query'];
            $count = (int) (clone $query)->count();
            if ($count === 0) {
                continue;
            }
            unset($definition['query']);
            $groups[] = $definition + ['count' => $count, 'items' => $query->limit(self::LIMIT)->all()];
        }
        return $groups;
    }

    /** Overdue items only (the sidebar badge and the digest subject) */
    public static function urgentCount(array $groups): int
    {
        return array_sum(array_map(static fn(array $g) => $g['urgent'] ? $g['count'] : 0, $groups));
    }

    /** Open actions owned by the user, not in the groups above (no date, or due later) */
    public static function laterActions(User $user, ?string $today = null): int
    {
        $today ??= date('Y-m-d');
        return (int) Action::find()->active()->ownedBy((int) $user->id)->open()
            ->andWhere(['or', ['action.due_date' => null], ['>', 'action.due_date', Action::dueSoonDate($today)]])->count();
    }
}
