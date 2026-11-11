<?php

namespace app\modules\cloud\components;

/**
 * Reads the Cloudflare zone configuration CloudflareZones collects: readable
 * labels, the findings (settings that weaken the edge: records that skip
 * the proxy, a paused zone, weak SSL/TLS, the Greywarden Blocklist rule
 * switched off) and the changes between two syncs, which go into the zone's
 * History as "Configuration changed".
 *
 * Properties (a setting the token could not read is left out, and listed
 * in not_read; a rule never fires on a missing setting):
 *
 *   paused            bool, the zone is paused (Cloudflare passes everything straight through)
 *   ssl               off | flexible | full | strict
 *   min_tls           "1.0" .. "1.3"
 *   always_use_https  bool
 *   hsts              bool
 *   security_level    Cloudflare's security level
 *   dnssec            active | pending | disabled | ...
 *   blocklist_rule    enabled | disabled | missing (only the CLOUDFLARE_ZONE_ID zone, once the blocklist is in use)
 *   dns_records       int, every record in the zone
 *   proxied           list of "name (TYPE)" records Cloudflare proxies
 *   dns_only          list of "name (TYPE)" records that could be proxied but are DNS only
 *   not_read          list of the parts the token could not read (see NOT_READ)
 */
final class CloudflareConfig
{
    public const TYPE = 'zone';

    /** part => [label, permission the token needs] */
    public const NOT_READ = [
        'settings' => ['zone settings (SSL/TLS, HTTPS, HSTS)', 'Zone › Zone Settings › Read'],
        'dns' => ['DNS records', 'Zone › DNS › Read'],
        'dnssec' => ['DNSSEC', 'Zone › DNS › Read'],
        'rules' => ['custom WAF rules', 'Zone › Zone WAF › Read'],
    ];

    /** property => label, in the order they are shown */
    private const LABELS = [
        'paused' => 'Paused',
        'ssl' => 'SSL/TLS encryption mode',
        'min_tls' => 'Minimum TLS version',
        'always_use_https' => 'Always Use HTTPS',
        'hsts' => 'HSTS',
        'security_level' => 'Security level',
        'dnssec' => 'DNSSEC',
        'blocklist_rule' => 'Greywarden Blocklist rule',
        'dns_records' => 'DNS records',
        'proxied' => 'Proxied records',
        'dns_only' => 'DNS-only records (could be proxied)',
        'not_read' => 'Not read (token permissions)',
    ];

    private const SSL_LABELS = ['off' => 'Off', 'flexible' => 'Flexible', 'full' => 'Full', 'strict' => 'Full (strict)'];

    /** Shown in the History for a record's proxy status */
    public const PROXIED = 'Proxied';
    public const DNS_ONLY = 'DNS only';

    public static function typeLabel(?string $type): ?string
    {
        return $type === self::TYPE ? 'Cloudflare zone' : ($type !== null && $type !== '' ? $type : null);
    }

    /** @return list<array{0:string, 1:string}> [label, value] */
    public static function facts(array $p): array
    {
        $facts = [];
        foreach (array_keys(array_intersect_key(self::LABELS, $p) + $p) as $key) {
            $facts[] = [self::LABELS[$key] ?? ucfirst(str_replace('_', ' ', (string) $key)), self::format((string) $key, $p[$key])];
        }
        return $facts;
    }

    private static function format(string $key, mixed $value): string
    {
        return match (true) {
            $key === 'ssl' && is_string($value) => self::SSL_LABELS[$value] ?? $value,
            $key === 'not_read' && is_array($value) => implode('; ', array_map(static fn($part) => self::NOT_READ[$part][0] ?? (string) $part, $value)),
            $key === 'blocklist_rule' && is_string($value), $key === 'dnssec' && is_string($value) => ucfirst($value),
            is_bool($value) => $value ? ($key === 'paused' ? 'Yes' : 'On') : ($key === 'paused' ? 'No' : 'Off'),
            is_array($value) => $value ? implode(', ', array_map('strval', $value)) : 'None',
            $value === null => '',
            default => (string) $value,
        };
    }

    /**
     * @param string[] $dnsOnlyAllowed hostnames that are DNS only on purpose ("*.example.com" covers its subdomains)
     * @return list<array{severity:string, title:string}>
     */
    public static function findings(string $type, array $p, array $dnsOnlyAllowed = []): array
    {
        if ($type !== self::TYPE) {
            return [];
        }
        $out = [];
        $add = static function (string $severity, string $title) use (&$out) {
            $out[] = ['severity' => $severity, 'title' => $title];
        };

        if (($p['paused'] ?? null) === true) {
            $add('high', 'The zone is paused: Cloudflare passes all traffic straight to the origin');
        }
        foreach ((array) ($p['dns_only'] ?? []) as $record) {
            if (!self::isAllowed(self::hostname((string) $record), $dnsOnlyAllowed)) {
                $add('high', sprintf('%s is DNS only: its traffic skips Cloudflare (WAF, blocklist, DDoS protection)', $record));
            }
        }
        match ($p['blocklist_rule'] ?? null) {
            'disabled' => $add('high', 'The Greywarden Blocklist rule is disabled: blocked addresses get through'),
            'missing' => $add('high', 'The Greywarden Blocklist rule is missing: blocked addresses get through'),
            default => null,
        };
        match ($p['ssl'] ?? null) {
            'off' => $add('high', 'SSL/TLS is off: visitors cannot use HTTPS'),
            'flexible' => $add('high', 'SSL/TLS mode is Flexible: Cloudflare reaches the origin over plain HTTP'),
            'full' => $add('medium', 'SSL/TLS mode is Full, not Full (strict): the origin certificate is not checked'),
            default => null,
        };
        if (isset($p['min_tls']) && is_string($p['min_tls']) && version_compare($p['min_tls'], '1.2', '<')) {
            $add('medium', sprintf('Minimum TLS version is %s (1.2 or later recommended)', $p['min_tls']));
        }
        if (($p['always_use_https'] ?? null) === false) {
            $add('medium', 'Always Use HTTPS is off: plain HTTP requests are not redirected');
        }
        foreach ((array) ($p['not_read'] ?? []) as $part) {
            if (isset(self::NOT_READ[$part])) {
                $add('medium', sprintf('Could not read the %s: give the API token %s', self::NOT_READ[$part][0], self::NOT_READ[$part][1]));
            }
        }
        if (($p['hsts'] ?? null) === false) {
            $add('low', 'HSTS is off');
        }
        if (isset($p['dnssec']) && $p['dnssec'] !== 'active') {
            $add('low', sprintf('DNSSEC is not active (%s)', $p['dnssec']));
        }
        return $out;
    }

    /**
     * What changed between two syncs: setting label => [old, new]. A record's
     * proxy status is keyed by the record ("www.example.com (A)"), null when it
     * did not exist (or no longer does). Settings read on only one side say
     * nothing (the token's permissions changed, not the zone), so the first
     * sync records no changes.
     *
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function changes(string $type, array $old, array $new): array
    {
        if ($type !== self::TYPE || !$old || !$new) {
            return [];
        }
        $changes = [];
        foreach (array_keys(self::LABELS) as $key) {
            if (in_array($key, ['proxied', 'dns_only', 'not_read'], true) || !array_key_exists($key, $old) || !array_key_exists($key, $new)) {
                continue;
            }
            [$was, $now] = [self::format($key, $old[$key]), self::format($key, $new[$key])];
            if ($was !== $now) {
                $changes[self::LABELS[$key]] = [$was, $now];
            }
        }
        $recordsWere = self::recordStates($old);
        $recordsNow = self::recordStates($new);
        if ($recordsWere !== null && $recordsNow !== null) {
            $names = array_unique(array_merge(array_keys($recordsWere), array_keys($recordsNow)));
            sort($names);
            foreach ($names as $name) {
                $was = $recordsWere[$name] ?? null;
                $now = $recordsNow[$name] ?? null;
                if ($was !== $now) {
                    $changes[$name] = [$was, $now];
                }
            }
        }
        return $changes;
    }

    /** @return array<string, string>|null record => Proxied | DNS only, null when the records were not read */
    private static function recordStates(array $p): ?array
    {
        if (!array_key_exists('proxied', $p) && !array_key_exists('dns_only', $p)) {
            return null;
        }
        return array_fill_keys(array_map('strval', (array) ($p['proxied'] ?? [])), self::PROXIED)
            + array_fill_keys(array_map('strval', (array) ($p['dns_only'] ?? [])), self::DNS_ONLY);
    }

    /** "www.example.com (A)" => "www.example.com" */
    public static function hostname(string $record): string
    {
        return strtolower(trim(preg_replace('/\s*\([^)]*\)$/', '', $record)));
    }

    /** Whether a hostname is on the DNS-only list: an exact name, or "*.example.com" for any name under example.com */
    public static function isAllowed(string $hostname, array $allowed): bool
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        foreach ($allowed as $entry) {
            $entry = strtolower(rtrim(trim((string) $entry), '.'));
            if ($entry === $hostname || (str_starts_with($entry, '*.') && str_ends_with($hostname, substr($entry, 1)))) {
                return true;
            }
        }
        return false;
    }
}
