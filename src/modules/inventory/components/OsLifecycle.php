<?php

namespace app\modules\inventory\components;

use DateTimeImmutable;
use Yii;

/**
 * Operating system end-of-support lookup from a device's free-text OS and version.
 *
 * A built-in catalogue of vendor end-of-support dates (the end of security
 * updates, not of paid extended support such as Windows ESU or Ubuntu Pro)
 * for the common desktop, mobile and server platforms. Apple and Android
 * do not publish dates: theirs are estimated from the usual support window and
 * marked as such.
 *
 * Extra or corrected entries go in params['device.osEndOfSupport'] as
 * regex => 'Y-m-d', matched against the lower-cased "os version" before
 * the built-in catalogue, e.g. ['/^fortios 7\.0\b/' => '2024-09-30'].
 */
final class OsLifecycle
{
    public const SUPPORTED = 'supported';
    public const ENDING = 'ending';
    public const UNSUPPORTED = 'unsupported';
    public const UNKNOWN = 'unknown';

    /** Windows 11 feature updates: [Home/Pro, Enterprise/Education] */
    private const WINDOWS_11 = [
        '21h2' => ['2023-10-10', '2024-10-08'],
        '22h2' => ['2024-10-08', '2025-10-14'],
        '23h2' => ['2025-11-11', '2026-11-10'],
        '24h2' => ['2026-10-13', '2027-10-12'],
        '25h2' => ['2027-10-12', '2028-10-10'],
    ];

    /** Windows 10/11 long-term servicing channel releases */
    private const WINDOWS_LTSC = [
        '10:2015' => '2025-10-14', '10:2016' => '2026-10-13', '10:2019' => '2029-01-09', '10:2021' => '2027-01-12',
        '11:2024' => '2029-10-09',
    ];

    private const WINDOWS_LEGACY = [
        'xp' => '2014-04-08', 'vista' => '2017-04-11', '7' => '2020-01-14', '8' => '2016-01-12', '8.1' => '2023-01-10',
        '10' => '2025-10-14',
    ];

    private const WINDOWS_SERVER = [
        '2003' => '2015-07-14', '2008' => '2020-01-14', '2012' => '2023-10-10', '2016' => '2027-01-12',
        '2019' => '2029-01-09', '2022' => '2031-10-14', '2025' => '2034-11-14',
    ];

    /** macOS major => [end, estimated]: Apple patches the current release and the two before it */
    private const MACOS = [
        10 => ['2022-10-24', false], 11 => ['2023-09-26', false], 12 => ['2024-09-16', false], 13 => ['2025-09-15', false],
        14 => ['2026-09-15', true], 15 => ['2027-09-15', true], 26 => ['2028-09-15', true],
    ];

    private const MACOS_NAMES = [
        'catalina' => 10, 'big sur' => 11, 'monterey' => 12, 'ventura' => 13, 'sonoma' => 14, 'sequoia' => 15, 'tahoe' => 26,
    ];

    /** iOS / iPadOS major => [end, estimated]: the previous release keeps security fixes until the next one ships */
    private const IOS = [
        15 => ['2023-09-18', false], 16 => ['2024-09-16', false], 17 => ['2025-09-15', false],
        18 => ['2026-09-15', true], 26 => ['2027-09-15', true],
    ];

    /** Android major => [end, estimated]: dropped from the Android Security Bulletin; vendor support varies */
    private const ANDROID = [
        10 => ['2023-02-06', false], 11 => ['2024-02-05', false], 12 => ['2025-03-03', false],
        13 => ['2026-03-02', true], 14 => ['2027-03-01', true], 15 => ['2028-03-06', true], 16 => ['2029-03-05', true],
    ];

    /** Ubuntu release => end of standard support */
    private const UBUNTU = [
        '14.04' => '2019-04-30', '16.04' => '2021-04-30', '18.04' => '2023-05-31', '20.04' => '2025-05-29',
        '22.04' => '2027-06-01', '24.04' => '2029-05-31',
        '23.04' => '2024-01-25', '23.10' => '2024-07-11', '24.10' => '2025-07-10', '25.04' => '2026-01-15', '25.10' => '2026-07-09',
    ];

    /** Debian major => end of LTS */
    private const DEBIAN = [8 => '2020-06-30', 9 => '2022-06-30', 10 => '2024-06-30', 11 => '2026-08-31', 12 => '2028-06-30', 13 => '2030-06-30'];

    /** RHEL and its rebuilds (Rocky, Alma, Oracle) major => end of maintenance support */
    private const RHEL = [6 => '2020-11-30', 7 => '2024-06-30', 8 => '2029-05-31', 9 => '2032-05-31', 10 => '2035-05-31'];

    private const AMAZON_LINUX = ['1' => '2023-12-31', '2' => '2026-06-30', '2023' => '2029-06-30'];

    public static function warningDays(): int
    {
        return (int) (Yii::$app->params['device.osWarningDays'] ?? 90);
    }

    public static function stateLabels(): array
    {
        return [self::SUPPORTED => 'Supported', self::ENDING => 'Support ending', self::UNSUPPORTED => 'Unsupported', self::UNKNOWN => 'Unknown'];
    }

    /**
     * @return array{state:string, endDate:string|null, estimated:bool, days:int|null} days until (negative: since) the end of support
     */
    public static function evaluate(?string $os, ?string $version, ?string $today = null): array
    {
        $eos = self::endOfSupport($os, $version);
        if ($eos === null) {
            return ['state' => self::UNKNOWN, 'endDate' => null, 'estimated' => false, 'days' => null];
        }
        [$end, $estimated] = $eos;
        $days = (int) (new DateTimeImmutable($today ?? 'today'))->diff(new DateTimeImmutable($end))->format('%r%a');
        $state = $days < 0 ? self::UNSUPPORTED : ($days <= self::warningDays() ? self::ENDING : self::SUPPORTED);
        return ['state' => $state, 'endDate' => $end, 'estimated' => $estimated, 'days' => $days];
    }

    /** @return array{0:string, 1:bool}|null [end of support Y-m-d, estimated] */
    public static function endOfSupport(?string $os, ?string $version): ?array
    {
        $s = strtolower(trim(preg_replace('/\s+/', ' ', trim((string) $os) . ' ' . trim((string) $version))));
        if ($s === '') {
            return null;
        }
        foreach ((array) (Yii::$app->params['device.osEndOfSupport'] ?? []) as $pattern => $date) {
            if (@preg_match($pattern, $s)) {
                return [$date, false];
            }
        }
        return self::builtIn($s);
    }

    private static function builtIn(string $s): ?array
    {
        if (preg_match('/^(?:microsoft )?windows server (\d{4})/', $s, $m)) {
            return isset(self::WINDOWS_SERVER[$m[1]]) ? [self::WINDOWS_SERVER[$m[1]], false] : null;
        }
        if (preg_match('/^(?:microsoft )?windows (xp|vista|7|8\.1|8|10|11)\b/', $s, $m)) {
            if (preg_match('/\blts[bc]\b.*?(\d{4})|(\d{4}).*?\blts[bc]\b/', $s, $l)) {
                $end = self::WINDOWS_LTSC[($m[1] === '11' ? '11' : '10') . ':' . ($l[1] ?: $l[2])] ?? null;
                return $end ? [$end, false] : null;
            }
            if ($m[1] === '11') {
                if (!preg_match('/\b(2\dh[12])\b/', $s, $v) || !isset(self::WINDOWS_11[$v[1]])) {
                    return null; // the feature update decides
                }
                return [self::WINDOWS_11[$v[1]][preg_match('/\b(enterprise|education)\b/', $s) ? 1 : 0], false];
            }
            return [self::WINDOWS_LEGACY[$m[1]], false];
        }
        if (preg_match('/^(?:mac ?os|os x)(?: x)?\b(.*)$/', $s, $m)) {
            $major = self::major($m[1]);
            foreach (self::MACOS_NAMES as $name => $number) {
                if ($major === null && str_contains($m[1], $name)) {
                    $major = $number;
                }
            }
            return $major === null ? null : self::byMajor(self::MACOS, $major);
        }
        if (preg_match('/^(?:ios|ipados)\b(.*)$/', $s, $m)) {
            return ($major = self::major($m[1])) === null ? null : self::byMajor(self::IOS, $major);
        }
        if (preg_match('/^android\b(.*)$/', $s, $m)) {
            return ($major = self::major($m[1])) === null ? null : self::byMajor(self::ANDROID, $major);
        }
        if (preg_match('/^ubuntu\b.*?\b(\d{2}\.\d{2})\b/', $s, $m)) {
            return isset(self::UBUNTU[$m[1]]) ? [self::UBUNTU[$m[1]], false] : null;
        }
        if (preg_match('/^debian\b(.*)$/', $s, $m)) {
            return ($major = self::major($m[1])) !== null && isset(self::DEBIAN[$major]) ? [self::DEBIAN[$major], false] : null;
        }
        if (preg_match('/^centos stream (\d+)/', $s, $m)) {
            return match ($m[1]) { '8' => ['2024-05-31', false], '9' => ['2027-05-31', false], default => null };
        }
        if (preg_match('/^centos(?: linux)?\b(.*)$/', $s, $m)) {
            return match (self::major($m[1])) { 6 => ['2020-11-30', false], 7 => ['2024-06-30', false], 8 => ['2021-12-31', false], default => null };
        }
        if (preg_match('/^(?:red hat enterprise linux|rhel|rocky(?: linux)?|alma ?linux|oracle linux)\b(.*)$/', $s, $m)) {
            return ($major = self::major($m[1])) !== null && isset(self::RHEL[$major]) ? [self::RHEL[$major], false] : null;
        }
        if (preg_match('/^amazon linux (?:ami )?(\d+)/', $s, $m)) {
            return isset(self::AMAZON_LINUX[$m[1]]) ? [self::AMAZON_LINUX[$m[1]], false] : null;
        }
        return null;
    }

    /** Leading major version number in "14.6 Sonoma", "(10.15.7)", " 17" */
    private static function major(string $version): ?int
    {
        return preg_match('/(\d+)/', $version, $m) ? (int) $m[1] : null;
    }

    /** Releases older than the catalogue are unsupported too; newer ones are unknown */
    private static function byMajor(array $table, int $major): ?array
    {
        if (isset($table[$major])) {
            return $table[$major];
        }
        $oldest = min(array_keys($table));
        return $major < $oldest ? $table[$oldest] : null;
    }
}
