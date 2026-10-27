<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\SourceResult;

/**
 * ipinfo.io: geolocation, hostname, organisation / AS and anycast flag.
 * Works without a token (limited); IPINFO_TOKEN raises the limit.
 */
class IpInfoSource extends Source
{
    public function key(): string { return 'ipinfo'; }
    public function label(): string { return 'ipinfo.io'; }
    public function category(): string { return self::CAT_NETWORK; }
    public function description(): string { return 'Geolocation, hostname, organisation and AS, anycast detection.'; }
    public function homepage(): string { return 'https://ipinfo.io/'; }
    public function supports(Indicator $indicator): bool { return $indicator->isIp(); }
    public function terms(): ?string { return 'Free tier, rate limited without IPINFO_TOKEN.'; }

    public function reportUrl(Indicator $indicator): ?string
    {
        return 'https://ipinfo.io/' . rawurlencode($indicator->value);
    }

    public function requests(Indicator $indicator, array $facts): array
    {
        $token = gw_env('IPINFO_TOKEN');
        return ['ip' => ['url' => 'https://ipinfo.io/' . rawurlencode($indicator->value) . '/json' . ($token ? '?token=' . rawurlencode($token) : '')]];
    }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $response = $responses['ip'] ?? null;
        $data = Http::json($response);
        if (($response['status'] ?? 0) !== 200 || !$data || isset($data['error'])) {
            return SourceResult::error(isset($data['error']) ? (string) ($data['error']['message'] ?? $data['error']['title'] ?? 'Error') : Http::errorText($response));
        }
        unset($data['readme']);
        $asn = null;
        $org = $data['org'] ?? null;
        if ($org && preg_match('/^AS(\d+)\s+(.*)$/', $org, $m)) {
            $asn = (int) $m[1];
            $org = $m[2];
        }
        [$lat, $lon] = isset($data['loc']) ? array_map('floatval', explode(',', $data['loc']) + [1 => 0]) : [null, null];
        $place = implode(', ', array_filter([$data['city'] ?? null, $data['region'] ?? null, $data['country'] ?? null]));

        $result = SourceResult::info(trim($place . ' · ' . ($org ?? ''), ' ·'));
        $result->row('Location', $place)
            ->row('Coordinates', isset($data['loc']) ? $data['loc'] : null)
            ->row('Postal code', $data['postal'] ?? null)
            ->row('Time zone', $data['timezone'] ?? null)
            ->row('Hostname', $data['hostname'] ?? null)
            ->row('Organisation', $data['org'] ?? null)
            ->row('Anycast', !empty($data['anycast']) ? 'Yes – served from many locations' : null)
            ->row('Bogon', !empty($data['bogon']) ? 'Yes' : null);
        $result->fact('country', $data['country'] ?? null)
            ->fact('city', $data['city'] ?? null)
            ->fact('region', $data['region'] ?? null)
            ->fact('lat', $lat)
            ->fact('lon', $lon)
            ->fact('timezone', $data['timezone'] ?? null)
            ->fact('hostname', $data['hostname'] ?? null)
            ->fact('asn', $asn)
            ->fact('org', $org)
            ->fact('anycast', !empty($data['anycast']) ? true : null);
        return $result->rawJson($data);
    }
}
