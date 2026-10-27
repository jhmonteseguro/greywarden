<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\SourceResult;

/**
 * AlienVault OTX (LevelBlue Open Threat Exchange): community "pulses" that
 * reference the address, related malware families and whitelist validation.
 * Keyless for the general section; OTX_API_KEY is sent when set.
 */
class OtxSource extends Source
{
    public function key(): string { return 'otx'; }
    public function label(): string { return 'AlienVault OTX'; }
    public function category(): string { return self::CAT_REPUTATION; }
    public function description(): string { return 'Community threat pulses referencing the address, malware families and whitelist validation.'; }
    public function homepage(): string { return 'https://otx.alienvault.com/'; }
    public function supports(Indicator $indicator): bool { return $indicator->isIp(); }

    public function reportUrl(Indicator $indicator): ?string
    {
        return 'https://otx.alienvault.com/indicator/ip/' . rawurlencode($indicator->value);
    }

    public function requests(Indicator $indicator, array $facts): array
    {
        $key = gw_env('OTX_API_KEY');
        $type = $indicator->ipVersion() === 6 ? 'IPv6' : 'IPv4';
        return ['general' => [
            'url' => "https://otx.alienvault.com/api/v1/indicators/$type/" . rawurlencode($indicator->value) . '/general',
            'headers' => $key ? ['X-OTX-API-KEY: ' . $key] : [],
            'timeout' => 10,
        ]];
    }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $response = $responses['general'] ?? null;
        $data = Http::json($response);
        if (($response['status'] ?? 0) !== 200 || !$data) {
            return SourceResult::error(Http::errorText($response));
        }
        $pulses = $data['pulse_info']['pulses'] ?? [];
        $count = (int) ($data['pulse_info']['count'] ?? count($pulses));
        $related = $data['pulse_info']['related'] ?? [];
        $families = array_values(array_unique(array_merge($related['alienvault']['malware_families'] ?? [], $related['other']['malware_families'] ?? [])));
        $adversaries = array_values(array_unique(array_merge($related['alienvault']['adversary'] ?? [], $related['other']['adversary'] ?? [])));
        $whitelisted = array_values(array_filter($data['validation'] ?? [], static fn($v) => in_array($v['source'] ?? '', ['whitelist', 'majestic', 'alexa', 'akamai', 'false_positive'], true)
            || str_contains(strtolower($v['message'] ?? ''), 'whitelist')));
        $tags = [];
        foreach ($pulses as $p) {
            foreach ($p['tags'] ?? [] as $t) {
                $t = strtolower(trim($t));
                $tags[$t] = ($tags[$t] ?? 0) + 1;
            }
        }
        arsort($tags);

        $result = $count > 0 ? SourceResult::hit(sprintf('%d pulse%s', $count, $count === 1 ? '' : 's')) : SourceResult::clean('No pulses');
        $result->row('Pulses', (string) $count)
            ->row('Malware families', array_slice($families, 0, 12))
            ->row('Adversaries', array_slice($adversaries, 0, 8))
            ->row('Common tags', array_slice(array_keys($tags), 0, 15))
            ->row('Validation', array_map(static fn($v) => ($v['name'] ?? $v['source'] ?? '') . (isset($v['message']) ? ': ' . $v['message'] : ''), $data['validation'] ?? []))
            ->row('False positive reports', count($data['false_positive'] ?? []) ?: null);
        $result->table('Recent pulses', ['Pulse', 'Created', 'Tags'], array_map(static fn($p) => [
            $p['name'] ?? '', substr($p['created'] ?? '', 0, 10), implode(', ', array_slice($p['tags'] ?? [], 0, 5)),
        ], array_slice($pulses, 0, 10)));

        if ($whitelisted) {
            $result->signal(-40, 'success', 'OTX: on a whitelist', $whitelisted[0]['message'] ?? $whitelisted[0]['name'] ?? null);
            $result->status = SourceResult::CLEAN;
        } elseif ($count > 0) {
            $weight = min(45, 10 + 5 * min($count, 7));
            $result->signal($weight, $weight >= 35 ? 'high' : 'medium', sprintf('Referenced in %d OTX threat pulse%s', $count, $count === 1 ? '' : 's'),
                $families ? 'Malware: ' . implode(', ', array_slice($families, 0, 4)) : (array_key_first($tags) ? 'Tags: ' . implode(', ', array_slice(array_keys($tags), 0, 4)) : null));
        }
        $result->fact('otx_pulses', $count)->fact('malware_families', $families);
        // Pulses are large; keep the raw view readable
        unset($data['pulse_info']['pulses'], $data['sections']);
        return $result->rawJson($data);
    }
}
