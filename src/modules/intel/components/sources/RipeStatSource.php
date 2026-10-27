<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\IpAddress;
use app\modules\intel\components\SourceResult;

/**
 * RIPEstat Data API (global routing data from RIPE NCC, covers all RIRs).
 *  IP:  prefix-overview (announcing AS + holder), maxmind-geo-lite (location), abuse-contact-finder
 *  ASN: as-overview, announced-prefixes, asn-neighbours
 */
class RipeStatSource extends Source
{
    private const BASE = 'https://stat.ripe.net/data/';

    public function key(): string { return 'ripestat'; }
    public function label(): string { return 'RIPEstat'; }
    public function category(): string { return self::CAT_NETWORK; }
    public function description(): string { return 'BGP routing (announcing AS, prefix), GeoLite location, abuse contact; for ASNs: announced prefixes and peers.'; }
    public function homepage(): string { return 'https://stat.ripe.net/'; }
    public function supports(Indicator $indicator): bool { return true; }

    public function reportUrl(Indicator $indicator): ?string
    {
        return 'https://stat.ripe.net/app/' . rawurlencode($indicator->value);
    }

    private static function url(string $call, string $resource, array $extra = []): array
    {
        return ['url' => self::BASE . $call . '/data.json?' . http_build_query(['resource' => $resource, 'sourceapp' => 'greywarden'] + $extra), 'timeout' => 10];
    }

    public function requests(Indicator $indicator, array $facts): array
    {
        if ($indicator->isIp()) {
            return [
                'prefix' => self::url('prefix-overview', $indicator->value),
                'geo' => self::url('maxmind-geo-lite', $indicator->value),
                'abuse' => self::url('abuse-contact-finder', $indicator->value),
            ];
        }
        return [
            'overview' => self::url('as-overview', $indicator->value),
            'prefixes' => self::url('announced-prefixes', $indicator->value, ['min_peers_seeing' => 10]),
            'neighbours' => self::url('asn-neighbours', $indicator->value, ['lod' => 0]),
        ];
    }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $data = array_map(static fn($r) => Http::json($r)['data'] ?? null, $responses);
        if (!array_filter($data)) {
            return SourceResult::error(Http::errorText(reset($responses) ?: null));
        }
        return $indicator->isIp() ? $this->collectIp($data) : $this->collectAsn($data);
    }

    private function collectIp(array $data): SourceResult
    {
        $prefix = $data['prefix'] ?? [];
        $asns = $prefix['asns'] ?? [];
        $location = $data['geo']['located_resources'][0]['locations'][0] ?? null;
        $abuse = $data['abuse']['abuse_contacts'] ?? [];

        $announced = (bool) ($prefix['announced'] ?? false);
        $result = SourceResult::info($announced
            ? ($prefix['resource'] ?? '') . ' via ' . implode(', ', array_map(static fn($a) => 'AS' . $a['asn'], $asns))
            : 'Not announced in BGP');
        $result->row('Announced', $announced ? 'Yes' : 'No')
            ->row('Prefix', $prefix['resource'] ?? null)
            ->row('Announced by', array_map(static fn($a) => 'AS' . $a['asn'] . ' · ' . $a['holder'], $asns))
            ->row('Covering block', isset($prefix['block']) ? $prefix['block']['resource'] . ' · ' . $prefix['block']['desc'] : null)
            ->row('Authoritative RIR', strtoupper($data['abuse']['authoritative_rir'] ?? ''))
            ->row('Abuse contact', $abuse)
            ->row('Geo (GeoLite2)', $location ? trim(($location['city'] ?? '') . ', ' . ($location['country'] ?? ''), ', ') : null)
            ->row('Coordinates', $location && isset($location['latitude']) ? sprintf('%.3f, %.3f', $location['latitude'], $location['longitude']) : null);

        $result->fact('announced', $announced)
            ->fact('bgp_prefix', $prefix['resource'] ?? null)
            ->fact('asn', $asns[0]['asn'] ?? null)
            ->fact('as_name', $asns[0]['holder'] ?? null)
            ->fact('abuse_email', $abuse[0] ?? null);
        if ($location) {
            $result->fact('country', $location['country'] ?: null)
                ->fact('city', $location['city'] ?: null)
                ->fact('lat', $location['latitude'] ?? null)
                ->fact('lon', $location['longitude'] ?? null);
        }
        if (!$announced) {
            $result->signal(10, 'low', 'Address is not announced in global BGP', 'Unrouted space is often spoofed or hijacked.');
        }
        return $result->rawJson($data);
    }

    private function collectAsn(array $data): SourceResult
    {
        $overview = $data['overview'] ?? [];
        $prefixes = array_column($data['prefixes']['prefixes'] ?? [], 'prefix');
        $counts = $data['neighbours']['neighbour_counts'] ?? [];

        $v4 = $v6 = [];
        $space4 = 0.0;
        foreach ($prefixes as $p) {
            $range = IpAddress::parseCidr($p);
            if (!$range) {
                continue;
            }
            if ($range['version'] === 4) {
                $v4[] = $range;
            } else {
                $v6[] = $range;
            }
        }
        // Address space without double counting overlapping (more specific) announcements
        foreach (IpAddress::mergeRanges(array_map(static fn($r) => [$r['start'], $r['end']], $v4)) as [$start, $end]) {
            $space4 += hexdec(substr($end, -8)) - hexdec(substr($start, -8)) + 1;
        }

        $announced = (bool) ($overview['announced'] ?? false);
        $result = SourceResult::info(($overview['holder'] ?? 'AS') . ' · ' . count($v4) . ' IPv4 / ' . count($v6) . ' IPv6 prefixes');
        $result->row('Holder', $overview['holder'] ?? null)
            ->row('Announced', $announced ? 'Yes' : 'No')
            ->row('Block', isset($overview['block']) ? $overview['block']['desc'] . ' (' . $overview['block']['resource'] . ')' : null)
            ->row('IPv4 prefixes', (string) count($v4))
            ->row('IPv6 prefixes', (string) count($v6))
            ->row('IPv4 address space', number_format($space4) . ' addresses (≈ ' . number_format($space4 / 256, $space4 < 2560 ? 1 : 0) . ' /24s)')
            ->row('Neighbours', $counts ? sprintf('%d upstream · %d downstream · %d uncertain', $counts['left'] ?? 0, $counts['right'] ?? 0, $counts['uncertain'] ?? 0) : null);

        $result->fact('as_name', $overview['holder'] ?? null)
            ->fact('announced', $announced)
            ->fact('prefixes', $prefixes)
            ->fact('prefix_count_v4', count($v4))
            ->fact('prefix_count_v6', count($v6))
            ->fact('address_space_v4', $space4)
            ->fact('neighbours', $counts);
        if (!$announced) {
            $result->signal(5, 'info', 'AS is not currently announcing any prefixes');
        }
        // Raw prefixes list can be huge; keep it compact
        $raw = $data;
        if (isset($raw['prefixes']['prefixes'])) {
            $raw['prefixes']['prefixes'] = array_slice($prefixes, 0, 500);
        }
        unset($raw['neighbours']['neighbours']);
        return $result->rawJson($raw);
    }
}
