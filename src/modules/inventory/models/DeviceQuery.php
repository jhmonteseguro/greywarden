<?php

namespace app\modules\inventory\models;

use app\models\DomainQuery;
use app\modules\inventory\components\DeviceCheck;
use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DevicePosture;
use yii\db\Expression;
use yii\db\Query;

class DeviceQuery extends DomainQuery
{
    public function inService(): static
    {
        return $this->andWhere(['device.status' => Device::STATUS_ACTIVE]);
    }

    // ---- Posture: SQL mirror of DevicePosture::evaluate() ------------------------

    /** Every applicable check passes */
    public function compliantCondition(?string $today = null): array|string
    {
        $cond = ['and'];
        foreach (DeviceChecks::all() as $check) {
            $applies = $this->appliesCondition($check);
            $passes = $this->resultCondition($check, DevicePosture::RESULT_PASS, $today);
            $cond[] = $applies ? ['or', ['not', $applies], $passes] : $passes;
        }
        return count($cond) > 1 ? $cond : '1=1';
    }

    /** Some applicable check fails */
    public function nonCompliantCondition(?string $today = null): array|string
    {
        $cond = ['or'];
        foreach (DeviceChecks::all() as $check) {
            $cond[] = $this->checkCondition($check, DevicePosture::RESULT_FAIL, $today);
        }
        return count($cond) > 1 ? $cond : '0=1';
    }

    public function compliant(?string $today = null): static
    {
        return $this->andWhere($this->compliantCondition($today));
    }

    public function nonCompliant(?string $today = null): static
    {
        return $this->andWhere($this->nonCompliantCondition($today));
    }

    /** No check fails, but at least one has no result */
    public function notAssessed(?string $today = null): static
    {
        return $this->andWhere(['not', $this->nonCompliantCondition($today)])
            ->andWhere(['not', $this->compliantCondition($today)]);
    }

    /** Devices the check applies to whose result is pass | fail | unknown */
    public function checkResult(DeviceCheck $check, string $result, ?string $today = null): static
    {
        return $this->andWhere($this->checkCondition($check, $result, $today));
    }

    private function checkCondition(DeviceCheck $check, string $result, ?string $today): array
    {
        $applies = $this->appliesCondition($check);
        $is = $this->resultCondition($check, $result, $today);
        return $applies ? ['and', $applies, $is] : $is;
    }

    /** Device type / ownership scope of a check ([] = every device) */
    private function appliesCondition(DeviceCheck $check): array
    {
        $cond = ['and'];
        if ($check->types !== null) {
            $cond[] = ['device.device_type' => $check->types];
        }
        if ($check->ownership !== 'all') {
            $cond[] = ['device.ownership' => $check->ownership];
        }
        return count($cond) > 1 ? $cond : [];
    }

    private function resultCondition(DeviceCheck $check, string $result, ?string $today): array
    {
        if ($check->isPatchAge()) {
            // NULL-safe: a comparison with a missing date is NULL, and NOT (NULL) would drop the row from notAssessed()
            $cutoff = DevicePosture::patchCutoff($check->maxAgeDays, $today);
            $known = ['not', ['device.last_patched_at' => null]];
            return match ($result) {
                DevicePosture::RESULT_PASS => ['and', $known, ['>=', 'device.last_patched_at', $cutoff]],
                DevicePosture::RESULT_FAIL => ['and', $known, ['<', 'device.last_patched_at', $cutoff]],
                default => ['device.last_patched_at' => null],
            };
        }
        $recorded = (new Query())->select(new Expression('1'))->from(['dc' => 'device_control'])
            ->where('dc.device_id = device.id')->andWhere(['dc.control_id' => $check->id]);
        return $result === DevicePosture::RESULT_UNKNOWN
            ? ['not exists', $recorded]
            : ['exists', $recorded->andWhere(['dc.result' => $result])];
    }

    // ---- Lifecycle ------------------------------------------------------------

    public function warrantyExpiring(?string $today = null): static
    {
        $today ??= date('Y-m-d');
        $limit = date('Y-m-d', strtotime($today . ' +' . Device::warrantyWarningDays() . ' days'));
        return $this->andWhere(['between', 'device.warranty_expiry', $today, $limit]);
    }

    /** Synced devices whose last check-in is older than Device::staleDays() (mirror of Device::isStale()) */
    public function stale(?string $today = null): static
    {
        return $this->andWhere(['<', 'device.last_seen_at', DevicePosture::patchCutoff(Device::staleDays(), $today)]);
    }

    public function pastEol(?string $today = null): static
    {
        return $this->andWhere(['<', 'device.eol_date', $today ?? date('Y-m-d')]);
    }
}
