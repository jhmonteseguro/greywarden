<?php

namespace app\modules\cloud\components;

use app\modules\intel\components\Cloudflare;
use RuntimeException;

/**
 * Cloudflare zones as CloudImporter records: one DNS zone asset per zone, in
 * the Cloudflare account that owns it. Read-only (GET /zones, paginated); the
 * token in CLOUDFLARE_API_TOKEN needs Zone > Zone > Read on the zones to list.
 */
final class CloudflareZones
{
    public const PER_PAGE = 50;

    /** Pages read at most (50 zones each), so a misbehaving API cannot loop forever */
    private const MAX_PAGES = 200;

    /**
     * @return list<array> records (see CloudExport)
     * @throws RuntimeException when Cloudflare fails or no token is configured
     */
    public static function records(): array
    {
        if (!Cloudflare::hasToken()) {
            throw new RuntimeException('Cloudflare is not configured: set CLOUDFLARE_API_TOKEN in .env (Zone > Zone > Read).');
        }
        $records = [];
        $page = 1;
        do {
            $data = Cloudflare::api('GET', sprintf('/zones?page=%d&per_page=%d', $page, self::PER_PAGE));
            foreach ((array) ($data['result'] ?? []) as $zone) {
                $records[] = self::record(count($records) + 1, (array) $zone);
            }
            $pages = (int) ($data['result_info']['total_pages'] ?? 1);
        } while (++$page <= min($pages, self::MAX_PAGES));
        return $records;
    }

    /** @param array $zone a zone object from the API */
    public static function record(int $line, array $zone): array
    {
        $id = (string) ($zone['id'] ?? '');
        $name = (string) ($zone['name'] ?? '');
        $status = strtolower((string) ($zone['status'] ?? ''));
        return [
            'line' => $line,
            'provider' => 'cloudflare',
            'provider_type' => 'zone',
            'account' => $zone['account']['id'] ?? null,
            'account_aliases' => [],
            'account_name' => ($zone['account']['name'] ?? '') !== '' ? $zone['account']['name'] : null,
            'attributes' => [
                'name' => $name,
                'resource_id' => $id,
                'source' => 'cloudflare',
                'source_id' => $id,
                'region' => 'global',
                'first_seen_at' => CloudExport::time($zone['created_on'] ?? null),
                'public_endpoints' => $name,
            ],
            'create' => ['internet_facing' => true],
            'state' => !empty($zone['paused']) ? 'paused' : ($status !== '' && $status !== 'active' ? $status : null),
            'error' => $id === '' || $name === '' ? 'The zone has no ID or name.' : null,
        ];
    }
}
