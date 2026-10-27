<?php

namespace app\modules\incident\models;

use app\models\DomainQuery;

class IncidentQuery extends DomainQuery
{
    public function open(): static
    {
        return $this->andWhere(['<>', 'incident.status', Incident::STATUS_CLOSED]);
    }

    /** Regulator notification required, not notified and the deadline has passed. */
    public function regulatorOverdue(?string $now = null): static
    {
        $cutoff = date('Y-m-d H:i:s', strtotime($now ?? 'now') - Incident::deadlineHours() * 3600);
        return $this->andWhere(['incident.regulator_notification_required' => 1, 'incident.regulator_notified_at' => null])
            ->andWhere(['<=', 'incident.detected_at', $cutoff]);
    }

    /** Regulator notification required and not notified yet (pending or overdue). */
    public function regulatorPending(): static
    {
        return $this->andWhere(['incident.regulator_notification_required' => 1, 'incident.regulator_notified_at' => null]);
    }
}
