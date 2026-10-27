<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\SourceResult;

/**
 * ip-api.com: geolocation plus proxy / hosting / mobile flags.
 * Its free endpoint is for non-commercial use only, so it is opt-in: INTEL_ENABLE_IPAPI=1.
 */
class IpApiSource extends Source
{
    /** status, message, continent, country, countryCode, regionName, city, lat, lon, timezone, isp, org, as, asname, reverse, mobile, proxy, hosting */
    private const FIELDS = 66846719;

    public function key(): string { return 'ipapi'; }
    public function label(): string { return 'ip-api.com'; }
    public function category(): string { return self::CAT_NETWORK; }
    public function description(): string { return 'Geolocation, ISP and proxy / VPN / hosting / mobile detection.'; }
    public function homepage(): string { return 'https://ip-api.com/'; }
    public function supports(Indicator $indicator): bool { return $indicator->isIp(); }
    public function terms(): ?string { return 'Free endpoint is non-commercial use only – opt in with INTEL_ENABLE_IPAPI=1.'; }

    public function isEnabled(): bool
    {
        return gw_env_bool('INTEL_ENABLE_IPAPI', false) && parent::isEnabled();
    }

    public function requests(Indicator $indicator, array $facts): array
    {
        // The free endpoint is HTTP only
        return ['ip' => ['url' => 'http://ip-api.com/json/' . rawurlencode($indicator->value) . '?fields=' . self::FIELDS]];
    }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $response = $responses['ip'] ?? null;
        $data = Http::json($response);
        if (!$data || ($data['status'] ?? '') !== 'success') {
            return SourceResult::error($data['message'] ?? Http::errorText($response));
        }
        $flags = array_keys(array_filter(['Proxy / VPN / Tor' => $data['proxy'] ?? false, 'Hosting' => $data['hosting'] ?? false, 'Mobile' => $data['mobile'] ?? false]));
        $result = SourceResult::info(trim(($data['city'] ?? '') . ', ' . ($data['countryCode'] ?? '') . ' · ' . ($data['isp'] ?? ''), ', ·'));
        $result->row('Location', implode(', ', array_filter([$data['city'] ?? null, $data['regionName'] ?? null, $data['country'] ?? null])))
            ->row('ISP', $data['isp'] ?? null)
            ->row('Organisation', $data['org'] ?? null)
            ->row('AS', $data['as'] ?? null)
            ->row('Reverse DNS', $data['reverse'] ?? null)
            ->row('Flags', $flags ? implode(', ', $flags) : 'none');
        $result->fact('country', $data['countryCode'] ?? null)
            ->fact('city', $data['city'] ?? null)
            ->fact('lat', $data['lat'] ?? null)
            ->fact('lon', $data['lon'] ?? null)
            ->fact('is_proxy', !empty($data['proxy']) ? true : null)
            ->fact('is_hosting', !empty($data['hosting']) ? true : null)
            ->fact('network_type', !empty($data['mobile']) ? 'mobile' : (!empty($data['hosting']) ? 'hosting' : null));
        if (!empty($data['proxy'])) {
            $result->signal(25, 'medium', 'ip-api: proxy, VPN or Tor exit', null, 'proxy');
            $result->status = SourceResult::HIT;
        }
        return $result->rawJson($data);
    }
}
