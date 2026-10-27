<?php

namespace app\modules\inventory\components;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Parses a Google Workspace device export (Admin console > Devices > Mobile &
 * endpoints, "Download" as CSV) into Device attributes. Pure: no database.
 *
 * The export has no security-posture data (encryption, EDR, firewall,
 * patching), so those controls are left for an analyst to confirm.
 */
final class GoogleDeviceCsv
{
    /** CSV header => internal key. Matched case-insensitively on the start of the header ("Serial Number (mandatory)"). */
    private const COLUMNS = [
        'device name' => 'name',
        'name' => 'user',
        'email' => 'email',
        'ownership' => 'ownership',
        'type' => 'type',
        'model' => 'model',
        'first sync' => 'first_sync',
        'last sync' => 'last_sync',
        'status' => 'status',
        'device id' => 'device_id',
        'serial number' => 'serial',
        'imei' => 'imei',
        'os' => 'os',
        'user agent' => 'user_agent',
        'host name' => 'hostname',
    ];

    private const REQUIRED = ['device_id', 'type', 'name'];

    /** "Model" values that only repeat the platform and say nothing about the hardware */
    private const GENERIC_MODELS = ['windows', 'mac os', 'macos', 'linux', 'ios', 'android', 'chrome os', 'chromeos'];

    /** Windows 10/11 build => feature release */
    private const WINDOWS_RELEASES = [
        19041 => [10, '2004'], 19042 => [10, '20H2'], 19043 => [10, '21H1'], 19044 => [10, '21H2'], 19045 => [10, '22H2'],
        22000 => [11, '21H2'], 22621 => [11, '22H2'], 22631 => [11, '23H2'], 26100 => [11, '24H2'], 26200 => [11, '25H2'],
    ];

    /** Browsers freeze the macOS version in their user agent at 10.15.7, so that value is not the real version */
    public const FROZEN_MACOS_VERSION = '10.15.7';

    /**
     * @return list<array{line: int, attributes: array<string, mixed>, notes: string[]}>
     * @throws InvalidArgumentException when the header is not a Google device export
     */
    public static function parse(string $csv, ?DateTimeZone $tz = null): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // UTF-8 BOM
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle, null, ',', '"', '');
        if (!$header) {
            throw new InvalidArgumentException('The file is empty.');
        }
        $map = self::mapHeader($header);
        $missing = array_diff(self::REQUIRED, $map);
        if ($missing) {
            throw new InvalidArgumentException('Not a Google Workspace device export: no ' . implode(', ', $missing) . ' column.');
        }

        $records = [];
        $line = 1;
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', $cells) === '') {
                continue;
            }
            $row = [];
            foreach ($map as $i => $key) {
                $row[$key] = $cells[$i] ?? '';
            }
            $records[] = ['line' => $line] + self::mapRow($row, $tz);
        }
        fclose($handle);
        return $records;
    }

    /** @return array<int, string> column index => internal key */
    private static function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $i => $label) {
            $label = strtolower(trim((string) $label));
            foreach (self::COLUMNS as $prefix => $key) {
                // exact match, or a prefix followed by a qualifier: "serial number (mandatory)", "imei / meid"
                if (!in_array($key, $map, true) && ($label === $prefix || preg_match('/^' . preg_quote($prefix, '/') . '\b[\s(\/]/', $label))) {
                    $map[$i] = $key;
                    break;
                }
            }
        }
        return $map;
    }

    /**
     * @param array<string, string> $row internal key => raw cell
     * @return array{attributes: array<string, mixed>, notes: string[]}
     */
    public static function mapRow(array $row, ?DateTimeZone $tz = null): array
    {
        $get = static fn(string $key) => self::clean($row[$key] ?? '');
        $notes = [];

        $type = strtolower($get('type'));
        $model = $get('model');
        if (in_array(strtolower($model), self::GENERIC_MODELS, true)) {
            $model = '';
        }
        [$os, $osVersion] = self::parseOs($get('os'), $type, $model);
        if ($os === 'macOS' && $osVersion === self::FROZEN_MACOS_VERSION) {
            $osVersion = null;
            $notes[] = 'Google reported macOS ' . self::FROZEN_MACOS_VERSION . ', which is the frozen browser user-agent value: the real macOS version is unknown.';
        }

        $status = strtolower($get('status'));
        if ($status !== '' && $status !== 'approved') {
            $notes[] = 'Google Workspace status: ' . $get('status') . '.';
        }
        $imei = $get('imei');
        if ($imei !== '') {
            $notes[] = 'IMEI / MEID: ' . $imei;
        }

        $hostname = $get('hostname');
        if ($hostname !== '' && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9_-]{0,62})(\.[A-Za-z0-9]([A-Za-z0-9_-]{0,62}))*$/', $hostname)) {
            $notes[] = 'Reported host name: ' . $hostname;
            $hostname = '';
        }

        $attributes = [
            'name' => $get('name'),
            'hostname' => $hostname ?: null,
            'device_type' => self::deviceType($type, $model),
            'ownership' => self::ownership($get('ownership')),
            'manufacturer' => self::manufacturer($type, $model),
            'model' => $model ?: null,
            'serial_number' => $get('serial') ?: null,
            'os' => $os,
            'os_version' => $osVersion,
            'assigned_to' => $get('user') ?: null,
            'assigned_email' => strtolower($get('email')) ?: null,
            'status' => in_array($status, ['wiped', 'account wiped'], true) ? 'retired' : 'active',
            'source' => 'google',
            'source_id' => $get('device_id'),
            'first_seen_at' => self::parseDate($get('first_sync'), $tz),
            'last_seen_at' => self::parseDate($get('last_sync'), $tz),
        ];
        return ['attributes' => $attributes, 'notes' => $notes];
    }

    /** Collapses runs of whitespace ("Adam  Pigott") and trims */
    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    public static function ownership(string $value): string
    {
        return preg_match('/\b(user|personal|employee)\b/i', $value) ? 'byod' : 'company';
    }

    public static function deviceType(string $type, string $model): string
    {
        return match ($type) {
            'ios' => stripos($model, 'ipad') !== false ? 'tablet' : 'mobile',
            'android' => preg_match('/\b(tab|pad)\b|^SM-[TPX]\d|tablet/i', $model) ? 'tablet' : 'mobile',
            'mac' => preg_match('/^(iMac|Macmini|MacPro\d|Mac ?mini|Mac Pro|Mac Studio)/i', $model) ? 'desktop' : 'laptop',
            'windows', 'linux', 'chrome os', 'chromeos' => preg_match('/desktop|tower|optiplex|elitedesk|prodesk|thinkcentre|all-in-one|\bnuc\b/i', $model) ? 'desktop' : 'laptop',
            default => 'other',
        };
    }

    public static function manufacturer(string $type, string $model): ?string
    {
        if (in_array($type, ['mac', 'ios'], true)) {
            return 'Apple';
        }
        $patterns = [
            '/^Pixel\b/i' => 'Google',
            '/^(SM-|Galaxy\b)/i' => 'Samsung',
            '/^Surface\b/i' => 'Microsoft',
            '/^HP\b/i' => 'HP',
            '/^(Dell\b|Latitude\b|XPS\b|OptiPlex\b|Precision\b|Inspiron\b)/i' => 'Dell',
            '/^(Lenovo\b|ThinkPad\b|ThinkCentre\b|IdeaPad\b|Yoga\b)/i' => 'Lenovo',
            '/^2[01][A-Z0-9]{2}[A-Z0-9]{4,6}$/' => 'Lenovo', // ThinkPad machine type-model, e.g. 20U90042UK
            '/^BV\d/i' => 'Blackview',
            '/^(OnePlus|ONEPLUS)/' => 'OnePlus',
            '/^(Xiaomi|Redmi|Mi )/i' => 'Xiaomi',
            '/^(moto|Motorola)/i' => 'Motorola',
            '/^(ASUS|ZenBook|VivoBook)/i' => 'ASUS',
            '/^(Acer|Aspire|Swift|TravelMate)\b/i' => 'Acer',
            '/^Framework\b/i' => 'Framework',
        ];
        foreach ($patterns as $pattern => $vendor) {
            if (preg_match($pattern, $model)) {
                return $vendor;
            }
        }
        return null;
    }

    /**
     * "Windows 10.0.26200.9711" => ['Windows 11', '25H2 (10.0.26200.9711)'], "MacOS 14.6.1" => ['macOS', '14.6.1'].
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function parseOs(string $value, string $type = '', string $model = ''): array
    {
        if ($value === '') {
            return [null, null];
        }
        if (preg_match('/^windows\s*([\d.]+)?$/i', $value, $m)) {
            $version = $m[1] ?? '';
            $parts = explode('.', $version);
            $build = isset($parts[2]) ? (int) $parts[2] : null;
            if ($build === null) {
                return ['Windows', null]; // "Windows 10.0" is the NT kernel version: 10 or 11 is unknown
            }
            $release = self::WINDOWS_RELEASES[$build] ?? null;
            $edition = $release[0] ?? ($build >= 22000 ? 11 : 10);
            return ['Windows ' . $edition, $release ? $release[1] . ' (' . $version . ')' : $version];
        }
        if (preg_match('/^(mac ?os|os x|ios|ipados|android|linux|chrome ?os)\s*(.*)$/i', $value, $m)) {
            $name = match (strtolower(str_replace(' ', '', $m[1]))) {
                'macos', 'osx' => 'macOS',
                'ios', 'ipados' => stripos($model, 'ipad') !== false ? 'iPadOS' : 'iOS',
                'android' => 'Android',
                'linux' => 'Linux',
                default => 'ChromeOS',
            };
            return [$name, $m[2] !== '' ? $m[2] : null];
        }
        $parts = explode(' ', $value, 2);
        return [$parts[0], $parts[1] ?? null];
    }

    /** "October 19, 2026 at 3:48 AM GMT-7" => "2026-10-19 10:48:00" in $tz (default: PHP's timezone) */
    public static function parseDate(string $value, ?DateTimeZone $tz = null): ?string
    {
        if ($value === '') {
            return null;
        }
        $value = preg_replace('/\s+at\s+/i', ' ', $value);
        // "!" zeroes the fields the format lacks (seconds), which would otherwise take the current time
        $date = DateTimeImmutable::createFromFormat('!F j, Y g:i A \G\M\TP', $value);
        if (!$date) {
            try {
                $date = new DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }
        return $date->setTimezone($tz ?? new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}
