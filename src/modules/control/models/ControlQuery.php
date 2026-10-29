<?php

namespace app\modules\control\models;

use app\models\DomainQuery;

class ControlQuery extends DomainQuery
{
    /** Operating controls (implementing or implemented) */
    public function inForce(): static
    {
        return $this->andWhere(['control.status' => Control::IN_FORCE_STATUSES]);
    }

    public function implemented(): static
    {
        return $this->andWhere(['control.status' => Control::STATUS_IMPLEMENTED]);
    }

    /** Mirror of Control::isTestOverdue() */
    public function testOverdue(?string $today = null): static
    {
        return $this->implemented()->andWhere(['<', 'control.next_test_due', $today ?? date('Y-m-d')]);
    }

    /** Mirror of Control::isTestDueSoon() */
    public function testDueSoon(?string $today = null): static
    {
        $today ??= date('Y-m-d');
        return $this->implemented()->andWhere(['between', 'control.next_test_due', $today, Control::testDueSoonCutoff($today)]);
    }

    /** Mirror of Control::isTargetOverdue() */
    public function targetOverdue(?string $today = null): static
    {
        return $this->andWhere(['control.status' => Control::PENDING_STATUSES])
            ->andWhere(['<', 'control.target_date', $today ?? date('Y-m-d')]);
    }

    /** Implemented, but last tested partially effective or ineffective */
    public function weakResult(): static
    {
        return $this->implemented()->andWhere(['control.effectiveness' => Control::WEAK_RESULTS]);
    }

    /**
     * SQL mirror of Control::weaknesses() !== [] for a control table alias.
     */
    public static function weakCondition(string $alias = 'control', ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        return ['or',
            ['not', ["$alias.archived_at" => null]],
            ["$alias.status" => Control::STATUS_RETIRED],
            ["$alias.effectiveness" => Control::WEAK_RESULTS],
            ['and', ["$alias.status" => Control::STATUS_IMPLEMENTED], ['<', "$alias.next_test_due", $today]],
            ['and', ["$alias.status" => Control::PENDING_STATUSES], ['<', "$alias.target_date", $today]],
        ];
    }

    /** Controls checked on devices */
    public function deviceChecks(): static
    {
        return $this->andWhere(['not', ['control.device_check' => null]]);
    }

    /** Controls checked on suppliers */
    public function supplierChecks(): static
    {
        return $this->andWhere(['not', ['control.supplier_check' => null]]);
    }

    /** Controls checked on cloud assets / accounts */
    public function cloudChecks(): static
    {
        return $this->andWhere(['not', ['control.cloud_check' => null]]);
    }
}
