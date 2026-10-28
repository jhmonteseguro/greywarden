<?php

namespace app\modules\cloud\components;

use InvalidArgumentException;

/**
 * Azure Resource Graph exports: `az graph query -q "Resources | project id, name,
 * type, kind, location, subscriptionId, properties"` JSON ({"data": [...]}, or a
 * plain list from older CLI versions and --query data) or the Resource Graph
 * Explorer's "Download as CSV" of the same query.
 *
 * The subscription is read from the resource ID, so it is right even when the
 * portal's "Format results" shows subscription names. Resource IDs are
 * case-insensitive in Azure and their casing varies between APIs, so they are
 * matched in lower case. Public IP resources fill the address from
 * properties.ipAddress and virtual machines their OS from
 * properties.extended.instanceView (or the OS disk's type).
 */
final class AzureExport extends CloudExport
{
    public static function provider(): string
    {
        return 'azure';
    }

    protected static function formats(): string
    {
        return 'an Azure Resource Graph export';
    }

    protected function jsonItems(mixed $json): array
    {
        $list = match (true) {
            isset($json['data']) && is_array($json['data']) => $json['data'],
            isset($json['value']) && is_array($json['value']) => $json['value'],
            array_is_list($json) => $json,
            default => throw new InvalidArgumentException('Not ' . self::formats() . ': expected a "data" list (az graph query) or a list of resources.'),
        };
        $items = [];
        foreach (array_values($list) as $i => $r) {
            $r = is_array($r) ? $r : [];
            $items[] = ['line' => $i + 1, 'id' => self::pick($r, 'id'), 'name' => self::pick($r, 'name'), 'type' => self::pick($r, 'type'),
                'kind' => self::pick($r, 'kind'), 'location' => self::pick($r, 'location'), 'subscription' => self::pick($r, 'subscriptionId'),
                'properties' => self::object($r['properties'] ?? null)];
        }
        return $items;
    }

    protected function requiredColumns(): array
    {
        return [['id', 'resourceid']];
    }

    protected function csvItem(array $row): array
    {
        return ['id' => self::pick($row, 'id', 'resourceid'), 'name' => self::pick($row, 'name'), 'type' => self::pick($row, 'type'),
            'kind' => self::pick($row, 'kind'), 'location' => self::pick($row, 'location'),
            'subscription' => self::pick($row, 'subscriptionid', 'subscription'), 'properties' => self::object($row['properties'] ?? null)];
    }

    protected function record(array $item): array
    {
        $id = $item['id'];
        $type = strtolower($item['type'] ?? ($id !== null ? self::typeFromId($id) : ''));
        if ($id === null || !str_starts_with(strtolower($id), '/subscriptions/')) {
            return self::failed($type, 'No Azure resource ID (/subscriptions/...).');
        }
        if ($type === 'microsoft.web/sites' && str_contains(strtolower((string) $item['kind']), 'functionapp')) {
            $type .= '#functionapp';
        }
        preg_match('#^/subscriptions/([^/]+)#i', $id, $m);
        $properties = $item['properties'];
        [$os, $version] = self::os($properties);
        $segments = explode('/', rtrim($id, '/'));
        return [
            'provider_type' => $type,
            'account' => strtolower($m[1]),
            'attributes' => [
                'name' => self::name($item['name'] ?? end($segments)),
                'resource_id' => $id,
                'source' => self::provider(),
                'source_id' => strtolower($id),
                'region' => $item['location'],
                'first_seen_at' => self::time(self::scalar(self::path($properties, 'timeCreated'))),
                'os' => $os,
                'os_version' => $version,
                'public_ips' => self::scalar(self::path($properties, 'ipAddress')),
            ],
        ];
    }

    /** "/subscriptions/s/resourceGroups/g/providers/Microsoft.Sql/servers/a/databases/b" => "microsoft.sql/servers/databases" */
    public static function typeFromId(string $id): string
    {
        $parts = preg_split('#/providers/#i', $id);
        $path = explode('/', trim((string) end($parts), '/'));
        if (count($parts) < 2 || count($path) < 3) {
            return '';
        }
        $types = [array_shift($path)];
        foreach ($path as $i => $segment) {
            if ($i % 2 === 0) {
                $types[] = $segment;
            }
        }
        return strtolower(implode('/', $types));
    }

    /** @return array{0: string|null, 1: string|null} OS name and version of a virtual machine */
    private static function os(array $properties): array
    {
        $name = self::scalar(self::path($properties, 'extended.instanceView.osName'));
        if ($name !== null) {
            return [$name === strtolower($name) ? ucfirst($name) : $name, self::scalar(self::path($properties, 'extended.instanceView.osVersion'))];
        }
        return [self::scalar(self::path($properties, 'storageProfile.osDisk.osType')), null];
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
