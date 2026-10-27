<?php

namespace app\modules\intel\components;

/**
 * Links to free web tools for deeper manual investigation (opened in a new tab;
 * nothing is sent until the analyst clicks).
 */
final class Pivots
{
    /** @return array<string, array<int, array{label: string, url: string, note: string}>> group => links */
    public static function for(Indicator $indicator): array
    {
        $v = rawurlencode($indicator->value);
        if ($indicator->isIp()) {
            return [
                'Reputation' => [
                    ['label' => 'VirusTotal', 'url' => "https://www.virustotal.com/gui/ip-address/$v", 'note' => 'Vendor verdicts, passive DNS, files'],
                    ['label' => 'AbuseIPDB', 'url' => "https://www.abuseipdb.com/check/$v", 'note' => 'Abuse reports'],
                    ['label' => 'GreyNoise', 'url' => "https://viz.greynoise.io/ip/$v", 'note' => 'Scanner activity'],
                    ['label' => 'AlienVault OTX', 'url' => "https://otx.alienvault.com/indicator/ip/$v", 'note' => 'Threat pulses'],
                    ['label' => 'Cisco Talos', 'url' => "https://talosintelligence.com/reputation_center/lookup?search=$v", 'note' => 'Email / web reputation'],
                    ['label' => 'IBM X-Force', 'url' => "https://exchange.xforce.ibmcloud.com/ip/$v", 'note' => 'Risk score, categories'],
                    ['label' => 'ThreatFox', 'url' => "https://threatfox.abuse.ch/browse.php?search=ioc%3A$v", 'note' => 'Malware IOCs'],
                    ['label' => 'Spamhaus', 'url' => "https://check.spamhaus.org/results/?query=$v", 'note' => 'SBL / XBL / CSS listings'],
                ],
                'Exposure & scanning' => [
                    ['label' => 'Shodan', 'url' => "https://www.shodan.io/host/$v", 'note' => 'Open services, banners'],
                    ['label' => 'Censys', 'url' => "https://search.censys.io/hosts/$v", 'note' => 'Services, certificates'],
                    ['label' => 'urlscan.io', 'url' => "https://urlscan.io/search/#ip:%22$v%22", 'note' => 'Web pages hosted here'],
                    ['label' => 'Spur', 'url' => "https://spur.us/context/$v", 'note' => 'VPN / proxy / residential proxy'],
                ],
                'Network & DNS' => [
                    ['label' => 'bgp.tools', 'url' => "https://bgp.tools/prefix/$v", 'note' => 'Prefix, upstreams, IRR'],
                    ['label' => 'Hurricane Electric BGP', 'url' => "https://bgp.he.net/ip/$v", 'note' => 'Routing, DNS, whois'],
                    ['label' => 'RIPEstat', 'url' => "https://stat.ripe.net/app/$v", 'note' => 'Routing history, geo'],
                    ['label' => 'ipinfo.io', 'url' => "https://ipinfo.io/$v", 'note' => 'Geo, ASN, company'],
                    ['label' => 'SecurityTrails', 'url' => "https://securitytrails.com/list/ip/$v", 'note' => 'Domains on this IP'],
                    ['label' => 'ViewDNS reverse IP', 'url' => "https://viewdns.info/reverseip/?host=$v&t=1", 'note' => 'Co-hosted domains'],
                ],
            ];
        }
        $asn = $indicator->asn;
        return [
            'Routing & peering' => [
                ['label' => 'bgp.tools', 'url' => "https://bgp.tools/as/$asn", 'note' => 'Prefixes, upstreams, connectivity'],
                ['label' => 'Hurricane Electric BGP', 'url' => "https://bgp.he.net/AS$asn", 'note' => 'Prefixes, peers, IRR'],
                ['label' => 'RIPEstat', 'url' => "https://stat.ripe.net/app/AS$asn", 'note' => 'Routing history, visibility'],
                ['label' => 'PeeringDB', 'url' => "https://www.peeringdb.com/asn/$asn", 'note' => 'Network profile, IXPs'],
                ['label' => 'Cloudflare Radar', 'url' => "https://radar.cloudflare.com/as$asn", 'note' => 'Traffic, route leaks, hijacks'],
            ],
            'Reputation' => [
                ['label' => 'Spamhaus', 'url' => "https://check.spamhaus.org/results/?query=AS$asn", 'note' => 'ASN-DROP / reputation'],
                ['label' => 'ipinfo.io', 'url' => "https://ipinfo.io/AS$asn", 'note' => 'Ranges, company, domains'],
                ['label' => 'Shodan', 'url' => 'https://www.shodan.io/search?query=' . rawurlencode('asn:AS' . $asn), 'note' => 'Exposed services in this AS'],
                ['label' => 'Censys', 'url' => 'https://search.censys.io/search?resource=hosts&q=' . rawurlencode('autonomous_system.asn: ' . $asn), 'note' => 'Hosts in this AS'],
            ],
        ];
    }
}
