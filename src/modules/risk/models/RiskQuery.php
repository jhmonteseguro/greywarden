<?php

namespace app\modules\risk\models;

use app\models\DomainQuery;
use app\modules\control\models\ControlQuery;
use yii\db\Expression;
use yii\db\Query;

class RiskQuery extends DomainQuery
{
    public function open(): static
    {
        return $this->andWhere(['<>', 'risk.status', Risk::STATUS_CLOSED]);
    }

    public function reviewOverdue(?string $today = null): static
    {
        return $this->open()->andWhere(['<', 'risk.next_review_date', $today ?? date('Y-m-d')]);
    }

    /** Open risks relying on a control that is retired, archived, not effective or overdue (mirror of Risk::getControlIssues()) */
    public function controlIssues(?string $today = null): static
    {
        return $this->open()->andWhere(['exists', (new Query())->select(new Expression('1'))->from(['rc' => 'risk_control'])
            ->innerJoin(['c' => 'control'], 'c.id = rc.control_id')
            ->where('rc.risk_id = risk.id')->andWhere(ControlQuery::weakCondition('c', $today))]);
    }

    public function treatmentOverdue(?string $today = null): static
    {
        return $this->andWhere(['risk.status' => Risk::TREATMENT_PENDING_STATUSES])
            ->andWhere(['<', 'risk.target_treatment_date', $today ?? date('Y-m-d')]);
    }
}
