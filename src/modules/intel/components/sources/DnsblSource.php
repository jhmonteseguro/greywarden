<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Indicator;
use app\modules\intel\components\IpAddress;
use app\modules\intel\components\Network;
use app\modules\intel\components\SourceResult;

/**
 * DNS blocklists (IPv4): spam sources, botnet drones, open proxies, exploited hosts.
 * An A record in 127.0.0.0/8 means "listed"; the last octet says why.
 * Spamhaus refuses queries arriving via public resolvers: with SPAMHAUS_DQS_KEY (free Data Query Service
 * account) ZEN is queried as <key>.zen.dq.spamhaus.net instead, which works through any resolver.
 */
class DnsblSource extends Source
{
    /**
     * zone => [name, default weight, codes (last octet => [label, weight])]
     * Weight 0 = informational (e.g. Spamhaus PBL: "dynamic range, should not send mail").
     */
    public const ZONES = [
        'zen.spamhaus.org' => ['Spamhaus ZEN', 40, [
            2 => ['SBL – spam source / spam operation', 45], 3 => ['CSS – snowshoe spam', 35],
            4 => ['XBL – exploited host (botnet, malware)', 60], 5 => ['XBL – exploited host', 60], 6 => ['XBL – exploited host', 60], 7 => ['XBL – exploited host', 60],
            9 => ['DROP – hijacked / criminal netblock', 85],
            10 => ['PBL – end-user range (ISP policy)', 0], 11 => ['PBL – end-user range (Spamhaus policy)', 0],
        ]],
        'bl.spamcop.net' => ['SpamCop', 20, []],
        'b.barracudacentral.org' => ['Barracuda BRBL', 20, []],
        'dnsbl.dronebl.org' => ['DroneBL', 35, [
            3 => ['IRC drone', 40], 5 => ['Bottler', 35], 6 => ['Unknown spambot / drone', 40], 7 => ['DDoS drone', 50], 8 => ['SOCKS proxy', 35],
            9 => ['HTTP proxy', 35], 10 => ['Proxy chain', 35], 11 => ['Web page proxy', 30], 12 => ['Open DNS resolver', 15], 13 => ['Brute force attacker', 45],
            14 => ['Open WinGate proxy', 35], 15 => ['Compromised router / gateway', 50], 16 => ['Autorooting worm', 55], 17 => ['Botnet IP', 55],
            18 => ['DNS/MX on IRC', 20], 19 => ['Abused VPN service', 25],
        ]],
        'psbl.surriel.com' => ['PSBL (Passive Spam Block List)', 15, []],
        'all.s5h.net' => ['s5h.net', 15, []],
        'dnsbl-1.uceprotect.net' => ['UCEPROTECT level 1', 10, []],
    ];

    /** Error answers (not listings); anything else in 127.255.255.0/24 is an error too */
    public const ERRORS = [
        '127.255.255.252' => 'query syntax rejected',
        '127.255.255.254' => 'refused – queries via public / open resolvers are blocked; use your own recursive resolver',
        '127.255.255.255' => 'refused – query volume limit exceeded',
    ];

    public const REFUSED_PUBLIC = '127.255.255.254';

    public function key(): string { return 'dnsbl'; }
    public function label(): string { return 'DNS blocklists'; }
    public function category(): string { return self::CAT_DNSBL; }
    public function description(): string { return 'Spamhaus ZEN, SpamCop, Barracuda, DroneBL, PSBL, s5h, UCEPROTECT – spam, botnet, proxy and exploited-host listings.'; }
    public function homepage(): string { return 'https://www.spamhaus.org/zen/'; }
    public function supports(Indicator $indicator): bool { return $indicator->isIp() && $indicator->ipVersion() === 4; }
    public function terms(): ?string { return 'Free for low-volume, non-commercial use; Spamhaus refuses queries through public resolvers (optional SPAMHAUS_DQS_KEY).'; }
    public function ttl(): int { return 3 * 3600; }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $reversed = IpAddress::reversed($indicator->value);
        $rows = [];
        $listed = 0;
        $answered = 0;
        $result = SourceResult::clean('');
        $dqsKey = gw_env('SPAMHAUS_DQS_KEY');
        $dqsValid = $dqsKey === null || preg_match('/^[a-z0-9]{8,64}$/', $dqsKey);
        $queries = [];
        foreach (array_keys(self::ZONES) as $zone) {
            $host = $zone;
            if ($zone === 'zen.spamhaus.org' && $dqsKey !== null) {
                if (!$dqsValid) {
                    continue;
                }
                $host = $dqsKey . '.zen.dq.spamhaus.net';
            }
            $queries[$zone] = [$reversed . '.' . $host, 'A'];
        }
        $all = Network::dnsMany($queries);
        foreach (self::ZONES as $zone => [$name, $defaultWeight, $codes]) {
            if (!array_key_exists($zone, $all)) {
                $rows[] = [$name, 'error', 'SPAMHAUS_DQS_KEY is malformed (expected the lowercase letters and digits of a Spamhaus DQS key)'];
                continue;
            }
            $answers = $all[$zone];
            if (!$answers) {
                $rows[] = [$name, 'clean', '—'];
                $answered++;
                continue;
            }
            $errors = array_values(array_intersect_key(self::ERRORS, array_flip($answers)));
            $valid = array_filter($answers, static fn($a) => str_starts_with($a, '127.') && !str_starts_with($a, '127.255.255.'));
            if (!$valid && $zone === 'zen.spamhaus.org' && $dqsKey === null && in_array(self::REFUSED_PUBLIC, $answers, true)) {
                // Expected through public resolvers: not a fault, just not available without a DQS key
                $rows[] = [$name, 'unavailable', 'refused via public resolver – set SPAMHAUS_DQS_KEY (free Spamhaus DQS key) or use your own recursive resolver'];
                continue;
            }
            if (!$valid) {
                $code = current(array_filter($answers, static fn($a) => str_starts_with($a, '127.255.255.')));
                $rows[] = [$name, 'error', $errors[0] ?? ($code ? 'refused (code ' . $code . ')' : 'unexpected answer ' . $answers[0] . ' (resolver rewrites NXDOMAIN?)')];
                continue;
            }
            $answered++;
            // Several return codes are possible (e.g. XBL + PBL): strongest reason first
            $found = [];
            foreach ($valid as $a) {
                $octet = (int) substr($a, strrpos($a, '.') + 1);
                [$label, $w] = $codes[$octet] ?? ['Listed' . ($octet !== 2 ? ' (code ' . $a . ')' : ''), $defaultWeight];
                $found[$label] = max($found[$label] ?? 0, $w);
            }
            arsort($found);
            $reasons = array_keys($found);
            $weight = (int) reset($found);
            if ($weight > 0) {
                $listed++;
                $rows[] = [$name, 'listed', implode('; ', $reasons)];
                $result->signal($weight, $weight >= 60 ? 'high' : ($weight >= 30 ? 'medium' : 'low'), $name . ': ' . $reasons[0], count($reasons) > 1 ? implode('; ', $reasons) : null,
                    str_starts_with($reasons[0], 'DROP') ? 'drop' : null);
            } else {
                $rows[] = [$name, 'policy', implode('; ', $reasons)];
            }
        }
        $result->table('Blocklists', ['List', 'Result', 'Detail'], $rows);
        $result->fact('dnsbl_listed', $listed)->fact('dnsbl_total', $answered);
        if ($answered === 0) {
            $result->status = SourceResult::ERROR;
            $result->summary = 'No blocklist answered';
        } else {
            $result->status = $listed ? SourceResult::HIT : SourceResult::CLEAN;
            $result->summary = sprintf('Listed on %d of %d', $listed, $answered);
        }
        return $result;
    }
}
