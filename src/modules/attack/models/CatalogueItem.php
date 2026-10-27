<?php

namespace app\modules\attack\models;

/**
 * Shared identity helpers for ATT&CK catalogue records, mirroring the
 * DomainRecord API (reference / title / view URL) so catalogue rows can be
 * used in link definitions, audit snapshots and search results.
 *
 * @property int $id
 * @property string $external_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_deprecated
 */
trait CatalogueItem
{
    public static function referenceAttribute(): string
    {
        return 'external_id';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    /** Path segment on attack.mitre.org, e.g. "techniques" */
    abstract protected static function mitrePath(): string;

    public static function findByExternalId(string $externalId): ?static
    {
        return static::findOne(['external_id' => strtoupper(trim($externalId))]);
    }

    public function getReferenceValue(): string
    {
        return (string) $this->external_id;
    }

    public function getTitleValue(): string
    {
        return (string) $this->name;
    }

    public function getIsArchived(): bool
    {
        return false;
    }

    /** Link to the official page on attack.mitre.org */
    public function getMitreUrl(): string
    {
        return 'https://attack.mitre.org/' . static::mitrePath() . '/' . str_replace('.', '/', (string) $this->external_id) . '/';
    }
}
