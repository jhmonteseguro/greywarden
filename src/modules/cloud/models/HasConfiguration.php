<?php

namespace app\modules\cloud\models;

use app\modules\cloud\components\CloudConfig;

/**
 * The imported configuration of a cloud asset or account (`properties`, JSON)
 * and the findings derived from it (`findings`, `finding_count`,
 * `finding_severity`), refreshed on every validation so imports, form saves
 * and the seed all keep them in step. Properties belong to the import: forms
 * never set them.
 *
 * @property string|null $properties
 * @property string|null $findings
 * @property int $finding_count
 * @property string|null $finding_severity
 */
trait HasConfiguration
{
    /** The provider whose rules read the properties */
    abstract protected function configProvider(): ?string;

    /** The provider's type the properties describe (CloudConfig::ACCOUNT_TYPE for an account) */
    abstract protected function configType(): ?string;

    /** The account identifier, to tell cross-account access */
    abstract protected function configAccount(): string;

    public function hasConfiguration(): bool
    {
        return $this->properties !== null && $this->properties !== '';
    }

    /** @return array<string,mixed> */
    public function getProperties(): array
    {
        $decoded = $this->hasConfiguration() ? json_decode((string) $this->properties, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array{severity:string, title:string}> worst first */
    public function getFindings(): array
    {
        $decoded = $this->findings ? json_decode((string) $this->findings, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array{0:string, 1:string}> [label, value] */
    public function getConfigFacts(): array
    {
        return CloudConfig::facts($this->configProvider(), $this->getProperties());
    }

    /** Recomputes the findings from the properties (none without properties) */
    public function refreshFindings(?string $today = null): void
    {
        $findings = $this->hasConfiguration()
            ? CloudConfig::findings($this->configProvider(), (string) $this->configType(), $this->getProperties(), $today, $this->configAccount())
            : [];
        $this->findings = $findings ? json_encode($findings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $this->finding_count = count($findings);
        $this->finding_severity = CloudConfig::worst($findings);
    }

    /** properties and findings are long JSON: the audit trail records the finding count and severity instead */
    protected function configAuditIgnored(): array
    {
        return ['created_at', 'updated_at', 'created_by', 'updated_by', 'properties', 'findings'];
    }
}
