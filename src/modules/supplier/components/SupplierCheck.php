<?php

namespace app\modules\supplier\components;

/**
 * A check suppliers of some tiers must pass: a supplier-check control from the
 * Control Register (see SupplierChecks::all()).
 */
final class SupplierCheck
{
    /** Pass / fail recorded on each supplier */
    public const MANUAL = 'manual';
    /** From the supplier's DPA answer; only suppliers that process personal data */
    public const DPA = 'dpa';
    /** From the supplier's assurance: passes while some certificate / report is current */
    public const ASSURANCE = 'assurance';

    /**
     * @param int[]|null $tiers supplier tiers it applies to; null = every tier
     */
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly string $title,
        public readonly string $method,
        public readonly ?array $tiers = null,
    ) {
    }

    public function appliesTo(int $tier, bool $personalData): bool
    {
        if ($this->isDpa() && !$personalData) {
            return false;
        }
        return $this->tiers === null || in_array($tier, $this->tiers, true);
    }

    public function isManual(): bool
    {
        return $this->method === self::MANUAL;
    }

    public function isDpa(): bool
    {
        return $this->method === self::DPA;
    }

    public function isAssurance(): bool
    {
        return $this->method === self::ASSURANCE;
    }
}
