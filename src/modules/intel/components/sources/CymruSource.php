<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Indicator;
use app\modules\intel\components\IpAddress;
use app\modules\intel\components\Network;
use app\modules\intel\components\SourceResult;

/**
 * Team Cymru IP-to-ASN mapping over DNS: origin AS, BGP prefix, registry and
 * allocation date (origin[6].asn.cymru.com) and the AS name (ASnnn.asn.cymru.com).
 */
class CymruSource extends Source
{
    public function key(): string { return 'cymru'; }
    public function label(): string { return 'Team Cymru IP→ASN'; }
    public function category(): string { return self::CAT_NETWORK; }
    public function description(): string { return 'Origin AS, BGP prefix, registry and allocation date from live routing data (DNS).'; }
    public function homepage(): string { return 'https://www.team-cymru.com/ip-asn-mapping'; }
    public function supports(Indicator $indicator): bool { return true; }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $result = SourceResult::info('');
        $asn = $indicator->asn;
        if ($indicator->isIp()) {
            $zone = $indicator->ipVersion() === 4 ? 'origin.asn.cymru.com' : 'origin6.asn.cymru.com';
            $answers = Network::dns(IpAddress::reversed($indicator->value) . '.' . $zone, 'TXT');
            if (!$answers) {
                return SourceResult::info('Not announced in BGP')->fact('announced', false);
            }
            // Several origins = MOAS; the most specific prefix comes first in practice
            $origins = [];
            foreach ($answers as $txt) {
                $p = array_map('trim', explode('|', $txt));
                if (count($p) >= 3) {
                    foreach (preg_split('/\s+/', $p[0]) as $a) {
                        $origins[] = ['asn' => (int) $a, 'prefix' => $p[1], 'cc' => $p[2] ?? '', 'registry' => $p[3] ?? '', 'allocated' => $p[4] ?? ''];
                    }
                }
            }
            if (!$origins) {
                return SourceResult::error('Unexpected answer: ' . mb_substr($answers[0], 0, 80));
            }
            $first = $origins[0];
            $asn = $first['asn'];
            $result->row('Origin AS', implode(', ', array_unique(array_map(static fn($o) => 'AS' . $o['asn'], $origins))))
                ->row('BGP prefix', $first['prefix'])
                ->row('Registry country', $first['cc'])
                ->row('Registry', strtoupper($first['registry']))
                ->row('Allocated', $first['allocated']);
            $result->fact('asn', $asn)->fact('bgp_prefix', $first['prefix'])->fact('registered_country', $first['cc'] ?: null)->fact('announced', true);
            if (count(array_unique(array_column($origins, 'asn'))) > 1) {
                $result->signal(5, 'info', 'Announced by multiple origin ASes (MOAS)', implode(', ', array_unique(array_map(static fn($o) => 'AS' . $o['asn'], $origins))));
            }
        }

        $names = Network::dns('AS' . $asn . '.asn.cymru.com', 'TXT');
        if ($names) {
            $p = array_map('trim', explode('|', $names[0]));
            $asName = $p[4] ?? null;
            $result->row('AS name', $asName);
            if ($indicator->isAsn()) {
                $result->row('Country', $p[1] ?? null)->row('Registry', strtoupper($p[2] ?? ''))->row('Allocated', $p[3] ?? null);
                $result->fact('country', $p[1] ?? null)->fact('registered_country', $p[1] ?? null)->fact('registered_at', $p[3] ?? null);
            }
            $result->fact('as_name', $asName);
            $result->summary = 'AS' . $asn . ($asName ? ' · ' . $asName : '');
        } elseif ($indicator->isAsn()) {
            return SourceResult::info('No AS record');
        } else {
            $result->summary = 'AS' . $asn;
        }
        return $result;
    }
}
