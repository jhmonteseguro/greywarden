<?php

namespace app\modules\intel\components;

use RuntimeException;

/**
 * Pushes blocklist entries to a Cloudflare WAF custom rule at the edge.
 *
 * The rule (CLOUDFLARE_RULE_NAME, default "Block Bad Actors") must already exist
 * in the zone's custom rules. Each entry becomes one OR-ed condition in it:
 *
 *   ... or (ip.src eq 203.0.113.7) or (ip.src in {198.51.100.0/24}) or (ip.src.asnum eq 64496)
 *
 * Only the exact condition Greywarden added is ever removed, so hand-written
 * parts of the expression are left alone.
 *
 * .env: CLOUDFLARE_API_TOKEN (Zone > Zone WAF > Edit), CLOUDFLARE_ZONE_ID.
 * The Cloud Inventory's zone sync (`make sync-cloudflare`) reuses the token and
 * also needs Zone > Zone > Read on the zones to list; edge findings
 * (`make edge-pull`) need Zone > Analytics > Read for graphql().
 */
class Cloudflare
{
    public const API = 'https://api.cloudflare.com/client/v4';
    public const PHASE = 'http_request_firewall_custom';
    /** Rule fields sent back on PATCH (the API replaces the whole definition) */
    private const RULE_FIELDS = ['action', 'action_parameters', 'description', 'enabled', 'expression', 'logging', 'ref'];

    public static function isConfigured(): bool
    {
        return gw_env('CLOUDFLARE_API_TOKEN') && gw_env('CLOUDFLARE_ZONE_ID');
    }

    /** Only the API token: enough for read-only calls such as the zone sync (Zone > Zone > Read) */
    public static function hasToken(): bool
    {
        return (bool) gw_env('CLOUDFLARE_API_TOKEN');
    }

    public static function ruleName(): string
    {
        return gw_env('CLOUDFLARE_RULE_NAME') ?: 'Block Bad Actors';
    }

    /** Rule-language condition for a blocklist value (ip | cidr | asn, as normalised by BlocklistEntry) */
    public static function clause(string $type, string $value): string
    {
        return match ($type) {
            'asn' => sprintf('(ip.src.asnum eq %d)', (int) preg_replace('/^AS/i', '', $value)),
            'cidr' => sprintf('(ip.src in {%s})', $value),
            default => sprintf('(ip.src eq %s)', $value),
        };
    }

    /**
     * Swap one condition in the rule for another in a single update; either may be null
     * (add: null → clause, remove: clause → null).
     *
     * @return array{changed: bool, enabled: bool} whether the rule was updated, and whether it is enabled in Cloudflare
     * @throws RuntimeException with a message fit for the user
     */
    public static function replace(?string $remove, ?string $add): array
    {
        if ($remove === $add) {
            return ['changed' => false, 'enabled' => true];
        }
        if (!self::isConfigured()) {
            throw new RuntimeException('Cloudflare is not configured (set CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID in .env).');
        }
        $zone = rawurlencode((string) gw_env('CLOUDFLARE_ZONE_ID'));
        $ruleset = self::call('GET', "/zones/$zone/rulesets/phases/" . self::PHASE . '/entrypoint');
        $matches = array_values(array_filter($ruleset['rules'] ?? [], static fn(array $r) => trim($r['description'] ?? '') === self::ruleName()));
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                $matches ? 'There are %2$d custom rules named "%1$s" in Cloudflare; rename all but one.' : 'No custom rule named "%s" was found in the Cloudflare zone. Create it first (WAF › Custom rules).',
                self::ruleName(),
                count($matches)
            ));
        }
        $rule = $matches[0];
        $expression = trim((string) $rule['expression']);
        $updated = $expression;
        if ($remove !== null) {
            $updated = self::without($updated, $remove);
        }
        if ($add !== null && !str_contains($updated, $add)) {
            $updated .= ' or ' . $add;
        }
        $enabled = (bool) ($rule['enabled'] ?? true);
        if ($updated === $expression) {
            return ['changed' => false, 'enabled' => $enabled];
        }
        $body = array_intersect_key($rule, array_flip(self::RULE_FIELDS));
        $body['expression'] = $updated;
        self::call('PATCH', "/zones/$zone/rulesets/" . rawurlencode($ruleset['id']) . '/rules/' . rawurlencode($rule['id']), $body);
        return ['changed' => true, 'enabled' => $enabled];
    }

    /** The expression without one OR-ed condition (unchanged if it is not there) */
    public static function without(string $expression, string $clause): string
    {
        $quoted = preg_quote($clause, '/');
        foreach (["/\\s+or\\s+$quoted/", "/^$quoted\\s+or\\s+/"] as $pattern) {
            $result = preg_replace($pattern, '', $expression, 1, $count);
            if ($count) {
                return $result;
            }
        }
        if ($expression === $clause) {
            throw new RuntimeException(sprintf('It is the only condition in the Cloudflare rule "%s", and a rule cannot be empty. Edit or disable the rule in Cloudflare instead.', self::ruleName()));
        }
        if (str_contains($expression, $clause)) {
            throw new RuntimeException(sprintf('%s is not an OR-ed condition in the Cloudflare rule "%s" (the rule was edited by hand), so it cannot be removed automatically. Remove it in Cloudflare.', $clause, self::ruleName()));
        }
        // Already removed in Cloudflare
        return $expression;
    }

    /** @throws RuntimeException */
    private static function call(string $method, string $path, ?array $body = null): array
    {
        $data = self::api($method, $path, $body);
        if (is_array($data['result'] ?? null)) {
            return $data['result'];
        }
        throw new RuntimeException('Cloudflare: the response has no result.');
    }

    /**
     * One Cloudflare API v4 request with the configured token. Returns the whole
     * response ({success, result, result_info, ...}) so callers can paginate.
     *
     * @throws RuntimeException when the request fails or Cloudflare reports errors
     */
    public static function api(string $method, string $path, ?array $body = null): array
    {
        $response = Http::fetchAll(['cf' => [
            'url' => self::API . $path,
            'method' => $method,
            'headers' => ['Authorization: Bearer ' . gw_env('CLOUDFLARE_API_TOKEN'), 'Content-Type: application/json'],
            'body' => $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES) : null,
            'timeout' => 15,
        ]])['cf'];
        $data = Http::json($response);
        if (($data['success'] ?? false) === true) {
            return $data;
        }
        $errors = array_map(static fn(array $e) => trim(($e['message'] ?? '') . (isset($e['code']) ? ' (' . $e['code'] . ')' : '')), $data['errors'] ?? []);
        throw new RuntimeException('Cloudflare: ' . ($errors ? implode('; ', $errors) : Http::errorText($response)));
    }

    /**
     * One GraphQL Analytics API query (POST /graphql) with the configured token.
     * The token needs Zone > Analytics > Read.
     *
     * @return array the `data` object
     * @throws RuntimeException when the request fails or the query reports errors
     */
    public static function graphql(string $query, array $variables = []): array
    {
        $result = self::graphqlAll(['q' => [$query, $variables]])['q'];
        if ($result['error'] !== null) {
            throw new RuntimeException($result['error']);
        }
        return $result['data'];
    }

    /**
     * Several GraphQL queries in parallel, so one failing query does not lose the others.
     *
     * @param array<string, array{string, array}> $queries name => [query, variables]
     * @return array<string, array{data: ?array, error: ?string}>
     */
    public static function graphqlAll(array $queries): array
    {
        $requests = [];
        foreach ($queries as $name => [$query, $variables]) {
            $requests[$name] = [
                'url' => self::API . '/graphql',
                'method' => 'POST',
                'headers' => ['Authorization: Bearer ' . gw_env('CLOUDFLARE_API_TOKEN'), 'Content-Type: application/json'],
                'body' => json_encode(['query' => $query, 'variables' => (object) $variables], JSON_UNESCAPED_SLASHES),
                'timeout' => 30,
            ];
        }
        $out = [];
        foreach (Http::fetchAll($requests) as $name => $response) {
            $data = Http::json($response);
            $errors = array_filter(array_map(static fn($e) => trim((string) (is_array($e) ? ($e['message'] ?? '') : $e)), (array) ($data['errors'] ?? [])));
            $out[$name] = match (true) {
                (bool) $errors => ['data' => null, 'error' => 'Cloudflare analytics: ' . implode('; ', array_unique($errors))],
                !is_array($data['data'] ?? null) => ['data' => null, 'error' => 'Cloudflare analytics: ' . ($response['status'] === 200 ? 'the response has no data.' : Http::errorText($response))],
                default => ['data' => $data['data'], 'error' => null],
            };
        }
        return $out;
    }
}
