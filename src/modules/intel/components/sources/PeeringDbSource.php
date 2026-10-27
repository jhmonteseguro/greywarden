<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\SourceResult;

/**
 * PeeringDB: self-declared network profile of an AS (type, traffic, scope,
 * peering policy). For an IP it profiles the origin AS (phase 2).
 */
class PeeringDbSource extends Source
{
    /** PeeringDB info_type => normalised network type */
    public const TYPES = [
        'NSP' => 'transit',
        'Content' => 'content',
        'Cable/DSL/ISP' => 'isp',
        'Enterprise' => 'enterprise',
        'Educational/Research' => 'education',
        'Non-Profit' => 'enterprise',
        'Government' => 'government',
        'Route Server' => 'transit',
        'Network Services' => 'hosting',
    ];

    public function key(): string { return 'peeringdb'; }
    public function label(): string { return 'PeeringDB'; }
    public function category(): string { return self::CAT_NETWORK; }
    public function description(): string { return 'Network type, traffic profile, scope and peering policy declared by the AS operator.'; }
    public function homepage(): string { return 'https://www.peeringdb.com/'; }
    public function supports(Indicator $indicator): bool { return true; }
    public function ttl(): int { return 24 * 3600; }

    public function phase(Indicator $indicator): int
    {
        return $indicator->isIp() ? 2 : 1;
    }

    private static function asn(Indicator $indicator, array $facts): ?int
    {
        return $indicator->isAsn() ? $indicator->asn : (isset($facts['asn']) ? (int) $facts['asn'] : null);
    }

    public function reportUrl(Indicator $indicator): ?string
    {
        return $indicator->isAsn() ? 'https://www.peeringdb.com/asn/' . $indicator->asn : null;
    }

    public function requests(Indicator $indicator, array $facts): array
    {
        $asn = self::asn($indicator, $facts);
        return $asn ? ['net' => ['url' => 'https://www.peeringdb.com/api/net?asn=' . $asn]] : [];
    }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $asn = self::asn($indicator, $facts);
        if (!$asn) {
            return SourceResult::skipped('Origin AS unknown');
        }
        $response = $responses['net'] ?? null;
        $data = Http::json($response);
        if (($response['status'] ?? 0) !== 200 || $data === null) {
            return SourceResult::error(Http::errorText($response));
        }
        $net = $data['data'][0] ?? null;
        if (!$net) {
            return SourceResult::info('AS' . $asn . ' has no PeeringDB profile')->row('Profile', 'None (common for small, enterprise or short-lived networks)');
        }
        $types = $net['info_types'] ?? array_filter([$net['info_type'] ?? null]);
        $result = SourceResult::info(($net['name'] ?? 'AS' . $asn) . ($types ? ' · ' . implode(', ', $types) : ''));
        $result->row($indicator->isIp() ? 'Origin AS' : 'AS', 'AS' . $asn)
            ->row('Name', $net['name'] ?? null)
            ->row('Also known as', $net['aka'] ?? null)
            ->row('Website', $net['website'] ?? null)
            ->row('Network type', implode(', ', $types))
            ->row('Traffic', $net['info_traffic'] ?? null)
            ->row('Traffic ratio', $net['info_ratio'] ?? null)
            ->row('Scope', $net['info_scope'] ?? null)
            ->row('Peering policy', $net['policy_general'] ?? null)
            ->row('IRR as-set', $net['irr_as_set'] ?? null)
            ->row('Max prefixes', isset($net['info_prefixes4']) ? sprintf('%s IPv4 / %s IPv6', number_format((int) $net['info_prefixes4']), number_format((int) ($net['info_prefixes6'] ?? 0))) : null)
            ->row('Profile updated', isset($net['updated']) ? substr($net['updated'], 0, 10) : null);
        $type = null;
        foreach ($types as $t) {
            $type ??= self::TYPES[$t] ?? null;
        }
        $result->fact('as_network_type', $type)->fact('as_name', $net['name'] ?? null)->fact('website', $net['website'] ?? null);
        return $result->rawJson($net);
    }
}
