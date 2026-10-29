<?php

namespace app\modules\intel\components;

use RuntimeException;
use Yii;

/**
 * Pushes blocklist entries to Cloudflare:
 *
 *   - addresses and networks go into an account IP list (CLOUDFLARE_LIST_NAME,
 *     default "greywarden_blocklist"; created when missing);
 *   - the zone's WAF custom rule "Greywarden Blocklist" blocks that list and
 *     every blocked ASN (IP lists cannot hold ASNs below Enterprise):
 *
 *       (ip.src in $greywarden_blocklist) or (ip.src.asnum eq 64496) or (ip.src.asnum eq 64511)
 *
 * The rule belongs to Greywarden: its expression is rewritten from the blocklist
 * on every change, and its action is set to Block. When it is missing, Greywarden
 * creates it disabled at the end of the zone's custom rules; the user then moves it
 * to the right place (top, or below any rule that allows trusted traffic) and
 * enables it. Its enabled state is never changed afterwards.
 *
 * .env: CLOUDFLARE_API_TOKEN (Zone > Zone WAF > Edit, Account > Account Filter
 * Lists > Edit), CLOUDFLARE_ZONE_ID, and optionally CLOUDFLARE_ACCOUNT_ID (otherwise
 * read from the zone, which needs Zone > Zone > Read). The Cloud Inventory's zone
 * sync (`make sync-cloudflare`) also needs Zone > Zone > Read; edge findings
 * (`make edge-pull`) need Zone > Analytics > Read for graphql().
 */
class Cloudflare
{
    public const API = 'https://api.cloudflare.com/client/v4';
    public const PHASE = 'http_request_firewall_custom';
    public const RULE_NAME = 'Greywarden Blocklist';
    public const DEFAULT_LIST = 'greywarden_blocklist';
    /** The rule "Add to Cloudflare" used before the IP list (see `make cloudflare-migrate`) */
    public const LEGACY_RULE_NAME = 'Block Bad Actors';
    /** Rule fields sent back on PATCH (the API replaces the whole definition) */
    private const RULE_FIELDS = ['action', 'action_parameters', 'description', 'enabled', 'expression', 'logging', 'ref'];
    /** List item pages read at most (500 items each) */
    private const MAX_PAGES = 100;
    /** Polls of a list bulk operation before giving up */
    public const BULK_POLLS = 20;

    /** @var int microseconds between bulk operation polls (tests set 0) */
    public static int $pollDelay = 500000;

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
        return self::RULE_NAME;
    }

    /** The IP list's name (lower-case letters, digits and underscores, at most 50) */
    public static function listName(): string
    {
        $name = (string) gw_env('CLOUDFLARE_LIST_NAME');
        return preg_match('/^[a-z0-9_]{1,50}$/', $name) ? $name : self::DEFAULT_LIST;
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

    /** The rule's expression: the IP list, then each ASN */
    public static function expression(array $asns): string
    {
        $asns = array_values(array_unique(array_map('intval', $asns)));
        sort($asns);
        return implode(' or ', array_merge(
            [sprintf('(ip.src in $%s)', self::listName())],
            array_map(static fn(int $asn) => self::clause('asn', (string) $asn), $asns),
        ));
    }

    /** What to do after Greywarden created the rule */
    public static function createdRuleMessage(): string
    {
        return sprintf('Greywarden created the custom rule "%s" in Cloudflare, disabled, at the end of the zone\'s custom rules. '
            . 'In Security › WAF › Custom rules, move it to the top (or second, below any rule that allows or skips trusted traffic), then enable it. '
            . 'Nothing is blocked until you do.', self::RULE_NAME);
    }

    /**
     * Brings Cloudflare in line with the blocklist: adds and removes IP list items,
     * then sets the rule to block the list and $asns, creating the list and the
     * (disabled) rule when they are missing.
     *
     * @param array<string, string> $addIps address or network => comment
     * @param string[] $removeIps addresses or networks
     * @param int[] $asns every ASN that should be blocked
     * @return array{enabled: bool, changed: bool, created: bool} whether the rule is enabled in Cloudflare, whether anything
     *   changed, and whether the rule was just created (disabled: see createdRuleMessage())
     * @throws RuntimeException with a message fit for the user
     */
    public static function sync(array $addIps, array $removeIps, array $asns): array
    {
        self::requireConfigured();
        $ruleset = self::entrypoint();
        $rule = self::ruleIn($ruleset);
        $list = self::ensureList();
        $changed = false;

        if ($addIps || $removeIps) {
            $items = self::listItems($list['id']);
            $add = [];
            foreach ($addIps as $value => $comment) {
                $key = self::itemKey((string) $value);
                if (!isset($items[$key])) {
                    $add[$key] = ['ip' => (string) $value, 'comment' => mb_substr((string) $comment, 0, 100)];
                }
            }
            $remove = [];
            foreach ($removeIps as $value) {
                $key = self::itemKey((string) $value);
                if (isset($items[$key]) && !array_key_exists($value, $addIps)) {
                    $remove[] = ['id' => $items[$key]];
                }
            }
            $path = '/accounts/' . rawurlencode(self::accountId()) . '/rules/lists/' . rawurlencode($list['id']) . '/items';
            if ($add) {
                self::waitFor(self::api('POST', $path, array_values($add))['result']['operation_id'] ?? null);
                $changed = true;
            }
            if ($remove) {
                self::waitFor(self::api('DELETE', $path, ['items' => $remove])['result']['operation_id'] ?? null);
                $changed = true;
            }
        }

        $expression = self::expression($asns);
        if ($rule === null) {
            self::createRule($ruleset['id'], $expression);
            return ['enabled' => false, 'changed' => true, 'created' => true];
        }
        if (trim((string) ($rule['expression'] ?? '')) !== $expression || ($rule['action'] ?? '') !== 'block') {
            $body = array_intersect_key($rule, array_flip(self::RULE_FIELDS));
            $body['expression'] = $expression;
            $body['action'] = 'block';
            unset($body['action_parameters']);
            self::call('PATCH', '/zones/' . self::zone() . '/rulesets/' . rawurlencode($ruleset['id']) . '/rules/' . rawurlencode($rule['id']), $body);
            $changed = true;
        }
        return ['enabled' => (bool) ($rule['enabled'] ?? true), 'changed' => $changed, 'created' => false];
    }

    /** Adds the rule, disabled, at the end of the zone's custom rules (creating the ruleset when the zone has none) */
    private static function createRule(?string $rulesetId, string $expression): void
    {
        $rule = [
            'description' => self::RULE_NAME,
            'expression' => $expression,
            'action' => 'block',
            'enabled' => false,
        ];
        if ($rulesetId === null) {
            self::call('PUT', '/zones/' . self::zone() . '/rulesets/phases/' . self::PHASE . '/entrypoint', ['rules' => [$rule]]);
        } else {
            self::call('POST', '/zones/' . self::zone() . '/rulesets/' . rawurlencode($rulesetId) . '/rules', $rule);
        }
    }

    /**
     * Read-only check of the setup.
     * @return array{rule: ?array, list: ?array, items: int} the rule and the list (null = created on first use), and its size
     * @throws RuntimeException
     */
    public static function check(): array
    {
        self::requireConfigured();
        $rule = self::ruleIn(self::entrypoint());
        $list = self::findList();
        return ['rule' => $rule, 'list' => $list, 'items' => $list ? count(self::listItems($list['id'])) : 0];
    }

    /**
     * Takes Greywarden's conditions out of another rule (the one "Add to Cloudflare" used
     * before the IP list). The rule is left alone when nothing else would remain.
     *
     * @param string[] $clauses
     * @return array{found: bool, removed: int, empty: bool, manual: string[]} manual = conditions that could not be taken out
     * @throws RuntimeException
     */
    public static function stripRule(string $name, array $clauses): array
    {
        self::requireConfigured();
        if (trim($name) === self::RULE_NAME) {
            throw new RuntimeException(sprintf('"%s" is the rule Greywarden now manages; give the old rule\'s name.', self::RULE_NAME));
        }
        $ruleset = self::entrypoint();
        $matches = array_values(array_filter($ruleset['rules'] ?? [], static fn(array $r) => trim($r['description'] ?? '') === trim($name)));
        if (!$matches) {
            return ['found' => false, 'removed' => 0, 'empty' => false, 'manual' => []];
        }
        $rule = $matches[0];
        $expression = trim((string) $rule['expression']);
        $out = ['found' => true, 'removed' => 0, 'empty' => false, 'manual' => []];
        foreach (array_unique($clauses) as $clause) {
            if (!str_contains($expression, $clause)) {
                continue;
            }
            if ($expression === $clause) {
                $out['empty'] = true;
                $out['removed']++;
                continue;
            }
            try {
                $expression = self::without($expression, $clause);
                $out['removed']++;
            } catch (RuntimeException) {
                $out['manual'][] = $clause;
            }
        }
        if (!$out['empty'] && $out['removed'] > 0) {
            $body = array_intersect_key($rule, array_flip(self::RULE_FIELDS));
            $body['expression'] = $expression;
            self::call('PATCH', '/zones/' . self::zone() . '/rulesets/' . rawurlencode($ruleset['id']) . '/rules/' . rawurlencode($rule['id']), $body);
        }
        return $out;
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
            throw new RuntimeException('It is the only condition in the rule, and a rule cannot be empty. Edit or delete the rule in Cloudflare instead.');
        }
        if (str_contains($expression, $clause)) {
            throw new RuntimeException(sprintf('%s is not an OR-ed condition in the rule (it was edited by hand), so it cannot be removed automatically. Remove it in Cloudflare.', $clause));
        }
        return $expression;
    }

    /** List items are compared in normal form: compressed lower-case addresses, networks as network/prefix */
    public static function itemKey(string $value): string
    {
        if (str_contains($value, '/')) {
            $range = IpAddress::parseCidr($value);
            $single = $range && $range['prefix'] === ($range['version'] === 4 ? 32 : 128);
            return $range ? ($single ? $range['network'] : $range['cidr']) : strtolower($value);
        }
        return IpAddress::normalize($value) ?? strtolower($value);
    }

    private static function requireConfigured(): void
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('Cloudflare is not configured (set CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID in .env).');
        }
    }

    private static function zone(): string
    {
        return rawurlencode((string) gw_env('CLOUDFLARE_ZONE_ID'));
    }

    /** The zone's custom rules ruleset; a zone without any custom rules has none */
    private static function entrypoint(): array
    {
        try {
            return self::call('GET', '/zones/' . self::zone() . '/rulesets/phases/' . self::PHASE . '/entrypoint');
        } catch (RuntimeException $e) {
            if (preg_match('/\(10003\)|not found|could not find/i', $e->getMessage())) {
                return ['id' => null, 'rules' => []];
            }
            throw $e;
        }
    }

    /** The "Greywarden Blocklist" rule in a ruleset, or null when it is missing */
    private static function ruleIn(array $ruleset): ?array
    {
        $matches = array_values(array_filter($ruleset['rules'] ?? [], static fn(array $r) => trim($r['description'] ?? '') === self::RULE_NAME));
        if (!$matches) {
            return null;
        }
        if (count($matches) > 1) {
            throw new RuntimeException(sprintf('There are %d custom rules named "%s" in Cloudflare; delete all but one.', count($matches), self::RULE_NAME));
        }
        return $matches[0];
    }

    /** The account that owns the zone (CLOUDFLARE_ACCOUNT_ID, or read from the zone once a day) */
    public static function accountId(): string
    {
        if ($id = gw_env('CLOUDFLARE_ACCOUNT_ID')) {
            return (string) $id;
        }
        $key = ['cf-account', (string) gw_env('CLOUDFLARE_ZONE_ID')];
        if (($id = Yii::$app->cache->get($key)) !== false) {
            return $id;
        }
        try {
            $id = (string) (self::call('GET', '/zones/' . self::zone())['account']['id'] ?? '');
        } catch (RuntimeException $e) {
            $id = '';
        }
        if ($id === '') {
            throw new RuntimeException('Cannot tell which Cloudflare account owns the zone: set CLOUDFLARE_ACCOUNT_ID in .env, or give the token Zone › Zone › Read.');
        }
        Yii::$app->cache->set($key, $id, 86400);
        return $id;
    }

    private static function findList(): ?array
    {
        $lists = self::call('GET', '/accounts/' . rawurlencode(self::accountId()) . '/rules/lists');
        foreach ($lists as $list) {
            if (($list['name'] ?? '') === self::listName()) {
                if (($list['kind'] ?? 'ip') !== 'ip') {
                    throw new RuntimeException(sprintf('The Cloudflare list "%s" is not an IP list; rename it or set CLOUDFLARE_LIST_NAME.', self::listName()));
                }
                return $list;
            }
        }
        return null;
    }

    private static function ensureList(): array
    {
        return self::findList() ?? self::call('POST', '/accounts/' . rawurlencode(self::accountId()) . '/rules/lists', [
            'name' => self::listName(),
            'kind' => 'ip',
            'description' => 'Managed by Greywarden: addresses and networks on its blocklist. Edit them in Greywarden, not here.',
        ]);
    }

    /** @return array<string, string> item key => item id */
    private static function listItems(string $listId): array
    {
        $items = [];
        $cursor = null;
        $page = 0;
        do {
            $data = self::api('GET', '/accounts/' . rawurlencode(self::accountId()) . '/rules/lists/' . rawurlencode($listId) . '/items?per_page=500'
                . ($cursor !== null ? '&cursor=' . rawurlencode($cursor) : ''));
            foreach ((array) ($data['result'] ?? []) as $item) {
                if (isset($item['ip'], $item['id'])) {
                    $items[self::itemKey((string) $item['ip'])] = (string) $item['id'];
                }
            }
            $cursor = $data['result_info']['cursors']['after'] ?? null;
        } while ($cursor !== null && $cursor !== '' && ++$page < self::MAX_PAGES);
        return $items;
    }

    /** Waits for a list bulk operation to finish */
    private static function waitFor(?string $operationId): void
    {
        if ($operationId === null || $operationId === '') {
            return;
        }
        $path = '/accounts/' . rawurlencode(self::accountId()) . '/rules/lists/bulk_operations/' . rawurlencode($operationId);
        for ($i = 0; $i < self::BULK_POLLS; $i++) {
            $op = self::call('GET', $path);
            if (($op['status'] ?? '') === 'completed') {
                return;
            }
            if (($op['status'] ?? '') === 'failed') {
                throw new RuntimeException('Cloudflare: the list update failed: ' . ($op['error'] ?? 'no reason given') . '.');
            }
            if (self::$pollDelay > 0) {
                usleep(self::$pollDelay);
            }
        }
        throw new RuntimeException('Cloudflare is still applying the list update; check again in a minute.');
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
