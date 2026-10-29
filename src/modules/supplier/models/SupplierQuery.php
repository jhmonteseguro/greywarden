<?php

namespace app\modules\supplier\models;

use app\models\DomainQuery;
use app\modules\supplier\components\SupplierCheck;
use app\modules\supplier\components\SupplierChecks;
use app\modules\supplier\components\SupplierPosture;
use DateTimeImmutable;
use yii\db\Expression;
use yii\db\Query;

class SupplierQuery extends DomainQuery
{
    public function inService(): static
    {
        return $this->andWhere(['supplier.status' => Supplier::IN_SERVICE_STATUSES]);
    }

    public function onboarding(): static
    {
        return $this->andWhere(['supplier.status' => Supplier::ONBOARDING_STATUSES]);
    }

    /** In service with the next review date passed (mirror of Supplier::isReviewOverdue()) */
    public function reviewOverdue(?string $today = null): static
    {
        return $this->inService()->andWhere(['<', 'supplier.next_review_date', $today ?? date('Y-m-d')]);
    }

    /** In service with a notice deadline inside the warning window (mirror of Supplier::isRenewalDue()) */
    public function renewalDue(?string $today = null): static
    {
        $today ??= date('Y-m-d');
        $cutoff = (new DateTimeImmutable($today))->modify('+' . Supplier::renewalWarningDays() . ' days')->format('Y-m-d');
        return $this->inService()->andWhere(['>=', 'supplier.contract_end', $today])
            ->andWhere(['<=', new Expression('DATE_SUB(supplier.contract_end, INTERVAL COALESCE(supplier.notice_days, 0) DAY)'), $cutoff]);
    }

    /**
     * Some assurance type whose longest-lasting entry has expired or expires
     * within the warning window (mirror of Supplier::getAssuranceDue()).
     */
    public function assuranceDue(?string $today = null): static
    {
        $today ??= date('Y-m-d');
        $cutoff = (new DateTimeImmutable($today))->modify('+' . SupplierAssurance::expiryWarningDays() . ' days')->format('Y-m-d');
        $later = (new Query())->select(new Expression('1'))->from(['b' => 'supplier_assurance'])
            ->where('b.supplier_id = a.supplier_id AND b.type = a.type AND b.id <> a.id')
            ->andWhere(['or', ['b.expires_on' => null], new Expression('b.expires_on > a.expires_on')]);
        return $this->andWhere(['exists', (new Query())->select(new Expression('1'))->from(['a' => 'supplier_assurance'])
            ->where('a.supplier_id = supplier.id')->andWhere(['<=', 'a.expires_on', $cutoff])->andWhere(['not exists', $later])]);
    }

    // ---- Posture: SQL mirror of SupplierPosture::evaluate() -------------------

    /** Every check that applies passes */
    public function compliant(?string $today = null): static
    {
        $cond = ['and'];
        foreach (SupplierChecks::all() as $check) {
            $cond[] = ['or', ['not', $this->appliesCondition($check)], $this->resultCondition($check, SupplierPosture::RESULT_PASS, $today)];
        }
        return $this->andWhere(count($cond) > 1 ? $cond : '1=1');
    }

    /** Some check that applies fails */
    public function nonCompliant(?string $today = null): static
    {
        return $this->andWhere($this->nonCompliantCondition($today));
    }

    /** No check fails, but at least one that applies has no result */
    public function notAssessed(?string $today = null): static
    {
        $cond = ['or'];
        foreach (SupplierChecks::all() as $check) {
            $cond[] = ['and', $this->appliesCondition($check), $this->resultCondition($check, SupplierPosture::RESULT_UNKNOWN, $today)];
        }
        return $this->andWhere(['not', $this->nonCompliantCondition($today)])->andWhere(count($cond) > 1 ? $cond : '0=1');
    }

    /** Suppliers the check applies to whose result is pass | fail | unknown */
    public function checkResult(SupplierCheck $check, string $result, ?string $today = null): static
    {
        return $this->andWhere(['and', $this->appliesCondition($check), $this->resultCondition($check, $result, $today)]);
    }

    private function nonCompliantCondition(?string $today): array|string
    {
        $cond = ['or'];
        foreach (SupplierChecks::all() as $check) {
            $cond[] = ['and', $this->appliesCondition($check), $this->resultCondition($check, SupplierPosture::RESULT_FAIL, $today)];
        }
        return count($cond) > 1 ? $cond : '0=1';
    }

    private function appliesCondition(SupplierCheck $check): array
    {
        $cond = ['and', $check->tiers === null ? '1=1' : ['supplier.tier' => $check->tiers]];
        if ($check->isDpa()) {
            $cond[] = ['supplier.personal_data' => 1];
        }
        return $cond;
    }

    private function resultCondition(SupplierCheck $check, string $result, ?string $today): array
    {
        if ($check->isDpa()) {
            // COALESCE: a NULL (unknown) answer must not turn the NOT in notAssessed() into NULL
            $dpa = new Expression("COALESCE(supplier.dpa_status, '')");
            return match ($result) {
                SupplierPosture::RESULT_PASS => ['=', $dpa, 'signed'],
                SupplierPosture::RESULT_FAIL => ['=', $dpa, 'not_signed'],
                default => ['not in', $dpa, ['signed', 'not_signed']],
            };
        }
        if ($check->isAssurance()) {
            $any = (new Query())->select(new Expression('1'))->from(['a' => 'supplier_assurance'])->where('a.supplier_id = supplier.id');
            $current = (clone $any)->andWhere(['or', ['a.expires_on' => null], ['>=', 'a.expires_on', $today ?? date('Y-m-d')]]);
            return match ($result) {
                SupplierPosture::RESULT_PASS => ['exists', $current],
                SupplierPosture::RESULT_FAIL => ['and', ['exists', $any], ['not exists', $current]],
                default => ['not exists', $any],
            };
        }
        $recorded = (new Query())->select(new Expression('1'))->from(['r' => 'supplier_control'])
            ->where('r.supplier_id = supplier.id')->andWhere(['r.control_id' => $check->id]);
        return $result === SupplierPosture::RESULT_UNKNOWN
            ? ['not exists', $recorded]
            : ['exists', $recorded->andWhere(['r.result' => $result])];
    }
}
