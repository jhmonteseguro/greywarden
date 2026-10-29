<?php

namespace app\modules\intel\components\edge;

use app\modules\intel\components\IpAddress;

/**
 * Turns Cloudflare traffic rows into one profile per client IP and scores
 * each profile with explainable signals.
 *
 * Profile (also what a finding keeps as its evidence, merged across pulls):
 *   requests, stopped (blocked / challenged by Cloudflare), passed (challenges solved),
 *   asn, country, agents [agent => n], methods [method => n], peak_rpm,
 *   paths [path => [count, statuses [status => n], group]]   (errors and successful probes)
 *   payloads [request => [count, status, families]]
 *
 * Score (0-100): signals combine like independent probabilities,
 *   p = 1 - Π(1 - w/100), as in the threat-intel assessment.
 */
final class EdgeAnalyzer
{
    /** Kept per profile, so evidence stays small */
    public const MAX_PATHS = 300;
    public const MAX_AGENTS = 20;
    public const MAX_PAYLOADS = 50;

    /** Cloudflare stopped at least this share of an address's requests: nothing slipped through */
    public const HANDLED_SHARE = 0.9;

    public static function emptyProfile(): array
    {
        return ['requests' => 0, 'stopped' => 0, 'passed' => 0, 'asn' => null, 'country' => null, 'agents' => [], 'methods' => [], 'peak_rpm' => 0, 'paths' => [], 'payloads' => []];
    }

    /**
     * @param array $traffic CloudflareTraffic::fetch() result
     * @param int $minutes width of the window (for the request rate)
     * @param string[] $ignoredPaths path prefixes your sites really serve
     * @param array|null $allowlist EdgeSettings::allowlist()
     * @return array<string, array> ip => profile
     */
    public static function profiles(array $traffic, int $minutes, array $ignoredPaths = [], ?array $allowlist = null): array
    {
        $profiles = [];
        $get = static function (string $raw) use (&$profiles): ?string {
            $ip = IpAddress::normalize($raw);
            if ($ip === null) {
                return null;
            }
            $profiles[$ip] ??= self::emptyProfile();
            return $ip;
        };

        foreach ($traffic['ips'] ?? [] as $r) {
            if (!$ip = $get($r['ip'])) {
                continue;
            }
            $p = &$profiles[$ip];
            $p['requests'] += $r['count'];
            $p['asn'] ??= $r['asn'];
            $p['country'] ??= $r['country'];
            $p['agents'][$r['agent']] = ($p['agents'][$r['agent']] ?? 0) + $r['count'];
            $p['methods'][$r['method']] = ($p['methods'][$r['method']] ?? 0) + $r['count'];
            unset($p);
        }
        foreach (['errors', 'exposures', 'payloads'] as $set) {
            foreach ($traffic[$set] ?? [] as $r) {
                if (!$ip = $get($r['ip'])) {
                    continue;
                }
                $p = &$profiles[$ip];
                $request = $r['path'] . ($r['query'] !== '' ? (str_starts_with($r['query'], '?') ? '' : '?') . $r['query'] : '');
                if ($families = EdgeCatalogue::payloadFamilies($request)) {
                    $p['payloads'][$request] = [
                        'count' => ($p['payloads'][$request]['count'] ?? 0) + $r['count'],
                        'status' => $r['status'],
                        'families' => $families,
                    ];
                }
                if ($set !== 'payloads' && !EdgeSettings::isIgnoredPath($r['path'], $ignoredPaths)) {
                    $path = &$p['paths'][$r['path']];
                    $path ??= ['count' => 0, 'statuses' => [], 'group' => EdgeCatalogue::probeGroup($r['path'])];
                    $path['count'] += $r['count'];
                    $path['statuses'][$r['status']] = ($path['statuses'][$r['status']] ?? 0) + $r['count'];
                    unset($path);
                }
                unset($p);
            }
        }
        foreach ($traffic['firewall'] ?? [] as $r) {
            if (!$ip = $get($r['ip'])) {
                continue;
            }
            if (in_array($r['action'], CloudflareTraffic::STOPPING_ACTIONS, true)) {
                $profiles[$ip]['stopped'] += $r['count'];
            } elseif (in_array($r['action'], CloudflareTraffic::PASSED_ACTIONS, true)) {
                $profiles[$ip]['passed'] += $r['count'];
            }
        }

        foreach ($profiles as $ip => &$p) {
            if ($allowlist !== null && EdgeSettings::isAllowlisted($ip, $p['asn'], $allowlist)) {
                unset($profiles[$ip]);
                continue;
            }
            // Errors and payload rows also count as requests when the volume query missed them (page limit)
            $p['requests'] = max($p['requests'], array_sum(array_column($p['paths'], 'count')));
            $p['peak_rpm'] = (int) ceil(max(0, $p['requests'] - $p['stopped']) / max(1, $minutes));
            $p = self::trim($p);
        }
        unset($p);
        return $profiles;
    }

    /** Adds a newer profile to an older one (counts summed, peak rate kept) */
    public static function merge(array $old, array $new): array
    {
        $old += self::emptyProfile();
        $out = $old;
        foreach (['requests', 'stopped', 'passed'] as $key) {
            $out[$key] = (int) $old[$key] + (int) $new[$key];
        }
        $out['asn'] = $new['asn'] ?? $old['asn'];
        $out['country'] = $new['country'] ?? $old['country'];
        $out['peak_rpm'] = max((int) $old['peak_rpm'], (int) $new['peak_rpm']);
        foreach (['agents', 'methods'] as $key) {
            foreach ($new[$key] as $name => $count) {
                $out[$key][$name] = ($out[$key][$name] ?? 0) + $count;
            }
        }
        foreach ($new['paths'] as $path => $row) {
            $merged = $out['paths'][$path] ?? ['count' => 0, 'statuses' => [], 'group' => $row['group']];
            $merged['count'] += $row['count'];
            foreach ($row['statuses'] as $status => $count) {
                $merged['statuses'][$status] = ($merged['statuses'][$status] ?? 0) + $count;
            }
            $out['paths'][$path] = $merged;
        }
        foreach ($new['payloads'] as $request => $row) {
            $out['payloads'][$request] = ['count' => ($out['payloads'][$request]['count'] ?? 0) + $row['count']] + $row;
        }
        return self::trim($out);
    }

    /** Keep the biggest entries of each list */
    private static function trim(array $p): array
    {
        arsort($p['agents']);
        arsort($p['methods']);
        uasort($p['paths'], static fn($a, $b) => $b['count'] <=> $a['count']);
        uasort($p['payloads'], static fn($a, $b) => $b['count'] <=> $a['count']);
        // Probes are kept before ordinary 404s, whatever their count
        $probes = array_filter($p['paths'], static fn($r) => $r['group'] !== null);
        $p['paths'] = array_slice($probes + $p['paths'], 0, self::MAX_PATHS, true);
        $p['agents'] = array_slice($p['agents'], 0, self::MAX_AGENTS, true);
        $p['payloads'] = array_slice($p['payloads'], 0, self::MAX_PAYLOADS, true);
        return $p;
    }

    /** Nothing slipped through: Cloudflare blocked or challenged (almost) every request */
    public static function isHandled(array $profile): bool
    {
        return $profile['requests'] > 0 && $profile['stopped'] >= $profile['requests'] * self::HANDLED_SHARE;
    }

    /**
     * Scores a profile.
     *
     * @param array $context volume_rpm (int), block (?array: reference, cloudflare bool) when the address is on the blocklist
     * @return array{score: int, signals: array, headline: string, exposure: bool, error_paths: int, research: bool}
     *   signals: [key, weight, tone, label, short, detail], strongest first
     */
    public static function score(array $profile, array $context = []): array
    {
        $signals = [];
        $add = static function (string $key, int $weight, string $tone, string $short, string $label, string $detail) use (&$signals): void {
            $signals[] = compact('key', 'weight', 'tone', 'short', 'label', 'detail');
        };
        $reached = max(0, $profile['requests'] - $profile['stopped']);

        // Sensitive paths that answered 2xx
        $exposed = [];
        $probes = [];
        $errorPaths = 0;
        foreach ($profile['paths'] as $path => $row) {
            $ok = array_sum(array_filter($row['statuses'], static fn($n, $s) => $s >= 200 && $s < 300, ARRAY_FILTER_USE_BOTH));
            if ($ok > 0 && EdgeCatalogue::isExposureGroup($row['group'])) {
                $exposed[] = $path;
            }
            if ($row['group'] !== null) {
                $probes[$path] = $row['group'];
            }
            if (array_filter(array_keys($row['statuses']), static fn($s) => $s >= 400 && $s < 500)) {
                $errorPaths++;
            }
        }
        if ($exposed) {
            $add('exposure', 90, 'critical', 'Sensitive file served',
                sprintf('Sensitive path answered 200: %s', self::list($exposed)),
                'A path that should never be public (secrets, backups, debug pages, web shells) returned a success code to this address. Unless your site answers every path with 200, as single-page apps do, open it from outside to check that it is not really exposed.');
        }
        if ($probes) {
            $groups = array_unique(array_values($probes));
            $add('probe', min(60, 30 + 5 * (count($probes) - 1)), 'high', sprintf('Probed %d attack path%s', count($probes), count($probes) === 1 ? '' : 's'),
                sprintf('Probed for %d known attack path%s: %s', count($probes), count($probes) === 1 ? '' : 's', self::list(array_keys($probes))),
                'Requests for files scanners look for (' . implode(', ', array_map([EdgeCatalogue::class, 'groupLabel'], $groups)) . '). Add the prefixes your sites really serve to Admin › Settings › Edge monitoring to stop counting them.');
        }
        if ($profile['payloads']) {
            $families = array_values(array_unique(array_merge(...array_column($profile['payloads'], 'families'))));
            $succeeded = array_filter($profile['payloads'], static fn($r) => $r['status'] > 0 && $r['status'] < 400);
            $add('payload', $succeeded ? 60 : 45, 'high', 'Attack payloads',
                'Attack payloads in requests: ' . implode(', ', $families),
                sprintf('%d request%s carried %s. %s', count($profile['payloads']), count($profile['payloads']) === 1 ? '' : 's', implode(', ', $families),
                    $succeeded ? 'Some were answered with a success code, so check the application logs for that endpoint.' : 'All were rejected with an error.'));
        }

        $agentSignal = null;
        $research = false;
        foreach (array_keys($profile['agents']) as $agent) {
            $kind = EdgeCatalogue::agentKind((string) $agent);
            if ($kind === null) {
                continue;
            }
            $weight = ['scanner' => 55, 'library' => 15, 'empty' => 15, 'research' => 15][$kind['kind']];
            $research = $research || $kind['kind'] === 'research';
            if ($agentSignal === null || $weight > $agentSignal['weight']) {
                $agentSignal = ['weight' => $weight] + $kind;
            }
        }
        if ($agentSignal) {
            [$short, $label, $detail] = match ($agentSignal['kind']) {
                'scanner' => ['Scanner tool', sprintf('Scanner user agent: %s', $agentSignal['name']), 'The user agent belongs to an offensive scanning or exploitation tool.'],
                'research' => ['Survey scanner', sprintf('Internet survey scanner: %s', $agentSignal['name']), 'Research and attack-surface scanners index the whole internet. Mostly noise; blocking them only hides your exposure from their reports.'],
                'library' => ['Scripted client', sprintf('Scripted client: %s', $agentSignal['name']), 'An HTTP library or command-line tool rather than a browser. Normal for APIs and monitors, unusual for web pages.'],
                default => ['No user agent', 'No user agent sent', 'Browsers always send a user agent; scripts and scanners often do not.'],
            };
            $add('agent', $agentSignal['weight'], $agentSignal['kind'] === 'scanner' ? 'high' : 'medium', $short, $label, $detail);
        }

        if ($errorPaths >= 15) {
            $add('enumeration', $errorPaths >= 100 ? 55 : ($errorPaths >= 40 ? 40 : 25), 'medium', 'Path enumeration',
                sprintf('%d different paths returned 4xx errors', $errorPaths),
                'Many different missing or forbidden paths from one address is how directory brute-forcing and content discovery look.');
        }
        $unusual = array_values(array_intersect(array_keys($profile['methods']), EdgeCatalogue::UNUSUAL_METHODS));
        if ($unusual) {
            $add('methods', 20, 'medium', 'Unusual methods', 'Unusual HTTP methods: ' . implode(', ', $unusual), 'WebDAV and debugging methods are rarely used by real visitors and are a common scanner check.');
        }
        $rpm = (int) ($context['volume_rpm'] ?? EdgeSettings::DEFAULTS['volume_rpm']);
        if ($profile['peak_rpm'] >= $rpm) {
            $add('volume', $profile['peak_rpm'] >= 4 * $rpm ? 40 : 25, 'medium', 'High request rate',
                sprintf('Up to %d requests a minute got through', $profile['peak_rpm']),
                sprintf('Above the %d requests a minute set in Admin › Settings › Edge monitoring. A rate-limiting rule in Cloudflare handles floods better than blocking addresses one by one.', $rpm));
        }
        if (!empty($context['block']) && $context['block']['cloudflare'] && $reached > 0) {
            $add('rule-gap', 45, 'high', 'Got past the block',
                sprintf('Blocked by %s but %d request%s still reached the site', $context['block']['reference'], $reached, $reached === 1 ? '' : 's'),
                'The address is in the Cloudflare rule, yet requests were not stopped. Check that the rule is enabled and that no rule above it skips or allows this traffic.');
        }
        if ($profile['passed'] > 0 && $signals) {
            $add('challenge', 10, 'info', 'Passed a challenge', sprintf('Passed %d Cloudflare challenge%s', $profile['passed'], $profile['passed'] === 1 ? '' : 's'),
                'It solved a bot challenge and then behaved like a scanner, so bot detection alone did not stop it.');
        }

        usort($signals, static fn($a, $b) => $b['weight'] <=> $a['weight']);
        $p = 1.0;
        foreach ($signals as $s) {
            $p *= 1 - $s['weight'] / 100;
        }
        return [
            'score' => (int) round(100 * (1 - $p)),
            'signals' => $signals,
            'headline' => mb_substr(implode(' · ', array_column(array_slice($signals, 0, 3), 'short')), 0, 255),
            'exposure' => (bool) $exposed,
            'error_paths' => $errorPaths,
            'research' => $research,
        ];
    }

    /** high | medium | low, from the score, an exposure and the intel rating */
    public static function priority(int $score, bool $exposure, ?string $intelRating = null): string
    {
        if ($exposure || $score >= 70 || $intelRating === 'malicious') {
            return 'high';
        }
        return $score >= 40 || $intelRating === 'suspicious' ? 'medium' : 'low';
    }

    private static function list(array $items, int $max = 3): string
    {
        $shown = array_slice($items, 0, $max);
        return implode(', ', $shown) . (count($items) > $max ? sprintf(' and %d more', count($items) - $max) : '');
    }
}
