<?php

namespace tests\support;

use app\modules\intel\components\Cloudflare;
use app\modules\intel\components\Http;

/**
 * An in-memory Cloudflare API for tests (installed as Http::$fake): the zone (in the
 * zone list, with its settings, DNS records and DNSSEC), its custom-rules entrypoint
 * ruleset, account IP lists with paged items and bulk operations, and an optional
 * GraphQL handler. Every request is recorded in $sent.
 */
final class FakeCloudflare
{
    public const ZONE = 'zone1';
    public const ACCOUNT = 'acc1';
    /** The owning account as the zone list reports it (the importer wants a real 32-hex account ID) */
    public const ZONE_ACCOUNT = '0123456789abcdef0123456789abcdef';

    /** @var array[]|null custom rules; null = the zone has no custom-rules ruleset yet */
    public ?array $rules;
    /** @var array<string, array> list name => [id, name, kind, items [id => [id, ip, comment]]] */
    public array $lists = [];
    /** @var array[] requests sent (graphql included) */
    public array $sent = [];
    /** Returned for every rulesets / lists / zone request instead of the fake (e.g. a 403) */
    public ?array $failWith = null;
    /** Returned for list item writes only */
    public ?array $failItemsWith = null;
    /** Bulk operation status reported: completed | failed | running */
    public string $opStatus = 'completed';
    /** Items per page when listing */
    public int $pageSize = 500;
    /** @var callable|null fn(array $decodedBody): array response for /graphql */
    public $graphql = null;

    // Zone configuration (GET /zones, settings, DNS records, DNSSEC) for the Cloudflare zone sync
    public bool $paused = false;
    /** @var array<string, mixed> zone setting id => value */
    public array $settings = [
        'ssl' => 'strict', 'min_tls_version' => '1.2', 'always_use_https' => 'on', 'security_level' => 'medium',
        'security_header' => ['strict_transport_security' => ['enabled' => true, 'max_age' => 31536000]],
    ];
    /** @var array[] DNS records [name, type, proxied, proxiable?] */
    public array $dnsRecords = [
        ['name' => 'example.com', 'type' => 'A', 'proxied' => true, 'proxiable' => true],
        ['name' => 'www.example.com', 'type' => 'CNAME', 'proxied' => true, 'proxiable' => true],
        ['name' => 'mail.example.com', 'type' => 'A', 'proxied' => false, 'proxiable' => true],
        ['name' => 'example.com', 'type' => 'MX', 'proxied' => false, 'proxiable' => false],
    ];
    public string $dnssec = 'active';
    /** @var array<string, array> path fragment (settings | dns_records | dnssec | zones) => response returned instead */
    public array $failZoneWith = [];
    private int $seq = 0;

    public function __construct(?array $rules = null, bool $withRule = true)
    {
        $this->rules = $rules ?? array_values(array_filter([
            ['id' => 'r-other', 'description' => 'Allow office', 'action' => 'skip', 'expression' => '(ip.src eq 192.0.2.250)', 'enabled' => true],
            $withRule ? ['id' => 'r-gw', 'description' => Cloudflare::RULE_NAME, 'action' => 'block', 'expression' => '(ip.src eq 192.0.2.1)', 'enabled' => true,
                'version' => '3', 'last_updated' => 'x', 'ref' => 'abc'] : null,
        ]));
    }

    public function install(): self
    {
        putenv('CLOUDFLARE_API_TOKEN=test-token');
        putenv('CLOUDFLARE_ZONE_ID=' . self::ZONE);
        Cloudflare::$pollDelay = 0;
        \Yii::$app->cache->delete(['cf-account', self::ZONE]);
        Http::$fake = fn(array $r): array => $this->handle($r);
        return $this;
    }

    public static function uninstall(): void
    {
        Http::$fake = null;
        \Yii::$app->cache->delete(['cf-account', self::ZONE]);
        Cloudflare::$pollDelay = 500000;
        foreach (['CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ZONE_ID', 'CLOUDFLARE_ACCOUNT_ID', 'CLOUDFLARE_LIST_NAME', 'CLOUDFLARE_RULE_NAME'] as $name) {
            putenv($name);
        }
    }

    /** The rule named $name, or null */
    public function rule(string $name = Cloudflare::RULE_NAME): ?array
    {
        foreach ($this->rules ?? [] as $rule) {
            if (($rule['description'] ?? '') === $name) {
                return $rule;
            }
        }
        return null;
    }

    /** @return string[] the IPs in a list, in insertion order */
    public function items(string $list = Cloudflare::DEFAULT_LIST): array
    {
        return array_values(array_map(static fn(array $i) => $i['ip'], $this->lists[$list]['items'] ?? []));
    }

    /** @return array[] requests matching a method and a path fragment */
    public function requests(string $method, string $path = ''): array
    {
        return array_values(array_filter($this->sent, static fn(array $r) => $r['method'] === $method && str_contains($r['url'], $path)));
    }

    private static function ok(mixed $result, array $extra = []): array
    {
        return ['body' => json_encode(['success' => true, 'errors' => [], 'result' => $result] + $extra)];
    }

    private static function error(int $status, int $code, string $message): array
    {
        return ['status' => $status, 'body' => json_encode(['success' => false, 'errors' => [['code' => $code, 'message' => $message]], 'result' => null])];
    }

    public function handle(array $r): array
    {
        $r['method'] = strtoupper($r['method'] ?? 'GET');
        $this->sent[] = $r;
        $path = substr($r['url'], strlen(Cloudflare::API));
        $body = $r['body'] !== null && $r['body'] !== '' ? json_decode($r['body'], true) : null;

        if ($path === '/graphql') {
            return $this->graphql ? ($this->graphql)($body) : ['status' => 404, 'body' => '{}'];
        }
        if ($this->failWith) {
            return $this->failWith;
        }
        $zone = '/zones/' . self::ZONE;
        $lists = '/accounts/' . self::ACCOUNT . '/rules/lists';
        $parts = parse_url($path);
        $route = $parts['path'];
        parse_str($parts['query'] ?? '', $query);

        foreach ($this->failZoneWith as $fragment => $response) {
            if ($r['method'] === 'GET' && ($route === "$zone/$fragment" || ($fragment === 'zones' && $route === '/zones'))) {
                return $response;
            }
        }
        if ($r['method'] === 'GET' && $route === '/zones') {
            return self::ok([['id' => self::ZONE, 'name' => 'example.com', 'status' => 'active', 'paused' => $this->paused,
                'account' => ['id' => self::ZONE_ACCOUNT, 'name' => 'Example'], 'created_on' => '2024-01-02T03:04:05Z']],
                ['result_info' => ['page' => 1, 'per_page' => 50, 'total_pages' => 1]]);
        }
        if ($r['method'] === 'GET' && $route === "$zone/settings") {
            return self::ok(array_map(static fn($id, $value) => ['id' => $id, 'value' => $value], array_keys($this->settings), $this->settings));
        }
        if ($r['method'] === 'GET' && $route === "$zone/dnssec") {
            return self::ok(['status' => $this->dnssec]);
        }
        if ($r['method'] === 'GET' && $route === "$zone/dns_records") {
            $perPage = (int) ($query['per_page'] ?? 100);
            $page = max(1, (int) ($query['page'] ?? 1));
            return self::ok(array_slice($this->dnsRecords, ($page - 1) * $perPage, $perPage),
                ['result_info' => ['page' => $page, 'per_page' => $perPage, 'total_pages' => max(1, (int) ceil(count($this->dnsRecords) / $perPage))]]);
        }
        if ($r['method'] === 'GET' && $route === $zone) {
            return self::ok(['id' => self::ZONE, 'name' => 'example.com', 'account' => ['id' => self::ACCOUNT, 'name' => 'Example']]);
        }
        if ($route === "$zone/rulesets/phases/" . Cloudflare::PHASE . '/entrypoint') {
            if ($r['method'] === 'PUT') {
                $this->rules = array_map(fn(array $rule) => ['id' => 'r' . ++$this->seq] + $rule, $body['rules']);
                return self::ok(['id' => 'rs1', 'rules' => $this->rules]);
            }
            return $this->rules === null
                ? self::error(404, 10003, 'could not find entrypoint ruleset in the http_request_firewall_custom phase')
                : self::ok(['id' => 'rs1', 'rules' => $this->rules]);
        }
        if ($r['method'] === 'POST' && $route === "$zone/rulesets/rs1/rules") {
            $this->rules[] = ['id' => 'r' . ++$this->seq] + $body;
            return self::ok(['id' => 'rs1', 'rules' => $this->rules]);
        }
        if ($r['method'] === 'PATCH' && preg_match("#^$zone/rulesets/rs1/rules/([\\w-]+)$#", $route, $m)) {
            foreach ($this->rules as &$rule) {
                if ($rule['id'] === $m[1]) {
                    $rule = ['id' => $rule['id']] + $body;
                }
            }
            unset($rule);
            return self::ok(['id' => 'rs1', 'rules' => $this->rules]);
        }

        if ($route === $lists && $r['method'] === 'GET') {
            return self::ok(array_values(array_map(static fn(array $l) => array_diff_key($l, ['items' => 1]) + ['num_items' => count($l['items'])], $this->lists)));
        }
        if ($route === $lists && $r['method'] === 'POST') {
            $this->lists[$body['name']] = ['id' => 'list' . ++$this->seq, 'name' => $body['name'], 'kind' => $body['kind'], 'description' => $body['description'] ?? '', 'items' => []];
            return self::ok(array_diff_key($this->lists[$body['name']], ['items' => 1]));
        }
        if (preg_match("#^$lists/bulk_operations/([\\w-]+)$#", $route, $m)) {
            return self::ok(['id' => $m[1], 'status' => $this->opStatus] + ($this->opStatus === 'failed' ? ['error' => 'invalid item'] : []));
        }
        if (preg_match("#^$lists/([\\w-]+)/items$#", $route, $m)) {
            $name = null;
            foreach ($this->lists as $n => $l) {
                if ($l['id'] === $m[1]) {
                    $name = $n;
                }
            }
            if ($name === null) {
                return self::error(404, 10000, 'list not found');
            }
            $list = &$this->lists[$name];
            if ($r['method'] === 'GET') {
                $offset = (int) ($query['cursor'] ?? 0);
                $page = array_slice(array_values($list['items']), $offset, $this->pageSize);
                $next = $offset + $this->pageSize < count($list['items']) ? (string) ($offset + $this->pageSize) : null;
                return self::ok($page, ['result_info' => ['cursors' => array_filter(['after' => $next])]]);
            }
            if ($this->failItemsWith) {
                return $this->failItemsWith;
            }
            if ($this->opStatus === 'failed') {
                // A failed bulk operation changes nothing
            } elseif ($r['method'] === 'POST') {
                foreach ($body as $item) {
                    $id = 'item' . ++$this->seq;
                    $list['items'][$id] = ['id' => $id] + $item;
                }
            } elseif ($r['method'] === 'DELETE') {
                foreach ($body['items'] as $item) {
                    unset($list['items'][$item['id']]);
                }
            }
            return self::ok(['operation_id' => 'op' . ++$this->seq]);
        }
        return self::error(404, 7003, 'Could not route to ' . $route);
    }
}
