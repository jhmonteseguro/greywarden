<?php

namespace app\modules\intel\components\edge;

use app\models\Setting;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\IpAddress;

/**
 * Edge monitoring settings (Admin › Settings › Edge monitoring), with their defaults:
 *
 *   edge.ignore_paths  path prefixes your sites really serve (e.g. /wp-admin on a WordPress site)
 *   edge.allowlist     addresses, networks and ASNs never flagged (your office, uptime monitors)
 *   edge.min_score     lowest score that opens a finding
 *   edge.block_days    default length of a block from a finding
 *   edge.volume_rpm    requests per minute from one address that count as a flood
 *
 * and, from .env, EDGE_PULL_INTERVAL: minutes between the scheduler container's pulls.
 */
final class EdgeSettings
{
    public const DEFAULTS = [
        'ignore_paths' => '',
        'allowlist' => '',
        'min_score' => '30',
        'block_days' => '7',
        'volume_rpm' => '120',
    ];

    public static function settingName(string $key): string
    {
        return 'edge.' . $key;
    }

    public static function get(string $key): string
    {
        $value = Setting::get(self::settingName($key));
        return $value === null || $value === '' ? self::DEFAULTS[$key] : $value;
    }

    public static function minScore(): int
    {
        return max(1, min(99, (int) self::get('min_score')));
    }

    public static function blockDays(): int
    {
        return max(1, min(365, (int) self::get('block_days')));
    }

    public static function volumeRpm(): int
    {
        return max(1, (int) self::get('volume_rpm'));
    }

    /** Minutes between the scheduler's edge pulls (EDGE_PULL_INTERVAL, default 15, 0 = off, at most a day) */
    public static function pullInterval(): int
    {
        $value = gw_env('EDGE_PULL_INTERVAL');
        return $value === null || $value === false || $value === '' ? 15 : max(0, min(1440, (int) $value));
    }

    /** @return string[] lines of a list setting, trimmed, without blanks and # comments */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** @return string[] lower-case path prefixes, each starting with "/" */
    public static function ignoredPaths(): array
    {
        return array_map(static fn(string $p) => '/' . ltrim(strtolower($p), '/'), self::lines(self::get('ignore_paths')));
    }

    public static function isIgnoredPath(string $path, array $prefixes): bool
    {
        $lower = strtolower($path);
        foreach ($prefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parsed allowlist.
     * @return array{ips: string[], cidrs: string[], asns: int[], invalid: string[]}
     */
    public static function allowlist(?string $text = null): array
    {
        $out = ['ips' => [], 'cidrs' => [], 'asns' => [], 'invalid' => []];
        foreach (self::lines($text ?? self::get('allowlist')) as $line) {
            if (str_contains($line, '/') && ($range = IpAddress::parseCidr($line))) {
                $out['cidrs'][] = $range['cidr'];
            } elseif ($indicator = Indicator::parse($line)) {
                $indicator->isIp() ? $out['ips'][] = $indicator->value : $out['asns'][] = $indicator->asn;
            } else {
                $out['invalid'][] = $line;
            }
        }
        return $out;
    }

    public static function isAllowlisted(string $ip, ?int $asn, ?array $allowlist = null): bool
    {
        $allowlist ??= self::allowlist();
        if (in_array($ip, $allowlist['ips'], true) || ($asn !== null && in_array($asn, $allowlist['asns'], true))) {
            return true;
        }
        foreach ($allowlist['cidrs'] as $cidr) {
            if (IpAddress::inCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }
}
