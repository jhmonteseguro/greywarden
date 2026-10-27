<?php

namespace app\modules\inventory\components;

use app\behaviors\AuditBehavior;
use app\modules\inventory\models\Device;

/**
 * Creates or updates devices from parsed export records (see GoogleDeviceCsv).
 *
 * Devices are matched on source + source device ID, archived ones included,
 * so importing a newer export refreshes the same records. An update only
 * touches what the source owns: sync times, OS and user details are
 * refreshed, and hardware details fill fields that are still empty. An
 * analyst's edits to the name, type, status, classification, posture or
 * notes are never overwritten, and an empty source value never clears a field.
 */
final class DeviceImporter
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    public const FAILED = 'failed';

    /** Refreshed from the source on every import (when the source has a value) */
    private const SYNCED = ['os', 'os_version', 'assigned_to', 'assigned_email', 'first_seen_at', 'last_seen_at'];

    /** Taken from the source only while still empty */
    private const FILL = ['hostname', 'serial_number', 'manufacturer', 'model'];

    /**
     * @param list<array{line: int, attributes: array<string, mixed>, notes: string[]}> $records
     * @param string $importNote first line of the notes on created devices
     * @return list<array{line: int, action: string, device: Device, changed: string[], errors: string[]}>
     */
    public function import(array $records, string $importNote): array
    {
        $results = [];
        foreach ($records as $record) {
            $results[] = ['line' => $record['line']] + $this->importOne($record['attributes'], $record['notes'], $importNote);
        }
        return $results;
    }

    /** @return array{action: string, device: Device, changed: string[], errors: string[]} */
    private function importOne(array $attributes, array $notes, string $importNote): array
    {
        $device = Device::findOne(['source' => $attributes['source'], 'source_id' => $attributes['source_id']]);
        if ($device === null) {
            $device = new Device($attributes + [
                'criticality' => 'medium',
                'data_classification' => 'internal',
            ]);
            $device->notes = implode("\n", array_merge([$importNote], $notes));
            $saved = $device->save();
            return ['action' => $saved ? self::CREATED : self::FAILED, 'device' => $device, 'changed' => [], 'errors' => $saved ? [] : $device->getErrorSummary(true)];
        }

        foreach (self::SYNCED as $attr) {
            if (($attributes[$attr] ?? null) !== null && $attributes[$attr] !== '') {
                $device->$attr = $attributes[$attr];
            }
        }
        foreach (self::FILL as $attr) {
            if (($device->$attr === null || $device->$attr === '') && ($attributes[$attr] ?? null) !== null) {
                $device->$attr = $attributes[$attr];
            }
        }
        if (!$device->validate()) {
            return ['action' => self::FAILED, 'device' => $device, 'changed' => [], 'errors' => $device->getErrorSummary(true)];
        }
        // Compare after validation, which normalises values (lower-cased email; trim turns NULL into '')
        $changed = array_keys(array_filter($device->getDirtyAttributes(),
            static fn($value, $attr) => !AuditBehavior::same($device->getOldAttribute($attr), $value), ARRAY_FILTER_USE_BOTH));
        if (!$changed) {
            return ['action' => self::UNCHANGED, 'device' => $device, 'changed' => [], 'errors' => []];
        }
        $device->save(false);
        return ['action' => self::UPDATED, 'device' => $device, 'changed' => $changed, 'errors' => []];
    }
}
