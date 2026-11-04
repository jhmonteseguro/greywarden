<?php

namespace app\modules\threat\models;

use app\models\DomainQuery;
use app\models\Organisation;

class ThreatTriageQuery extends DomainQuery
{
    /** Only this organisation's rows; every read of triage goes through here */
    public function forOrganisation(?int $id = null): static
    {
        return $this->andWhere(['threat_triage.organisation_id' => $id ?? Organisation::currentId()]);
    }

    /** Leaves out background advisories */
    public function visible(): static
    {
        return $this->andWhere(['threat_triage.is_hidden' => 0]);
    }

    /** Still to triage and not background */
    public function open(): static
    {
        return $this->visible()->andWhere(['threat_triage.status' => ThreatTriage::STATUS_NEW]);
    }

    /** Critical first, then the score, then the newest */
    public function urgentFirst(): static
    {
        return $this->joinWith('story')->orderBy([
            new \yii\db\Expression("FIELD(threat_triage.priority, 'critical','high','medium','low')"),
            'threat_triage.score' => SORT_DESC,
            'threat_story.last_seen_at' => SORT_DESC,
        ]);
    }
}
