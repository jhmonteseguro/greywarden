<?php

namespace app\modules\cloud\components;

use InvalidArgumentException;

/**
 * AWS resource exports:
 *
 * - Resource Explorer: `aws resource-explorer-2 search` / `list-resources` JSON
 *   ({"Resources": [{"Arn", "ResourceType", "Region", "OwningAccountId",
 *   "LastReportedAt", "Properties": [{"Name": "tags", "Data": [{"Key", "Value"}]}]}]})
 *   or the console's "Export resources to CSV" (identifier, resource type,
 *   Region, account ID and a column per tag key).
 * - AWS Config advanced query: `aws configservice select-resource-config` /
 *   `select-aggregate-resource-config` JSON ({"Results": ["{...}", ...]}, one
 *   JSON string per resource with the selected fields: resourceId, resourceName,
 *   resourceType, arn, awsRegion, accountId, tags, configuration.publicIpAddress)
 *   or the query editor's CSV export.
 *
 * Resource Explorer's LastReportedAt is when AWS last indexed the resource, so
 * it becomes the asset's last-seen time. Config's capture time is when the
 * configuration last changed, which says nothing about the resource still
 * existing, so Config exports are "seen" at the import time.
 */
final class AwsExport extends CloudExport
{
    public static function provider(): string
    {
        return 'aws';
    }

    protected static function formats(): string
    {
        return 'an AWS Resource Explorer or AWS Config export';
    }

    protected function jsonItems(mixed $json): array
    {
        if (isset($json['Resources']) && is_array($json['Resources'])) {
            $list = $json['Resources'];
        } elseif (isset($json['Results']) && is_array($json['Results'])) {
            $list = array_map(static fn($r) => is_string($r) ? (json_decode($r, true) ?: []) : (array) $r, $json['Results']);
        } elseif (array_is_list($json)) {
            $list = $json; // e.g. --query Resources
        } else {
            throw new InvalidArgumentException('Not ' . self::formats() . ': expected a "Resources" (Resource Explorer) or "Results" (AWS Config) list.');
        }
        $items = [];
        foreach (array_values($list) as $i => $r) {
            $r = is_array($r) ? $r : [];
            $items[] = ['line' => $i + 1,
                'arn' => self::pick($r, 'Arn', 'arn'),
                'resource_id' => self::pick($r, 'resourceId'),
                'type' => self::pick($r, 'ResourceType', 'resourceType'),
                'region' => self::pick($r, 'Region', 'awsRegion'),
                'account' => self::pick($r, 'OwningAccountId', 'accountId'),
                'name' => self::tag(self::resourceExplorerTags($r) ?: (array) ($r['tags'] ?? []), 'Name') ?? self::pick($r, 'resourceName'),
                'last_reported' => self::pick($r, 'LastReportedAt'),
                'public_ip' => self::pick((array) ($r['configuration'] ?? []), 'publicIpAddress', 'publicIp'),
            ];
        }
        return $items;
    }

    protected function requiredColumns(): array
    {
        return [['arn', 'identifier', 'resourcearn', 'resourceid'], ['resourcetype', 'type']];
    }

    protected function csvItem(array $row): array
    {
        $arn = self::pick($row, 'arn', 'identifier', 'resourcearn');
        return [
            'arn' => $arn !== null && str_starts_with($arn, 'arn:') ? $arn : null,
            'resource_id' => self::pick($row, 'resourceid') ?? ($arn !== null && !str_starts_with($arn, 'arn:') ? $arn : null),
            'type' => self::pick($row, 'resourcetype', 'type'),
            'region' => self::pick($row, 'region', 'awsregion'),
            'account' => self::pick($row, 'owningaccountid', 'accountid', 'awsaccountid', 'awsaccount', 'account'),
            'name' => self::untagged(self::pick($row, 'tagname', 'name', 'resourcename')),
            'last_reported' => self::pick($row, 'lastreportedat', 'lastreported'),
            'public_ip' => self::pick($row, 'configurationpublicipaddress', 'configurationpublicip', 'publicipaddress', 'publicip'),
        ];
    }

    protected function record(array $item): array
    {
        $type = (string) $item['type'];
        $arn = $item['arn'];
        if ($arn === null && $item['resource_id'] === null) {
            return self::failed($type, 'No ARN or resource ID.');
        }
        $account = $item['account'] ?? ($arn !== null ? (explode(':', $arn)[4] ?? '') : '');
        $region = ($item['region'] ?? ($arn !== null ? (explode(':', $arn)[3] ?? '') : '')) ?: 'global';
        $resourceId = $arn ?? $item['resource_id'];
        return [
            'provider_type' => $type,
            'account' => $account !== '' ? $account : null,
            'attributes' => [
                'name' => self::name($item['name'] ?? ($arn !== null ? self::arnName($arn) : $item['resource_id'])),
                'resource_id' => $resourceId,
                'source' => self::provider(),
                // Config exports without the ARN: the resource ID is only unique within an account, region and type
                'source_id' => $arn ?? implode(':', [$account, $region, strtolower($type), $item['resource_id']]),
                'region' => $region,
                'last_seen_at' => self::time($item['last_reported']),
                'public_ips' => $item['public_ip'],
            ],
        ];
    }

    /** The readable part of an ARN: "arn:aws:iam::111122223333:role/ci/deploy" => "deploy" */
    public static function arnName(string $arn): string
    {
        $resource = implode(':', array_slice(explode(':', $arn), 5));
        if (preg_match('#^loadbalancer/(?:app|net|gwy)/([^/]+)/#', $resource, $m)) {
            return $m[1];
        }
        $parts = preg_split('#[:/]#', $resource);
        return (string) end($parts) ?: $arn;
    }

    /** The console CSV writes "(not tagged)" in the Name column of resources without a Name tag: no name, so the ARN's is used */
    private static function untagged(?string $name): ?string
    {
        return $name !== null && strcasecmp($name, '(not tagged)') === 0 ? null : $name;
    }

    /** Resource Explorer: Properties [{"Name": "tags", "Data": [{"Key", "Value"}]}] */
    private static function resourceExplorerTags(array $resource): array
    {
        foreach ((array) ($resource['Properties'] ?? []) as $property) {
            if (strtolower((string) ($property['Name'] ?? '')) === 'tags' && is_array($property['Data'] ?? null)) {
                return $property['Data'];
            }
        }
        return [];
    }

    /** A tag's value from [{"Key"|"key", "Value"|"value"}] */
    private static function tag(array $tags, string $key): ?string
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && (string) ($tag['Key'] ?? $tag['key'] ?? '') === $key) {
                $value = trim((string) ($tag['Value'] ?? $tag['value'] ?? ''));
                return $value !== '' ? $value : null;
            }
        }
        return null;
    }
}
