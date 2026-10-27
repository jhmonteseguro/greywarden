<?php

namespace app\modules\intel\components;

/**
 * DNS (parallel UDP, hard timeout) and WHOIS (TCP/43) primitives. Both can be replaced in tests:
 *   Network::$fakeDns   = fn(string $name, string $type): array   (list of A/AAAA ips, TXT strings or PTR names)
 *   Network::$fakeWhois = fn(string $server, string $query): ?string
 */
class Network
{
    /** @var callable|null */
    public static $fakeDns = null;
    /** @var callable|null */
    public static $fakeWhois = null;

    public const WHOIS_ROOT = 'whois.iana.org';
    public const WHOIS_TIMEOUT = 6;

    public const DNS_TIMEOUT = 2.5;
    private const DNS_TYPES = ['A' => 1, 'PTR' => 12, 'TXT' => 16, 'AAAA' => 28];

    /**
     * @param string $type A | AAAA | TXT | PTR
     * @return string[] answers (empty for NXDOMAIN / no data / timeout)
     */
    public static function dns(string $name, string $type): array
    {
        return self::dnsMany([[$name, $type]])[0];
    }

    /**
     * Resolve several names in parallel over UDP with a hard timeout
     * (PHP's dns_get_record() is sequential and cannot time out).
     * @param array<int|string, array{0: string, 1: string}> $queries key => [name, type]
     * @return array<int|string, string[]> key => answers
     */
    public static function dnsMany(array $queries): array
    {
        if (self::$fakeDns !== null) {
            return array_map(static fn($q) => (self::$fakeDns)($q[0], $q[1]), $queries);
        }
        $server = self::nameserver();
        if ($server === null) {
            return array_map(static fn($q) => self::dnsBlocking($q[0], $q[1]), $queries);
        }
        $out = array_map(static fn() => [], $queries);
        $pending = [];
        foreach ($queries as $key => [$name, $type]) {
            $socket = @stream_socket_client('udp://' . (str_contains($server, ':') ? "[$server]" : $server) . ':53', $errno, $errstr, 1);
            if (!$socket) {
                $out[$key] = self::dnsBlocking($name, $type);
                continue;
            }
            stream_set_blocking($socket, false);
            $id = random_int(0, 0xFFFF);
            $packet = self::dnsQuery($id, $name, self::DNS_TYPES[$type]);
            fwrite($socket, $packet);
            $pending[$key] = ['socket' => $socket, 'id' => $id, 'type' => $type, 'packet' => $packet, 'resent' => false];
        }
        $started = microtime(true);
        while ($pending && ($elapsed = microtime(true) - $started) < self::DNS_TIMEOUT) {
            // UDP may drop a datagram: resend unanswered queries once, halfway through
            if ($elapsed > self::DNS_TIMEOUT / 2) {
                foreach ($pending as &$p) {
                    if (!$p['resent']) {
                        fwrite($p['socket'], $p['packet']);
                        $p['resent'] = true;
                    }
                }
                unset($p);
            }
            $read = array_column($pending, 'socket');
            $write = $except = null;
            if (!@stream_select($read, $write, $except, 0, 100000)) {
                continue;
            }
            foreach ($pending as $key => $p) {
                if (!in_array($p['socket'], $read, true)) {
                    continue;
                }
                $data = fread($p['socket'], 4096);
                if ($data === false || strlen($data) < 12 || unpack('n', $data)[1] !== $p['id']) {
                    continue;
                }
                $out[$key] = self::dnsParse($data, self::DNS_TYPES[$p['type']]);
                fclose($p['socket']);
                unset($pending[$key]);
            }
        }
        foreach ($pending as $p) {
            fclose($p['socket']);
        }
        return $out;
    }

    private static function nameserver(): ?string
    {
        static $server = false;
        if ($server === false) {
            $server = null;
            $conf = @file_get_contents('/etc/resolv.conf');
            if ($conf && preg_match('/^\s*nameserver\s+(\S+)/m', $conf, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) {
                $server = $m[1];
            }
        }
        return $server;
    }

    public static function dnsQuery(int $id, string $name, int $qtype): string
    {
        $qname = '';
        foreach (explode('.', rtrim($name, '.')) as $label) {
            $qname .= chr(strlen($label)) . $label;
        }
        // RD flag set, one question
        return pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $qname . "\0" . pack('nn', $qtype, 1);
    }

    /** Answers of the wanted type from a DNS response (NXDOMAIN / errors give []) */
    public static function dnsParse(string $data, int $qtype): array
    {
        $header = unpack('nid/nflags/nqd/nan', $data);
        if (($header['flags'] & 0x000F) !== 0) {
            return [];
        }
        $pos = 12;
        for ($i = 0; $i < $header['qd']; $i++) {
            self::dnsName($data, $pos);
            $pos += 4;
        }
        $out = [];
        for ($i = 0; $i < $header['an'] && $pos + 10 <= strlen($data); $i++) {
            self::dnsName($data, $pos);
            $rr = unpack('ntype/nclass/Nttl/nlen', substr($data, $pos, 10));
            $pos += 10;
            $rdata = substr($data, $pos, $rr['len']);
            if ($rr['type'] === $qtype) {
                switch ($qtype) {
                    case 1:
                    case 28:
                        $ip = @inet_ntop($rdata);
                        if ($ip !== false) {
                            $out[] = $ip;
                        }
                        break;
                    case 16:
                        $txt = '';
                        for ($j = 0; $j < strlen($rdata);) {
                            $len = ord($rdata[$j]);
                            $txt .= substr($rdata, $j + 1, $len);
                            $j += $len + 1;
                        }
                        $out[] = $txt;
                        break;
                    case 12:
                        $p = $pos;
                        $out[] = self::dnsName($data, $p);
                        break;
                }
            }
            $pos += $rr['len'];
        }
        return $out;
    }

    /** Read a (possibly compressed) domain name at $pos; advances $pos past it */
    private static function dnsName(string $data, int &$pos): string
    {
        $labels = [];
        $jumped = false;
        $p = $pos;
        for ($guard = 0; $guard < 128 && $p < strlen($data); $guard++) {
            $len = ord($data[$p]);
            if ($len === 0) {
                $p++;
                break;
            }
            if (($len & 0xC0) === 0xC0) {
                if (!$jumped) {
                    $pos = $p + 2;
                }
                $jumped = true;
                $p = (($len & 0x3F) << 8) | ord($data[$p + 1]);
                continue;
            }
            $labels[] = substr($data, $p + 1, $len);
            $p += $len + 1;
        }
        if (!$jumped) {
            $pos = $p;
        }
        return implode('.', $labels);
    }

    /** Fallback when no resolver address is known */
    private static function dnsBlocking(string $name, string $type): array
    {
        $constant = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'TXT' => DNS_TXT, 'PTR' => DNS_PTR][$type];
        $records = @dns_get_record($name, $constant);
        if (!is_array($records)) {
            return [];
        }
        $out = [];
        foreach ($records as $r) {
            $value = match ($type) {
                'A' => $r['ip'] ?? null,
                'AAAA' => $r['ipv6'] ?? null,
                'TXT' => isset($r['entries']) ? implode('', $r['entries']) : ($r['txt'] ?? null),
                'PTR' => $r['target'] ?? null,
            };
            if ($value !== null && ($r['type'] ?? $type) === $type) {
                $out[] = rtrim($value, '.');
            }
        }
        return $out;
    }

    /**
     * Raw WHOIS: asks IANA for the authoritative server, then queries it.
     * @return array{server: string|null, text: string|null, chain: string[], error: string|null}
     */
    public static function whois(string $query): array
    {
        $chain = [self::WHOIS_ROOT];
        $iana = self::whoisQuery(self::WHOIS_ROOT, $query);
        if ($iana === null) {
            return ['server' => null, 'text' => null, 'chain' => $chain, 'error' => 'Could not reach ' . self::WHOIS_ROOT . ':43'];
        }
        $server = preg_match('/^(?:refer|whois):\s*(\S+)/mi', $iana, $m) ? strtolower($m[1]) : null;
        if (!$server || $server === self::WHOIS_ROOT || !preg_match('/^[a-z0-9.-]+$/', $server)) {
            return ['server' => self::WHOIS_ROOT, 'text' => $iana, 'chain' => $chain, 'error' => null];
        }
        $chain[] = $server;
        // ARIN needs a type flag, otherwise it may return a list of matches
        $q = $query;
        if ($server === 'whois.arin.net') {
            $q = preg_match('/^AS(\d+)$/i', $query, $a) ? 'a + ' . $a[1] : 'n + ' . $query;
        }
        $text = self::whoisQuery($server, $q);
        if ($text === null) {
            return ['server' => self::WHOIS_ROOT, 'text' => $iana, 'chain' => $chain, 'error' => "Could not reach $server:43; showing the IANA record"];
        }
        // RIRs sometimes refer on again (e.g. ARIN -> LACNIC / RWHOIS); follow one more whois:// hop
        if (preg_match('/^ReferralServer:\s*whois:\/\/([a-z0-9.-]+)/mi', $text, $r) && strtolower($r[1]) !== $server) {
            $next = strtolower($r[1]);
            $more = self::whoisQuery($next, $query);
            if ($more !== null) {
                $chain[] = $next;
                $server = $next;
                $text = $more;
            }
        }
        return ['server' => $server, 'text' => $text, 'chain' => $chain, 'error' => null];
    }

    private static function whoisQuery(string $server, string $query): ?string
    {
        if (self::$fakeWhois !== null) {
            return (self::$fakeWhois)($server, $query);
        }
        $socket = @stream_socket_client("tcp://$server:43", $errno, $errstr, self::WHOIS_TIMEOUT);
        if (!$socket) {
            return null;
        }
        stream_set_timeout($socket, self::WHOIS_TIMEOUT);
        fwrite($socket, $query . "\r\n");
        $text = '';
        $started = microtime(true);
        while (!feof($socket) && strlen($text) < 262144 && microtime(true) - $started < self::WHOIS_TIMEOUT) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || (stream_get_meta_data($socket)['timed_out'] ?? false)) {
                break;
            }
            $text .= $chunk;
        }
        fclose($socket);
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1');
        return trim($text) === '' ? null : trim($text);
    }
}
