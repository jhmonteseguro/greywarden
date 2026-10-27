<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\SourceResult;

/**
 * abuse.ch ThreatFox (malware IOCs: C2s, payload hosts) and URLhaus (malware
 * distribution URLs on this host). Free Auth-Key from auth.abuse.ch: ABUSECH_AUTH_KEY.
 * (Feodo Tracker, also abuse.ch, is a keyless feed - see FeedCatalogue.)
 */
class AbuseChSource extends Source
{
    public function key(): string { return 'abusech'; }
    public function label(): string { return 'abuse.ch ThreatFox / URLhaus'; }
    public function category(): string { return self::CAT_REPUTATION; }
    public function description(): string { return 'Malware IOCs (botnet C2, payload delivery) and malware distribution URLs hosted on the address.'; }
    public function homepage(): string { return 'https://abuse.ch/'; }
    public function supports(Indicator $indicator): bool { return $indicator->isIp(); }
    public function apiKeyEnv(): ?string { return 'ABUSECH_AUTH_KEY'; }
    public function terms(): ?string { return 'Free Auth-Key from auth.abuse.ch (fair use).'; }

    public function reportUrl(Indicator $indicator): ?string
    {
        return 'https://threatfox.abuse.ch/browse.php?search=ioc%3A' . rawurlencode($indicator->value);
    }

    public function requests(Indicator $indicator, array $facts): array
    {
        $auth = 'Auth-Key: ' . $this->apiKey();
        return [
            'threatfox' => [
                'url' => 'https://threatfox-api.abuse.ch/api/v1/',
                'method' => 'POST',
                'headers' => [$auth, 'Content-Type: application/json'],
                'body' => json_encode(['query' => 'search_ioc', 'search_term' => $indicator->value, 'exact_match' => false]),
            ],
            'urlhaus' => [
                'url' => 'https://urlhaus-api.abuse.ch/v1/host/',
                'method' => 'POST',
                'headers' => [$auth, 'Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query(['host' => $indicator->value]),
            ],
        ];
    }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $tf = Http::json($responses['threatfox'] ?? null);
        $uh = Http::json($responses['urlhaus'] ?? null);
        if (!$tf && !$uh) {
            return SourceResult::error(Http::errorText($responses['threatfox'] ?? null));
        }
        $iocs = ($tf['query_status'] ?? '') === 'ok' && is_array($tf['data'] ?? null) ? $tf['data'] : [];
        $urls = ($uh['query_status'] ?? '') === 'ok' ? ($uh['urls'] ?? []) : [];
        $online = array_filter($urls, static fn($u) => ($u['url_status'] ?? '') === 'online');

        $parts = [];
        if ($iocs) {
            $parts[] = count($iocs) . ' ThreatFox IOC' . (count($iocs) === 1 ? '' : 's');
        }
        if ($urls) {
            $parts[] = count($urls) . ' URLhaus URL' . (count($urls) === 1 ? '' : 's');
        }
        $result = $parts ? SourceResult::hit(implode(' · ', $parts)) : SourceResult::clean('No IOCs or malware URLs');
        $result->row('ThreatFox', $tf ? ($iocs ? count($iocs) . ' IOC(s)' : 'No match') : 'Error: ' . Http::errorText($responses['threatfox'] ?? null))
            ->row('URLhaus', $uh ? ($urls ? sprintf('%d URL(s), %d online', count($urls), count($online)) : 'No match') : 'Error: ' . Http::errorText($responses['urlhaus'] ?? null));

        $result->table('ThreatFox IOCs', ['IOC', 'Threat', 'Malware', 'Confidence', 'Last seen'], array_map(static fn($i) => [
            $i['ioc'] ?? '', $i['threat_type_desc'] ?? $i['threat_type'] ?? '', $i['malware_printable'] ?? '', ($i['confidence_level'] ?? '') . '%', substr((string) ($i['last_seen'] ?? $i['first_seen'] ?? ''), 0, 10),
        ], array_slice($iocs, 0, 15)));
        $result->table('URLhaus URLs', ['URL', 'Status', 'Threat', 'Added'], array_map(static fn($u) => [
            $u['url'] ?? '', $u['url_status'] ?? '', $u['threat'] ?? '', substr($u['date_added'] ?? '', 0, 10),
        ], array_slice($urls, 0, 15)));

        if ($iocs) {
            $malware = array_values(array_unique(array_filter(array_column($iocs, 'malware_printable'))));
            $result->signal(80, 'critical', 'ThreatFox: malware IOC (' . ($iocs[0]['threat_type'] ?? 'ioc') . ')', $malware ? 'Malware: ' . implode(', ', array_slice($malware, 0, 4)) : null);
            $result->fact('malware_families', $malware);
        }
        if ($urls) {
            $result->signal($online ? 70 : 40, $online ? 'critical' : 'high', sprintf('URLhaus: %s malware distribution', $online ? 'active' : 'past'),
                sprintf('%d URL(s) on this host, %d still online.', count($urls), count($online)));
        }
        return $result->rawJson(['threatfox' => $tf, 'urlhaus' => $uh ? array_diff_key($uh, ['urls' => 1]) + ['urls' => array_slice($urls, 0, 25)] : null]);
    }
}
