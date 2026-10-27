<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\SourceResult;

/**
 * VirusTotal: verdicts of ~90 security vendors for the address.
 * Free community API key (4 lookups/min, 500/day): VIRUSTOTAL_API_KEY.
 */
class VirusTotalSource extends Source
{
    public function key(): string { return 'virustotal'; }
    public function label(): string { return 'VirusTotal'; }
    public function category(): string { return self::CAT_REPUTATION; }
    public function description(): string { return 'Security vendor verdicts, community reputation and tags.'; }
    public function homepage(): string { return 'https://www.virustotal.com/'; }
    public function supports(Indicator $indicator): bool { return $indicator->isIp(); }
    public function apiKeyEnv(): ?string { return 'VIRUSTOTAL_API_KEY'; }
    public function terms(): ?string { return 'Free community key: 4 lookups/min, 500/day, non-commercial.'; }
    public function ttl(): int { return 12 * 3600; }

    public function reportUrl(Indicator $indicator): ?string
    {
        return 'https://www.virustotal.com/gui/ip-address/' . rawurlencode($indicator->value);
    }

    public function requests(Indicator $indicator, array $facts): array
    {
        return ['ip' => [
            'url' => 'https://www.virustotal.com/api/v3/ip_addresses/' . rawurlencode($indicator->value),
            'headers' => ['x-apikey: ' . $this->apiKey()],
        ]];
    }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $response = $responses['ip'] ?? null;
        $attr = Http::json($response)['data']['attributes'] ?? null;
        if (($response['status'] ?? 0) !== 200 || !$attr) {
            return SourceResult::error(Http::json($response)['error']['message'] ?? Http::errorText($response));
        }
        $stats = $attr['last_analysis_stats'] ?? [];
        $malicious = (int) ($stats['malicious'] ?? 0);
        $suspicious = (int) ($stats['suspicious'] ?? 0);
        $total = array_sum(array_map('intval', $stats));

        $result = $malicious + $suspicious > 0
            ? SourceResult::hit(sprintf('%d/%d vendors flag it', $malicious + $suspicious, $total))
            : SourceResult::clean(sprintf('0/%d vendors flag it', $total));
        $result->row('Malicious', (string) $malicious)
            ->row('Suspicious', (string) $suspicious)
            ->row('Harmless', (string) ($stats['harmless'] ?? 0))
            ->row('Undetected', (string) ($stats['undetected'] ?? 0))
            ->row('Community reputation', isset($attr['reputation']) ? (string) $attr['reputation'] : null)
            ->row('AS owner', $attr['as_owner'] ?? null)
            ->row('Tags', $attr['tags'] ?? [])
            ->row('Last analysis', isset($attr['last_analysis_date']) ? gmdate('Y-m-d H:i', (int) $attr['last_analysis_date']) . ' UTC' : null);
        $flagged = [];
        foreach ($attr['last_analysis_results'] ?? [] as $engine => $r) {
            if (in_array($r['category'] ?? '', ['malicious', 'suspicious'], true)) {
                $flagged[] = [$engine, $r['category'], $r['result'] ?? ''];
            }
        }
        $result->table('Vendors flagging the address', ['Vendor', 'Category', 'Result'], $flagged);
        if ($malicious > 0) {
            $weight = min(85, 25 + 10 * $malicious);
            $result->signal($weight, $weight >= 70 ? 'critical' : 'high', sprintf('VirusTotal: %d vendor%s flag it malicious', $malicious, $malicious === 1 ? '' : 's'),
                implode(', ', array_slice(array_column($flagged, 0), 0, 6)));
        } elseif ($suspicious > 0) {
            $result->signal(min(30, 10 * $suspicious), 'medium', sprintf('VirusTotal: %d vendor%s flag it suspicious', $suspicious, $suspicious === 1 ? '' : 's'));
        }
        $result->fact('vt_malicious', $malicious);
        unset($attr['last_analysis_results'], $attr['last_https_certificate']);
        return $result->rawJson($attr);
    }
}
