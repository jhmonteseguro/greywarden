<?php

namespace app\modules\inventory\components;

/**
 * A security check every applicable device must pass: a device-check control
 * from the Control Register (see DeviceChecks::all()).
 */
final class DeviceCheck
{
    public const MANUAL = 'manual';
    public const PATCH_AGE = 'patch_age';
    public const OS_SUPPORT = 'os_support';

    /**
     * @param string[]|null $types device types it applies to; null = all
     * @param string $ownership 'all' | 'company' | 'byod'
     * @param string[] $mitigations ATT&CK mitigation ids the control implements
     */
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly string $title,
        public readonly string $method,
        public readonly ?array $types = null,
        public readonly string $ownership = 'all',
        public readonly int $maxAgeDays = 30,
        public readonly array $mitigations = [],
    ) {
    }

    public function appliesTo(?string $deviceType, ?string $ownership): bool
    {
        return ($this->types === null || in_array($deviceType, $this->types, true))
            && ($this->ownership === 'all' || $this->ownership === $ownership);
    }

    public function isPatchAge(): bool
    {
        return $this->method === self::PATCH_AGE;
    }

    public function isOsSupport(): bool
    {
        return $this->method === self::OS_SUPPORT;
    }

    /** Recorded per device (pass / fail); the other methods are worked out from the device's fields */
    public function isManual(): bool
    {
        return $this->method === self::MANUAL;
    }

    /** Short label for charts, e.g. "Patched ≤30d" */
    public function shortLabel(): string
    {
        return match ($this->method) {
            self::PATCH_AGE => sprintf('Patched ≤%dd', $this->maxAgeDays),
            self::OS_SUPPORT => 'Supported OS',
            default => $this->title,
        };
    }
}
