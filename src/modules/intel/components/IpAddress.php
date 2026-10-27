<?php

namespace app\modules\intel\components;

/**
 * IPv4 / IPv6 helpers: validation, normalisation, special-purpose ranges,
 * CIDR maths and the sortable hex form used in the database.
 *
 * Hex form: 32 lowercase hex chars of the 16-byte address; IPv4 is mapped to
 * ::ffff:a.b.c.d so both families share one ordered keyspace.
 */
final class IpAddress
{
    /**
     * Special-purpose ranges (IANA registries) that are never routed on the
     * public internet. Lookups against external services are skipped for these.
     * cidr => label
     */
    public const SPECIAL = [
        '0.0.0.0/8' => 'This network (RFC 1122)',
        '10.0.0.0/8' => 'Private network (RFC 1918)',
        '100.64.0.0/10' => 'Carrier-grade NAT (RFC 6598)',
        '127.0.0.0/8' => 'Loopback',
        '169.254.0.0/16' => 'Link-local',
        '172.16.0.0/12' => 'Private network (RFC 1918)',
        '192.0.0.0/24' => 'IETF protocol assignments',
        '192.0.2.0/24' => 'Documentation (TEST-NET-1)',
        '192.88.99.0/24' => 'Deprecated 6to4 relay anycast',
        '192.168.0.0/16' => 'Private network (RFC 1918)',
        '198.18.0.0/15' => 'Benchmarking (RFC 2544)',
        '198.51.100.0/24' => 'Documentation (TEST-NET-2)',
        '203.0.113.0/24' => 'Documentation (TEST-NET-3)',
        '224.0.0.0/4' => 'Multicast',
        '240.0.0.0/4' => 'Reserved for future use',
        '255.255.255.255/32' => 'Limited broadcast',
        '::/128' => 'Unspecified address',
        '::1/128' => 'Loopback',
        '::ffff:0:0/96' => 'IPv4-mapped address',
        '64:ff9b:1::/48' => 'Local-use IPv4/IPv6 translation',
        '100::/64' => 'Discard-only (RFC 6666)',
        '2001:db8::/32' => 'Documentation',
        '2001::/32' => 'TEREDO',
        '2002::/16' => '6to4',
        'fc00::/7' => 'Unique local address',
        'fe80::/10' => 'Link-local',
        'ff00::/8' => 'Multicast',
    ];

    public static function isValid(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /** Canonical text form (compressed IPv6, lowercase) or null when invalid. */
    public static function normalize(string $ip): ?string
    {
        $ip = trim($ip, " \t\n\r\0\x0B[]");
        if (!self::isValid($ip)) {
            return null;
        }
        return inet_ntop(inet_pton($ip));
    }

    public static function version(string $ip): int
    {
        return str_contains($ip, ':') ? 6 : 4;
    }

    /** 16-byte binary, IPv4 mapped into ::ffff:0:0/96 */
    public static function toBinary16(string $ip): string
    {
        $bin = inet_pton($ip);
        if ($bin === false) {
            throw new \InvalidArgumentException("Invalid IP address: $ip");
        }
        return strlen($bin) === 4 ? str_repeat("\0", 10) . "\xff\xff" . $bin : $bin;
    }

    public static function toHex(string $ip): string
    {
        return bin2hex(self::toBinary16($ip));
    }

    /** Back from the hex form; mapped IPv4 comes back as dotted quad. */
    public static function fromHex(string $hex): string
    {
        $bin = hex2bin($hex);
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            $bin = substr($bin, 12);
        }
        return inet_ntop($bin);
    }

    /**
     * Parse "a.b.c.d/nn" or "2001:db8::/32" into its normalised network form.
     * @return array{cidr: string, network: string, prefix: int, version: int, start: string, end: string}|null start / end in hex form
     */
    public static function parseCidr(string $cidr): ?array
    {
        $cidr = trim($cidr);
        if (!preg_match('~^([0-9a-fA-F:.]+)/(\d{1,3})$~', $cidr, $m) || !self::isValid($m[1])) {
            return null;
        }
        $version = self::version($m[1]);
        $prefix = (int) $m[2];
        $bits = $version === 4 ? 32 : 128;
        if ($prefix > $bits) {
            return null;
        }
        $bin = inet_pton($m[1]);
        [$low, $high] = self::maskRange($bin, $prefix);
        $network = inet_ntop($low);
        return [
            'cidr' => $network . '/' . $prefix,
            'network' => $network,
            'prefix' => $prefix,
            'version' => $version,
            'start' => self::toHex($network),
            'end' => bin2hex($version === 4 ? str_repeat("\0", 10) . "\xff\xff" . $high : $high),
        ];
    }

    /** [network, broadcast] binaries for an address and prefix length */
    private static function maskRange(string $bin, int $prefix): array
    {
        $low = '';
        $high = '';
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $bitsHere = max(0, min(8, $prefix - $i * 8));
            $mask = $bitsHere === 0 ? 0 : (0xFF << (8 - $bitsHere)) & 0xFF;
            $byte = ord($bin[$i]);
            $low .= chr($byte & $mask);
            $high .= chr(($byte & $mask) | (~$mask & 0xFF));
        }
        return [$low, $high];
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        $range = self::parseCidr($cidr);
        if (!$range || self::version($ip) !== $range['version']) {
            return false;
        }
        $hex = self::toHex($ip);
        return $hex >= $range['start'] && $hex <= $range['end'];
    }

    /** Label of the special-purpose range the address falls in, or null for a public (global unicast) address. */
    public static function specialPurpose(string $ip): ?string
    {
        $ip = self::normalize($ip);
        if ($ip === null) {
            return null;
        }
        $version = self::version($ip);
        $match = null;
        $matchPrefix = -1;
        foreach (self::SPECIAL as $cidr => $label) {
            [$net, $prefix] = explode('/', $cidr);
            if (self::version($net) === $version && (int) $prefix > $matchPrefix && self::inCidr($ip, $cidr)) {
                $match = $label;
                $matchPrefix = (int) $prefix;
            }
        }
        // Outside 2000::/3 nothing is global unicast
        if ($match === null && $version === 6 && !self::inCidr($ip, '2000::/3')) {
            $match = 'Reserved by IETF';
        }
        return $match;
    }

    public static function isPublic(string $ip): bool
    {
        return self::isValid($ip) && self::specialPurpose($ip) === null;
    }

    /** "4.3.2.1" for DNSBL / Team Cymru queries; nibble form for IPv6. */
    public static function reversed(string $ip): string
    {
        if (self::version($ip) === 4) {
            return implode('.', array_reverse(explode('.', $ip)));
        }
        return implode('.', array_reverse(str_split(bin2hex(inet_pton($ip)))));
    }

    public static function ptrName(string $ip): string
    {
        return self::reversed($ip) . (self::version($ip) === 4 ? '.in-addr.arpa' : '.ip6.arpa');
    }

    /** Number of addresses in a prefix, as float (IPv6 sizes overflow ints). */
    public static function prefixSize(int $version, int $prefix): float
    {
        return 2 ** (($version === 4 ? 32 : 128) - $prefix);
    }

    /**
     * Merge overlapping [start, end] hex ranges into a sorted, non-overlapping list
     * (adjacent ranges stay separate so per-entry meta survives). Metas of merged ranges are combined.
     * @param array<int, array{0: string, 1: string, 2?: string|null}> $ranges
     * @return array<int, array{0: string, 1: string, 2: string|null}>
     */
    public static function mergeRanges(array $ranges): array
    {
        usort($ranges, static fn($a, $b) => strcmp($a[0], $b[0]) ?: strcmp($b[1], $a[1]));
        $out = [];
        foreach ($ranges as $r) {
            $meta = $r[2] ?? null;
            $last = count($out) - 1;
            if ($last >= 0 && strcmp($r[0], $out[$last][1]) <= 0) {
                if (strcmp($r[1], $out[$last][1]) > 0) {
                    $out[$last][1] = $r[1];
                }
                if ($meta !== null && $meta !== '' && !in_array($meta, explode('; ', (string) $out[$last][2]), true)) {
                    $out[$last][2] = mb_substr(ltrim($out[$last][2] . '; ' . $meta, '; '), 0, 255);
                }
                continue;
            }
            $out[] = [$r[0], $r[1], $meta];
        }
        return $out;
    }
}
