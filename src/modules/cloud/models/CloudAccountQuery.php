<?php

namespace app\modules\cloud\models;

use app\models\DomainQuery;
use app\modules\cloud\components\CloudCheck;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\components\CloudPosture;
use yii\db\Expression;
use yii\db\Query;

class CloudAccountQuery extends DomainQuery
{
    public function inService(): static
    {
        return $this->andWhere(['cloud_account.status' => CloudAccount::IN_SERVICE_STATUSES]);
    }

    // ---- Posture: SQL mirror of CloudPosture::evaluateAccount() -------------------

    /** Every account check passes */
    public function compliant(): static
    {
        $cond = ['and'];
        foreach (CloudChecks::forAccounts() as $check) {
            $cond[] = $this->resultCondition($check, CloudPosture::RESULT_PASS);
        }
        return $this->andWhere(count($cond) > 1 ? $cond : '1=1');
    }

    /** Some account check fails */
    public function nonCompliant(): static
    {
        return $this->andWhere($this->nonCompliantCondition());
    }

    /** No check fails, but at least one has no result */
    public function notAssessed(): static
    {
        $cond = ['or'];
        foreach (CloudChecks::forAccounts() as $check) {
            $cond[] = $this->resultCondition($check, CloudPosture::RESULT_UNKNOWN);
        }
        return $this->andWhere(['not', $this->nonCompliantCondition()])->andWhere(count($cond) > 1 ? $cond : '0=1');
    }

    /** Accounts whose result for an account check is pass | fail | unknown */
    public function checkResult(CloudCheck $check, string $result): static
    {
        return $this->andWhere($check->appliesToAccounts() ? $this->resultCondition($check, $result) : '0=1');
    }

    private function nonCompliantCondition(): array|string
    {
        $cond = ['or'];
        foreach (CloudChecks::forAccounts() as $check) {
            $cond[] = $this->resultCondition($check, CloudPosture::RESULT_FAIL);
        }
        return count($cond) > 1 ? $cond : '0=1';
    }

    private function resultCondition(CloudCheck $check, string $result): array
    {
        $recorded = (new Query())->select(new Expression('1'))->from(['r' => 'cloud_account_control'])
            ->where('r.cloud_account_id = cloud_account.id')->andWhere(['r.control_id' => $check->id]);
        return $result === CloudPosture::RESULT_UNKNOWN
            ? ['not exists', $recorded]
            : ['exists', $recorded->andWhere(['r.result' => $result])];
    }
}
