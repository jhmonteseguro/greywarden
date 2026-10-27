<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\SourceResult;

/**
 * Shodan InternetDB: open ports, hostnames, CPEs, tags and known CVEs seen by
 * Shodan's scanners (free, keyless, updated weekly).
 */
class InternetDbSource extends Source
{
    /** Shodan tag => [weight, tone, label, signal group] */
    public const TAGS = [
        'c2' => [75, 'critical', 'Shodan: command-and-control server', null],
        'malware' => [60, 'high', 'Shodan: hosting malware', null],
        'compromised' => [55, 'high', 'Shodan: compromised host', null],
        'tor' => [30, 'medium', 'Shodan: Tor node', 'tor'],
        'proxy' => [20, 'medium', 'Shodan: open proxy', 'proxy'],
        'vpn' => [10, 'low', 'Shodan: VPN endpoint', 'proxy'],
        'scanner' => [10, 'low', 'Shodan: scanner', 'scanner'],
        'honeypot' => [0, 'info', 'Shodan: likely a honeypot', null],
    ];

    public function key(): string { return 'internetdb'; }
    public function label(): string { return 'Shodan InternetDB'; }
    public function category(): string { return self::CAT_EXPOSURE; }
    public function description(): string { return 'Open ports, services (CPE), hostnames, tags and known vulnerabilities seen by Shodan.'; }
    public function homepage(): string { return 'https://internetdb.shodan.io/'; }
    public function supports(Indicator $indicator): bool { return $indicator->isIp(); }

    public function reportUrl(Indicator $indicator): ?string
    {
        return 'https://www.shodan.io/host/' . rawurlencode($indicator->value);
    }

    public function requests(Indicator $indicator, array $facts): array
    {
        return ['host' => ['url' => 'https://internetdb.shodan.io/' . rawurlencode($indicator->value)]];
    }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $response = $responses['host'] ?? null;
        if (($response['status'] ?? 0) === 404) {
            return SourceResult::clean('No open services observed');
        }
        $data = Http::json($response);
        if (!$data || ($response['status'] ?? 0) !== 200) {
            return SourceResult::error(Http::errorText($response));
        }
        $ports = array_map('intval', $data['ports'] ?? []);
        sort($ports);
        $vulns = $data['vulns'] ?? [];
        rsort($vulns, SORT_NATURAL);
        $tags = $data['tags'] ?? [];

        $result = SourceResult::info(sprintf('%d open port%s · %d CVE%s', count($ports), count($ports) === 1 ? '' : 's', count($vulns), count($vulns) === 1 ? '' : 's'));
        $result->row('Open ports', $ports ? implode(', ', $ports) : 'none')
            ->row('Hostnames', array_slice($data['hostnames'] ?? [], 0, 10))
            ->row('Tags', $tags)
            ->row('Software (CPE)', array_slice($data['cpes'] ?? [], 0, 12));
        if ($vulns) {
            $result->table('Known vulnerabilities', ['CVE'], array_map(static fn($v) => [$v], array_slice($vulns, 0, 60)));
        }
        $result->fact('ports', $ports)->fact('vulns', $vulns)->fact('tags', $tags)->fact('hostnames', $data['hostnames'] ?? []);

        foreach ($tags as $tag) {
            if (isset(self::TAGS[$tag])) {
                [$weight, $tone, $label, $group] = self::TAGS[$tag];
                $result->signal($weight, $tone, $label, null, $group);
                $result->status = $weight >= 20 ? SourceResult::HIT : $result->status;
            }
            match ($tag) {
                'tor' => $result->fact('is_tor', true),
                'vpn' => $result->fact('is_vpn', true),
                'proxy' => $result->fact('is_proxy', true),
                'cloud' => $result->fact('is_hosting', true),
                default => null,
            };
        }
        if ($vulns) {
            $result->signal(min(30, 10 + count($vulns)), 'medium', sprintf('Exposes %d known vulnerabilit%s', count($vulns), count($vulns) === 1 ? 'y' : 'ies'),
                'Vulnerable internet-facing services are frequently compromised and reused for attacks. ' . implode(', ', array_slice($vulns, 0, 5)));
        }
        return $result->rawJson($data);
    }
}
