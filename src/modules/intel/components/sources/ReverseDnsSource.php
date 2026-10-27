<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Indicator;
use app\modules\intel\components\IpAddress;
use app\modules\intel\components\Network;
use app\modules\intel\components\SourceResult;

/**
 * PTR record plus forward confirmation (FCrDNS): does the PTR name resolve back to the address?
 * Names that advertise scanners, proxies or Tor are flagged.
 */
class ReverseDnsSource extends Source
{
    /** pattern => [weight, label, signal group] */
    public const NAME_HINTS = [
        '/(^|[.-])(tor|tor-?exit|torexit|tor-relay)([.-]|\d|$)/i' => [25, 'PTR name suggests a Tor node', 'tor'],
        '/(^|[.-])(vpn|proxy|socks)([.-]|\d|$)/i' => [15, 'PTR name suggests a VPN / proxy', 'proxy'],
        '/(scan(ner)?|shodan|censys|shadowserver|binaryedge|internet-?measurement|recyber|stretchoid|onyphe)/i' => [10, 'PTR name suggests an internet scanner', 'scanner'],
        '/(dynamic|dyn|dhcp|pool|ppp|dsl|cable|cust|broadband|res(idential)?)[.-]/i' => [0, 'PTR name suggests a residential / dynamic range', null],
    ];

    public function key(): string { return 'rdns'; }
    public function label(): string { return 'Reverse DNS'; }
    public function category(): string { return self::CAT_NETWORK; }
    public function description(): string { return 'PTR hostname and forward-confirmed reverse DNS check.'; }
    public function homepage(): string { return 'https://en.wikipedia.org/wiki/Forward-confirmed_reverse_DNS'; }
    public function supports(Indicator $indicator): bool { return $indicator->isIp(); }
    public function ttl(): int { return 3600; }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $names = Network::dns(IpAddress::ptrName($indicator->value), 'PTR');
        if (!$names) {
            return SourceResult::info('No PTR record')->row('PTR', 'none');
        }
        $host = strtolower($names[0]);
        $forward = array_merge(...Network::dnsMany([[$host, 'A'], [$host, 'AAAA']]));
        $confirmed = in_array($indicator->value, array_map(static fn($ip) => IpAddress::normalize($ip), $forward), true);

        $result = SourceResult::info($host . ($confirmed ? ' (confirmed)' : ''));
        $result->row('PTR', count($names) > 1 ? $names : $host)
            ->row('Forward lookup', $forward ? implode(', ', array_slice($forward, 0, 6)) : 'does not resolve')
            ->row('FCrDNS', $confirmed ? 'Confirmed – PTR resolves back to this address' : 'Not confirmed');
        $result->fact('hostname', $host)->fact('fcrdns', $confirmed);
        foreach (self::NAME_HINTS as $pattern => [$weight, $label, $group]) {
            if (preg_match($pattern, $host)) {
                $result->signal($weight, $weight >= 20 ? 'medium' : 'info', $label, $host, $group);
                if ($weight === 0) {
                    $result->fact('network_type_hint', 'residential');
                }
                break;
            }
        }
        return $result;
    }
}
