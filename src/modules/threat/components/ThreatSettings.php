<?php

namespace app\modules\threat\components;

/**
 * Threat Watch settings from .env: THREAT_PULL_INTERVAL and the optional NVD_API_KEY.
 */
final class ThreatSettings
{
    public const DEFAULT_INTERVAL = 15;

    /** Minutes between the scheduler's checks for sources that are due (default 15, 0 = off, at most a day) */
    public static function pullInterval(): int
    {
        $value = gw_env('THREAT_PULL_INTERVAL');
        return $value === null || $value === false || $value === '' ? self::DEFAULT_INTERVAL : max(0, min(1440, (int) $value));
    }

    /** Optional: raises the NVD rate limit, so CVSS scores arrive faster */
    public static function nvdApiKey(): ?string
    {
        $value = trim((string) gw_env('NVD_API_KEY'));
        return $value === '' ? null : $value;
    }
}
