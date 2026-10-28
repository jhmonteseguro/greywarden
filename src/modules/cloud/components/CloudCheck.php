<?php

namespace app\modules\cloud\components;

/**
 * A security check applicable cloud assets and / or cloud accounts must pass:
 * a cloud-check control from the Control Register (see CloudChecks::all()).
 */
final class CloudCheck
{
    public const MANUAL = 'manual';
    public const OS_SUPPORT = 'os_support';
    public const ENCRYPTION = 'encryption';

    /**
     * @param string[]|null $types resource types it applies to; null = every type, [] = none (accounts only)
     * @param bool $accounts also (only, when $types is []) checked on cloud accounts
     * @param string[] $mitigations ATT&CK mitigation ids the control implements
     */
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly string $title,
        public readonly string $method,
        public readonly ?array $types = null,
        public readonly bool $accounts = false,
        public readonly array $mitigations = [],
    ) {
    }

    public function appliesToAsset(?string $resourceType): bool
    {
        if ($this->isOsSupport() && !CloudCatalogue::hasOs($resourceType)) {
            return false;
        }
        return $this->types === null || in_array($resourceType, $this->types, true);
    }

    /** Only manual checks are recorded on accounts; the other methods read asset fields */
    public function appliesToAccounts(): bool
    {
        return $this->accounts && $this->isManual();
    }

    /** @return string[]|null the resource types the check can apply to (null = every type) */
    public function assetTypes(): ?array
    {
        if ($this->isOsSupport()) {
            return array_values(array_intersect(CloudCatalogue::osTypes(), $this->types ?? CloudCatalogue::osTypes()));
        }
        return $this->types;
    }

    public function isManual(): bool
    {
        return $this->method === self::MANUAL;
    }

    public function isOsSupport(): bool
    {
        return $this->method === self::OS_SUPPORT;
    }

    public function isEncryption(): bool
    {
        return $this->method === self::ENCRYPTION;
    }

    /** Short label for charts */
    public function shortLabel(): string
    {
        return match ($this->method) {
            self::OS_SUPPORT => 'Supported OS (cloud)',
            self::ENCRYPTION => 'Encrypted at rest',
            default => $this->title,
        };
    }
}
