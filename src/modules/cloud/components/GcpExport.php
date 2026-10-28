<?php

namespace app\modules\cloud\components;

use InvalidArgumentException;

/**
 * Google Cloud Asset Inventory exports: `gcloud asset search-all-resources
 * --scope=projects/<id> --format=json` (a list of ResourceSearchResult: name,
 * assetType, project, displayName, location, state, createTime, updateTime,
 * additionalAttributes; the REST API's {"results": [...]} also works) or the
 * same command with --format="csv(name,assetType,project,displayName,location,state,createTime)".
 *
 * `project` is "projects/<number>", while most full resource names carry the
 * project ID (//compute.googleapis.com/projects/<id>/zones/...). Rows that show
 * both, and the project's own row (additionalAttributes.projectId), pair the
 * number with the ID, so every resource of a project lands in one account
 * named by its project ID. Accounts are matched on either.
 *
 * createTime becomes the first-seen time. updateTime is when the resource last
 * changed, not when it was last seen, so assets are "seen" at the import time.
 */
final class GcpExport extends CloudExport
{
    private const PROJECT_TYPE = 'cloudresourcemanager.googleapis.com/project';

    /** States that mean the resource is in normal service (anything else is shown in the summary) */
    private const NORMAL_STATES = ['ACTIVE', 'RUNNING', 'RUNNABLE', 'READY', 'ENABLED'];

    /** @var array<string,string> project number => project ID */
    private array $projectIds = [];

    /** @var array<string,string> project ID or number => display name (from the project's own row) */
    private array $projectNames = [];

    public static function provider(): string
    {
        return 'gcp';
    }

    protected static function formats(): string
    {
        return 'a Google Cloud Asset Inventory export';
    }

    protected function jsonItems(mixed $json): array
    {
        $list = match (true) {
            isset($json['results']) && is_array($json['results']) => $json['results'],
            array_is_list($json) => $json,
            default => throw new InvalidArgumentException('Not ' . self::formats() . ': expected a list of resources (gcloud asset search-all-resources --format=json).'),
        };
        $items = [];
        foreach (array_values($list) as $i => $r) {
            $r = is_array($r) ? $r : [];
            $items[] = ['line' => $i + 1, 'name' => self::pick($r, 'name'), 'type' => self::pick($r, 'assetType'), 'project' => self::pick($r, 'project'),
                'display_name' => self::pick($r, 'displayName'), 'location' => self::pick($r, 'location'), 'state' => self::pick($r, 'state'),
                'created' => self::pick($r, 'createTime'), 'project_id' => self::pick((array) ($r['additionalAttributes'] ?? []), 'projectId'),
                'address' => self::pick((array) ($r['additionalAttributes'] ?? []), 'address')];
        }
        return $items;
    }

    protected function requiredColumns(): array
    {
        return [['name'], ['assettype']];
    }

    protected function csvItem(array $row): array
    {
        return ['name' => self::pick($row, 'name'), 'type' => self::pick($row, 'assettype'), 'project' => self::pick($row, 'project'),
            'display_name' => self::pick($row, 'displayname'), 'location' => self::pick($row, 'location'), 'state' => self::pick($row, 'state'),
            'created' => self::pick($row, 'createtime'), 'project_id' => self::pick($row, 'projectid'), 'address' => self::pick($row, 'address')];
    }

    protected function prepare(array $items): array
    {
        foreach ($items as $item) {
            $number = self::projectNumber($item['project']);
            $id = strtolower((string) $item['type']) === self::PROJECT_TYPE ? $item['project_id'] : self::projectIdFromName($item['name']);
            if ($number !== null && $id !== null) {
                $this->projectIds[$number] ??= $id;
            }
            if (strtolower((string) $item['type']) === self::PROJECT_TYPE && $item['display_name'] !== null) {
                foreach (array_filter([$number, $id]) as $key) {
                    $this->projectNames[$key] = $item['display_name'];
                }
            }
        }
        return $items;
    }

    protected function record(array $item): array
    {
        $type = (string) $item['type'];
        $name = $item['name'];
        if ($name === null || !str_starts_with($name, '//')) {
            return self::failed($type, 'No full resource name (//service.googleapis.com/...).');
        }
        $number = self::projectNumber($item['project']);
        $id = self::projectIdFromName($name) ?? ($number !== null ? ($this->projectIds[$number] ?? null) : null);
        $segments = explode('/', rtrim($name, '/'));
        return [
            'provider_type' => $type,
            'account' => $id ?? $number,
            'account_aliases' => array_values(array_filter([$id, $number])),
            'account_name' => $this->projectNames[$id ?? $number ?? ''] ?? null,
            'attributes' => [
                'name' => self::name($item['display_name'] ?? end($segments)),
                'resource_id' => $name,
                'source' => self::provider(),
                'source_id' => $name,
                'region' => $item['location'],
                'first_seen_at' => self::time($item['created']),
                'public_ips' => $item['address'],
            ],
            'state' => $item['state'] !== null && !in_array(strtoupper($item['state']), self::NORMAL_STATES, true)
                ? strtolower($item['state']) : null,
        ];
    }

    /** "projects/123456789012" => "123456789012" */
    private static function projectNumber(?string $project): ?string
    {
        return $project !== null && preg_match('#^projects/(\d+)$#', $project, $m) ? $m[1] : null;
    }

    /** The project ID in a full resource name: "//compute.googleapis.com/projects/example-prod/zones/..." => "example-prod" */
    private static function projectIdFromName(?string $name): ?string
    {
        return $name !== null && preg_match('#/projects/([a-z][a-z0-9-]{4,28}[a-z0-9])(?:/|$)#', $name, $m) ? $m[1] : null;
    }
}
