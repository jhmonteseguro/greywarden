<?php

namespace app\modules\intel\components\edge;

use app\modules\intel\components\Cloudflare;
use RuntimeException;
use Yii;

/**
 * Reads the zone's traffic from the Cloudflare GraphQL Analytics API
 * (httpRequestsAdaptiveGroups and firewallEventsAdaptiveGroups, available on
 * every plan; the counts are sampled, so they are estimates).
 *
 * The limits of the zone's plan (how far back, how wide a window, page size,
 * which fields) are read from the API's settings node first, so nothing that
 * the plan does not offer is asked for. Five queries run in parallel:
 *
 *   ips        every request, by client IP, ASN, country, user agent and method (volume, agents)
 *   errors     responses >= 400, by client IP, path and status (probing, enumeration)
 *   exposures  responses < 400 on secret / web-shell paths (a probe that succeeded)
 *   payloads   query strings that look like attacks (when the plan has clientRequestQuery)
 *   firewall   security events by client IP and action (what Cloudflare already stopped)
 *
 * Only `ips` and `errors` are required; the others add a warning when they fail.
 * .env: CLOUDFLARE_API_TOKEN (Zone > Analytics > Read), CLOUDFLARE_ZONE_ID.
 */
final class CloudflareTraffic
{
    public const HTTP = 'httpRequestsAdaptiveGroups';
    public const FIREWALL = 'firewallEventsAdaptiveGroups';

    /** Used when the settings node cannot be read: a day back, a day wide */
    public const FALLBACK_LIMITS = ['enabled' => true, 'availableFields' => null, 'maxDuration' => 86400, 'maxPageSize' => 5000, 'notOlderThan' => 86400];

    /** Largest page asked for, whatever the plan allows */
    public const MAX_ROWS = 10000;

    public const SETTINGS_TTL = 86400;

    /** Firewall actions that stopped the request (normalised: lower case, no underscores) */
    public const STOPPING_ACTIONS = ['block', 'drop', 'connectionclose', 'challenge', 'jschallenge', 'managedchallenge', 'challengefailed', 'jschallengefailed', 'managedchallengefailed'];

    /** Firewall actions that mean a challenge was passed */
    public const PASSED_ACTIONS = ['challengesolved', 'jschallengesolved', 'managedchallengeinteractivesolved', 'managedchallengenoninteractivesolved'];

    public static function isConfigured(): bool
    {
        return Cloudflare::isConfigured();
    }

    /**
     * Dataset limits for the zone: [dataset => [enabled, availableFields, maxDuration, maxPageSize, notOlderThan]].
     * Cached for a day; `warning` is set when the settings could not be read and the fallback is used.
     *
     * @return array{http: array, firewall: array, warning: ?string}
     */
    public static function settings(bool $refresh = false): array
    {
        $key = ['edge-cf-settings', (string) gw_env('CLOUDFLARE_ZONE_ID')];
        if (!$refresh && ($cached = Yii::$app->cache->get($key)) !== false) {
            return $cached;
        }
        $fields = 'enabled availableFields maxDuration maxPageSize notOlderThan';
        $query = 'query EdgeSettings($zoneTag: string) { viewer { zones(filter: {zoneTag: $zoneTag}) { settings { '
            . self::HTTP . " { $fields } " . self::FIREWALL . " { $fields } } } } }";
        try {
            $data = Cloudflare::graphql($query, ['zoneTag' => (string) gw_env('CLOUDFLARE_ZONE_ID')]);
            $settings = $data['viewer']['zones'][0]['settings'] ?? null;
            if (!is_array($settings)) {
                throw new RuntimeException('Cloudflare analytics: the zone was not found (check CLOUDFLARE_ZONE_ID and that the token can read its analytics).');
            }
            $out = [
                'http' => self::limits($settings[self::HTTP] ?? null),
                'firewall' => self::limits($settings[self::FIREWALL] ?? null),
                'warning' => null,
            ];
            Yii::$app->cache->set($key, $out, self::SETTINGS_TTL);
            return $out;
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                throw $e;
            }
            return ['http' => self::FALLBACK_LIMITS, 'firewall' => self::FALLBACK_LIMITS, 'warning' => 'Plan limits unknown (' . $e->getMessage() . '); used a one-day window.'];
        }
    }

    private static function limits(?array $node): array
    {
        if ($node === null) {
            return ['enabled' => false] + self::FALLBACK_LIMITS;
        }
        return [
            'enabled' => (bool) ($node['enabled'] ?? false),
            'availableFields' => isset($node['availableFields']) ? array_map('strval', (array) $node['availableFields']) : null,
            'maxDuration' => (int) ($node['maxDuration'] ?? self::FALLBACK_LIMITS['maxDuration']) ?: self::FALLBACK_LIMITS['maxDuration'],
            'maxPageSize' => (int) ($node['maxPageSize'] ?? self::FALLBACK_LIMITS['maxPageSize']) ?: self::FALLBACK_LIMITS['maxPageSize'],
            'notOlderThan' => (int) ($node['notOlderThan'] ?? self::FALLBACK_LIMITS['notOlderThan']) ?: self::FALLBACK_LIMITS['notOlderThan'],
        ];
    }

    /** Whether a dataset offers a field (unknown field lists count as yes) */
    public static function hasField(array $limits, string $field): bool
    {
        if ($limits['availableFields'] === null) {
            return true;
        }
        foreach ($limits['availableFields'] as $available) {
            if (preg_match('/(^|[._])' . preg_quote($field, '/') . '$/', $available)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Traffic between two UTC timestamps.
     *
     * @return array{ips: array, errors: array, exposures: array, payloads: array, firewall: array, truncated: string[], warnings: string[]}
     *   ips:       [ip, asn, country, agent, method, count]
     *   errors / exposures / payloads: [ip, path, query, status, count]
     *   firewall:  [ip, action, count]
     * @throws RuntimeException when a required query fails
     */
    public static function fetch(int $start, int $end, array $settings): array
    {
        $http = $settings['http'];
        $firewall = $settings['firewall'];
        if (!$http['enabled']) {
            throw new RuntimeException('Cloudflare analytics: request analytics (' . self::HTTP . ') are not available for this zone.');
        }
        $limit = min(self::MAX_ROWS, max(100, $http['maxPageSize']));
        $window = sprintf('datetime_geq: %s, datetime_lt: %s', self::literal(gmdate('Y-m-d\TH:i:s\Z', $start)), self::literal(gmdate('Y-m-d\TH:i:s\Z', $end)));
        // Visitor traffic only (not Workers subrequests), where the plan can filter on it
        if (self::hasField($http, 'requestSource')) {
            $window .= ', requestSource: "eyeball"';
        }
        $hasQuery = self::hasField($http, 'clientRequestQuery');
        $pathFields = 'clientIP clientRequestPath edgeResponseStatus' . ($hasQuery ? ' clientRequestQuery' : '');

        $queries = [
            'ips' => self::groups(self::HTTP, "{ $window }", $limit, 'clientIP clientAsn clientCountryName userAgent clientRequestHTTPMethodName'),
            'errors' => self::groups(self::HTTP, "{ $window, edgeResponseStatus_geq: 400 }", $limit, $pathFields),
            'exposures' => self::groups(self::HTTP, "{ $window, edgeResponseStatus_lt: 400, OR: [" . self::likes('clientRequestPath', EdgeCatalogue::exposureLikes()) . '] }', $limit, $pathFields),
        ];
        if ($hasQuery) {
            $queries['payloads'] = self::groups(self::HTTP, "{ $window, edgeResponseStatus_lt: 400, OR: [" . self::likes('clientRequestQuery', EdgeCatalogue::PAYLOAD_LIKES) . '] }', $limit, $pathFields);
        }
        if ($firewall['enabled']) {
            $fwLimit = min(self::MAX_ROWS, max(100, $firewall['maxPageSize']));
            $queries['firewall'] = self::groups(self::FIREWALL, "{ $window }", $fwLimit, 'clientIP action');
        }

        $zone = ['zoneTag' => (string) gw_env('CLOUDFLARE_ZONE_ID')];
        $results = Cloudflare::graphqlAll(array_map(static fn(string $q) => [$q, $zone], $queries));

        $out = ['ips' => [], 'errors' => [], 'exposures' => [], 'payloads' => [], 'firewall' => [], 'truncated' => [], 'warnings' => []];
        foreach ($results as $name => $result) {
            if ($result['error'] !== null) {
                if (in_array($name, ['ips', 'errors'], true)) {
                    throw new RuntimeException($result['error']);
                }
                $out['warnings'][] = sprintf('%s skipped: %s', $name, $result['error']);
                continue;
            }
            $rows = (array) ($result['data']['viewer']['zones'][0]['rows'] ?? []);
            if (count($rows) >= ($name === 'firewall' ? ($fwLimit ?? $limit) : $limit)) {
                $out['truncated'][] = $name;
            }
            foreach ($rows as $row) {
                $out[$name][] = self::row($name, (array) ($row['dimensions'] ?? []), (int) ($row['count'] ?? 0));
            }
        }
        return $out;
    }

    private static function row(string $name, array $d, int $count): array
    {
        return match ($name) {
            'ips' => [
                'ip' => (string) ($d['clientIP'] ?? ''),
                'asn' => isset($d['clientAsn']) && (int) $d['clientAsn'] > 0 ? (int) $d['clientAsn'] : null,
                'country' => ($d['clientCountryName'] ?? '') !== '' ? mb_substr((string) $d['clientCountryName'], 0, 64) : null,
                'agent' => (string) ($d['userAgent'] ?? ''),
                'method' => strtoupper((string) ($d['clientRequestHTTPMethodName'] ?? 'GET')),
                'count' => $count,
            ],
            'firewall' => [
                'ip' => (string) ($d['clientIP'] ?? ''),
                'action' => strtolower(str_replace('_', '', (string) ($d['action'] ?? ''))),
                'count' => $count,
            ],
            default => [
                'ip' => (string) ($d['clientIP'] ?? ''),
                'path' => (string) ($d['clientRequestPath'] ?? ''),
                'query' => (string) ($d['clientRequestQuery'] ?? ''),
                'status' => (int) ($d['edgeResponseStatus'] ?? 0),
                'count' => $count,
            ],
        };
    }

    /** One grouped query against a dataset; rows come back under `rows` */
    private static function groups(string $dataset, string $filter, int $limit, string $dimensions): string
    {
        return sprintf(
            'query EdgeTraffic($zoneTag: string) { viewer { zones(filter: {zoneTag: $zoneTag}) { rows: %s(filter: %s, limit: %d, orderBy: [count_DESC]) { count dimensions { %s } } } } }',
            $dataset, $filter, $limit, $dimensions
        );
    }

    /** `{field_like: "..."}` objects for an OR list */
    private static function likes(string $field, array $patterns): string
    {
        return implode(', ', array_map(static fn(string $p) => sprintf('{%s_like: %s}', $field, self::literal($p)), $patterns));
    }

    /** A GraphQL string literal */
    public static function literal(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
