<?php

namespace app\modules\action\models;

use app\models\DomainQuery;
use app\models\DomainRecord;
use yii\db\Expression;

/** @extends DomainQuery<Action> */
class ActionQuery extends DomainQuery
{
    public function open(): static
    {
        return $this->andWhere([$this->col('status') => Action::OPEN_STATUSES]);
    }

    public function closed(): static
    {
        return $this->andWhere(['not', [$this->col('status') => Action::OPEN_STATUSES]]);
    }

    public function overdue(?string $today = null): static
    {
        return $this->open()->andWhere(['<', $this->col('due_date'), $today ?? date('Y-m-d')]);
    }

    /** Open, due today or within Action::DUE_SOON_DAYS days, not overdue */
    public function dueSoon(?string $today = null): static
    {
        $today ??= date('Y-m-d');
        return $this->open()->andWhere(['between', $this->col('due_date'), $today, Action::dueSoonDate($today)]);
    }

    public function ownedBy(?int $userId): static
    {
        return $this->andWhere([$this->col('owner_id') => $userId ?? 0]);
    }

    public function forRecord(DomainRecord $record): static
    {
        return $this->andWhere([$this->col('record_type') => $record::entityType(), $this->col('record_id') => $record->id]);
    }

    /** Overdue first, then by due date (none last), then priority */
    public function byUrgency(): static
    {
        $due = $this->col('due_date');
        return $this->orderBy([
            new Expression("$due IS NULL"),
            $due => SORT_ASC,
            new Expression("FIELD({$this->col('priority')}, 'critical', 'high', 'medium', 'low')"),
            $this->col('id') => SORT_ASC,
        ]);
    }
}
