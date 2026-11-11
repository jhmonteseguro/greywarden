<?php

namespace app\modules\cloud\components;

use app\modules\cloud\models\CloudAsset;
use app\modules\intel\components\Cloudflare;
use app\modules\intel\models\BlocklistEntry;
use RuntimeException;

/**
 * Cloudflare zones as CloudImporter records: one DNS zone asset per zone, in
 * the Cloudflare account that owns it, with the zone's configuration (see
 * CloudflareConfig) as its properties. Read-only. The token in
 * CLOUDFLARE_API_TOKEN needs Zone > Zone > Read to list the zones, and for
 * the configuration Zone Settings Read, DNS Read and Zone WAF Read; a part
 * it cannot read is listed in the zone's not_read property (a finding), so
 * the sync still works with a narrower token.
 *
 * Run by `make sync-cloudflare` and every CLOUDFLARE_SYNC_INTERVAL minutes by
 * the scheduler, so a record switched to DNS only or a weakened setting shows
 * up as a finding and in the zone's History.
 */
final class CloudflareZones
{
    public const PER_PAGE = 50;
    public const DNS_PER_PAGE = 100;

    /** Pages read at most (50 zones, or 100 DNS records, each), so a misbehaving API cannot loop forever */
    private const MAX_PAGES = 200;

    /** Record types Cloudflare can proxy (it also says so per record: "proxiable") */
    private const PROXIABLE = ['A', 'AAAA', 'CNAME'];

    /** @var string[] problems met while reading zone configurations (other than missing permissions), for the output */
    public static array $warnings = [];

    /**
     * @param bool $config also read each zone's configuration
     * @return list<array> records (see CloudExport)
     * @throws RuntimeException when Cloudflare fails or no token is configured
     */
    public static function records(bool $config = true): array
    {
        if (!Cloudflare::hasToken()) {
            throw new RuntimeException('Cloudflare is not configured: set CLOUDFLARE_API_TOKEN in .env (Zone > Zone > Read).');
        }
        self::$warnings = [];
        $records = [];
        $page = 1;
        do {
            $data = Cloudflare::api('GET', sprintf('/zones?page=%d&per_page=%d', $page, self::PER_PAGE));
            foreach ((array) ($data['result'] ?? []) as $zone) {
                $zone = (array) $zone;
                $properties = $config && ($zone['id'] ?? '') !== '' ? self::configuration($zone) : null;
                $records[] = self::record(count($records) + 1, $zone, $properties);
            }
            $pages = (int) ($data['result_info']['total_pages'] ?? 1);
        } while (++$page <= min($pages, self::MAX_PAGES));
        return $records;
    }

    /**
     * @param array $zone a zone object from the API
     * @param array|null $properties its configuration (null = not read: the asset keeps what it has)
     */
    public static function record(int $line, array $zone, ?array $properties = null): array
    {
        $id = (string) ($zone['id'] ?? '');
        $name = (string) ($zone['name'] ?? '');
        $status = strtolower((string) ($zone['status'] ?? ''));
        return [
            'line' => $line,
            'provider' => 'cloudflare',
            'provider_type' => CloudflareConfig::TYPE,
            'account' => $zone['account']['id'] ?? null,
            'account_aliases' => [],
            'account_name' => ($zone['account']['name'] ?? '') !== '' ? $zone['account']['name'] : null,
            'attributes' => [
                'name' => $name,
                'resource_id' => $id,
                'source' => 'cloudflare',
                'source_id' => $id,
                'provider_type' => CloudflareConfig::TYPE,
                'region' => 'global',
                'first_seen_at' => CloudExport::time($zone['created_on'] ?? null),
                'public_endpoints' => $name,
                'properties' => $properties !== null ? json_encode($properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ],
            'create' => ['internet_facing' => true],
            'state' => !empty($zone['paused']) ? 'paused' : ($status !== '' && $status !== 'active' ? $status : null),
            'error' => $id === '' || $name === '' ? 'The zone has no ID or name.' : null,
        ];
    }

    /**
     * The zone's configuration (see CloudflareConfig). A part the token may not read
     * goes into not_read; any other failure means the whole configuration is not
     * read this time (null), so a passing glitch changes nothing.
     */
    public static function configuration(array $zone): ?array
    {
        $id = rawurlencode((string) $zone['id']);
        $p = ['paused' => !empty($zone['paused'])];
        $notRead = [];
        $read = static function (string $part, callable $fn) use (&$p, &$notRead, $zone): bool {
            try {
                $p = $fn() + $p;
                return true;
            } catch (RuntimeException $e) {
                if (self::isPermissionError($e->getMessage())) {
                    $notRead[] = $part;
                    return true;
                }
                self::$warnings[] = sprintf('%s: %s not read: %s', $zone['name'] ?? $zone['id'], CloudflareConfig::NOT_READ[$part][0], $e->getMessage());
                return false;
            }
        };

        $ok = $read('settings', static fn() => self::settings($id))
            && $read('dns', static fn() => self::dnsRecords($id))
            && $read('dnssec', static fn() => ['dnssec' => strtolower((string) (Cloudflare::api('GET', "/zones/$id/dnssec")['result']['status'] ?? 'unknown'))]);
        if ($ok && Cloudflare::isConfigured() && (string) gw_env('CLOUDFLARE_ZONE_ID') === (string) $zone['id']) {
            $ok = $read('rules', static function () {
                $state = Cloudflare::ruleState();
                // A missing rule only matters once something has been blocked through it
                return $state === 'missing' && !BlocklistEntry::find()->where(['not', ['cloudflare_added_at' => null]])->exists() ? [] : ['blocklist_rule' => $state];
            });
        }
        if (!$ok) {
            return null;
        }
        if ($notRead) {
            $p['not_read'] = $notRead;
        }
        // In a fixed order, so the stored JSON only changes when a setting does
        $order = ['paused', 'ssl', 'min_tls', 'always_use_https', 'hsts', 'security_level', 'dnssec', 'blocklist_rule', 'dns_records', 'proxied', 'dns_only', 'not_read'];
        return array_merge(array_intersect_key(array_flip($order), $p), $p);
    }

    /** Cloudflare refused for lack of permission (rather than failing) */
    public static function isPermissionError(string $message): bool
    {
        return preg_match('/\((9109|10000|9106|10001)\)|unauthori[sz]ed|authentication error|permission|forbidden|HTTP 403/i', $message) === 1;
    }

    /** SSL/TLS, HTTPS and security settings (GET /zones/{id}/settings) */
    private static function settings(string $id): array
    {
        $values = [];
        foreach ((array) (Cloudflare::api('GET', "/zones/$id/settings")['result'] ?? []) as $setting) {
            if (isset($setting['id'])) {
                $values[(string) $setting['id']] = $setting['value'] ?? null;
            }
        }
        $p = [];
        if (is_string($values['ssl'] ?? null)) {
            $p['ssl'] = strtolower($values['ssl']);
        }
        if (is_string($values['min_tls_version'] ?? null)) {
            $p['min_tls'] = $values['min_tls_version'];
        }
        if (is_string($values['always_use_https'] ?? null)) {
            $p['always_use_https'] = $values['always_use_https'] === 'on';
        }
        if (is_array($values['security_header']['strict_transport_security'] ?? null)) {
            $p['hsts'] = !empty($values['security_header']['strict_transport_security']['enabled']);
        }
        if (is_string($values['security_level'] ?? null)) {
            $p['security_level'] = str_replace('_', ' ', $values['security_level']);
        }
        return $p;
    }

    /** Which proxiable records are proxied and which are DNS only (GET /zones/{id}/dns_records, paginated) */
    private static function dnsRecords(string $id): array
    {
        $count = 0;
        $state = [];
        $page = 1;
        do {
            $data = Cloudflare::api('GET', sprintf('/zones/%s/dns_records?page=%d&per_page=%d', $id, $page, self::DNS_PER_PAGE));
            foreach ((array) ($data['result'] ?? []) as $r) {
                $count++;
                $type = strtoupper((string) ($r['type'] ?? ''));
                $proxiable = isset($r['proxiable']) ? (bool) $r['proxiable'] : in_array($type, self::PROXIABLE, true);
                if (!$proxiable || ($r['name'] ?? '') === '') {
                    continue;
                }
                $key = sprintf('%s (%s)', strtolower((string) $r['name']), $type);
                // Several records with one name (round robin): DNS only if any of them is
                $state[$key] = ($state[$key] ?? true) && !empty($r['proxied']);
            }
            $pages = (int) ($data['result_info']['total_pages'] ?? 1);
        } while (++$page <= min($pages, self::MAX_PAGES));
        ksort($state);
        return [
            'dns_records' => $count,
            'proxied' => array_keys(array_filter($state)),
            'dns_only' => array_keys(array_filter($state, static fn(bool $proxied) => !$proxied)),
        ];
    }

    /** Recomputes the findings of every Cloudflare zone (after the DNS-only list changes) */
    public static function refreshFindings(): int
    {
        $n = 0;
        foreach (CloudAsset::find()->joinWith('account a', false)->where(['a.provider' => 'cloudflare'])->andWhere(['not', ['cloud_asset.properties' => null]])->each() as $asset) {
            /** @var CloudAsset $asset */
            $before = [$asset->findings, $asset->finding_count, $asset->finding_severity];
            $asset->refreshFindings();
            if ($before !== [$asset->findings, $asset->finding_count, $asset->finding_severity]) {
                $asset->save(false, ['findings', 'finding_count', 'finding_severity']);
                $n++;
            }
        }
        return $n;
    }

    /** Minutes between the scheduler's syncs (CLOUDFLARE_SYNC_INTERVAL, default 15, 0 = off, at most a day) */
    public static function syncInterval(): int
    {
        $value = gw_env('CLOUDFLARE_SYNC_INTERVAL');
        return $value === null || $value === false || $value === '' ? 15 : max(0, min(1440, (int) $value));
    }
}
