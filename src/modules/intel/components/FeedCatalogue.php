<?php

namespace app\modules\intel\components;

/**
 * Free, keyless bulk feeds that are downloaded and matched locally
 * (no per-lookup query leaves the network).
 *
 * key => [
 *   name, category, urls[], format, weight (0-100 risk evidence; 0 = context only), tone,
 *   description, homepage, maxAge (hours before refresh), terms
 * ]
 * Categories: c2 | hijack | attack | reputation | anonymiser | cloud
 */
final class FeedCatalogue
{
    public const CATEGORY_LABELS = [
        'c2' => 'Malware C2',
        'hijack' => 'Hijacked / criminal networks',
        'attack' => 'Attack sources',
        'reputation' => 'Aggregated reputation',
        'anonymiser' => 'Anonymisers',
        'cloud' => 'Cloud & CDN ranges',
    ];

    public static function all(): array
    {
        $feeds = [
            'feodo' => [
                'name' => 'abuse.ch Feodo Tracker',
                'category' => 'c2',
                'urls' => ['https://feodotracker.abuse.ch/downloads/ipblocklist.json'],
                'format' => 'feodo',
                'weight' => 90, 'tone' => 'critical',
                'description' => 'Botnet command-and-control servers (Dridex, Emotet, QakBot, Pikabot, …).',
                'homepage' => 'https://feodotracker.abuse.ch/',
                'maxAge' => 6,
                'terms' => 'CC0',
            ],
            'spamhaus-drop' => [
                'name' => 'Spamhaus DROP',
                'category' => 'hijack',
                'urls' => ['https://www.spamhaus.org/drop/drop_v4.json', 'https://www.spamhaus.org/drop/drop_v6.json'],
                'format' => 'spamhaus-drop',
                'weight' => 85, 'tone' => 'critical',
                'description' => '"Don\'t Route Or Peer": netblocks hijacked or leased by professional spam / cybercrime operations.',
                'homepage' => 'https://www.spamhaus.org/blocklists/do-not-route-or-peer/',
                'maxAge' => 12,
                'terms' => 'Free for all users',
            ],
            'spamhaus-asndrop' => [
                'name' => 'Spamhaus ASN-DROP',
                'category' => 'hijack',
                'urls' => ['https://www.spamhaus.org/drop/asndrop.json'],
                'format' => 'spamhaus-asndrop',
                'weight' => 70, 'tone' => 'high',
                'description' => 'Autonomous systems controlled by spammers or cybercriminals.',
                'homepage' => 'https://www.spamhaus.org/blocklists/do-not-route-or-peer/',
                'maxAge' => 12,
                'terms' => 'Free for all users',
            ],
            'et-compromised' => [
                'name' => 'Emerging Threats compromised',
                'category' => 'attack',
                'urls' => ['https://rules.emergingthreats.net/blockrules/compromised-ips.txt'],
                'format' => 'plain',
                'weight' => 55, 'tone' => 'high',
                'description' => 'Hosts known to be compromised and actively attacking (Proofpoint ET Open).',
                'homepage' => 'https://rules.emergingthreats.net/',
                'maxAge' => 12,
                'terms' => 'ET Open (BSD)',
            ],
            'firehol-l1' => [
                'name' => 'FireHOL Level 1',
                'category' => 'reputation',
                'urls' => ['https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/firehol_level1.netset'],
                'format' => 'plain',
                'weight' => 50, 'tone' => 'high',
                'description' => 'Low false-positive aggregate of attack, malware and abuse lists (DShield, Feodo, Spamhaus DROP, …).',
                'homepage' => 'https://iplists.firehol.org/?ipset=firehol_level1',
                'maxAge' => 12,
                'terms' => 'Aggregated from free lists',
                // FireHOL level 1 also blocks bogons; those are reported as special-purpose ranges instead
                'skipSpecial' => true,
            ],
            'blocklist-de' => [
                'name' => 'blocklist.de',
                'category' => 'attack',
                'urls' => ['https://lists.blocklist.de/lists/all.txt'],
                'format' => 'plain',
                'weight' => 40, 'tone' => 'medium',
                'description' => 'Attackers reported by fail2ban users in the last 48h (SSH, mail, FTP, web brute force).',
                'homepage' => 'https://www.blocklist.de/',
                'maxAge' => 12,
                'terms' => 'Free',
            ],
            'cins' => [
                'name' => 'CINS Army',
                'category' => 'attack',
                'urls' => ['https://cinsscore.com/list/ci-badguys.txt'],
                'format' => 'plain',
                'weight' => 40, 'tone' => 'medium',
                'description' => 'Hosts with poor reputation scores seen by Sentinel IPS sensors.',
                'homepage' => 'https://cinsscore.com/',
                'maxAge' => 12,
                'terms' => 'Free',
            ],
            'ipsum' => [
                'name' => 'IPsum',
                'category' => 'reputation',
                'urls' => ['https://raw.githubusercontent.com/stamparm/ipsum/master/ipsum.txt'],
                'format' => 'ipsum',
                'weight' => 0, 'tone' => 'medium', // weight scales with the number of lists (see FeedSource)
                'description' => 'Daily aggregate of 30+ public blocklists; the score is the number of lists naming the address.',
                'homepage' => 'https://github.com/stamparm/ipsum',
                'maxAge' => 24,
                'terms' => 'Unlicense',
            ],
            'tor-exit' => [
                'name' => 'Tor exit nodes',
                'category' => 'anonymiser',
                'urls' => ['https://check.torproject.org/torbulkexitlist'],
                'format' => 'plain',
                'weight' => 35, 'tone' => 'medium',
                'description' => 'Current Tor exit relays (traffic from these is anonymised).',
                'homepage' => 'https://metrics.torproject.org/',
                'maxAge' => 6,
                'terms' => 'Public',
            ],
            'aws' => [
                'name' => 'Amazon Web Services',
                'category' => 'cloud',
                'urls' => ['https://ip-ranges.amazonaws.com/ip-ranges.json'],
                'format' => 'aws',
                'weight' => 0, 'tone' => 'info',
                'description' => 'Published AWS address ranges (EC2, CloudFront, …) with region.',
                'homepage' => 'https://docs.aws.amazon.com/vpc/latest/userguide/aws-ip-ranges.html',
                'maxAge' => 168,
                'terms' => 'Public',
            ],
            'gcp' => [
                'name' => 'Google Cloud',
                'category' => 'cloud',
                'urls' => ['https://www.gstatic.com/ipranges/cloud.json'],
                'format' => 'gcp',
                'weight' => 0, 'tone' => 'info',
                'description' => 'Published Google Cloud customer ranges with region.',
                'homepage' => 'https://cloud.google.com/compute/docs/faq#find_ip_range',
                'maxAge' => 168,
                'terms' => 'Public',
            ],
            'cloudflare' => [
                'name' => 'Cloudflare',
                'category' => 'cloud',
                'urls' => ['https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6'],
                'format' => 'plain',
                'weight' => 0, 'tone' => 'info',
                'description' => 'Cloudflare edge (CDN / proxy) ranges – the real origin is hidden behind them.',
                'homepage' => 'https://www.cloudflare.com/ips/',
                'maxAge' => 168,
                'terms' => 'Public',
            ],
            'digitalocean' => [
                'name' => 'DigitalOcean',
                'category' => 'cloud',
                'urls' => ['https://www.digitalocean.com/geo/google.csv'],
                'format' => 'csv-cidr',
                'weight' => 0, 'tone' => 'info',
                'description' => 'Published DigitalOcean droplet ranges.',
                'homepage' => 'https://docs.digitalocean.com/platform/',
                'maxAge' => 168,
                'terms' => 'Public',
            ],
        ];
        $disabled = gw_env_list('INTEL_DISABLE', []);
        return array_diff_key($feeds, array_flip($disabled));
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Feeds that are evidence of malicious activity (weight > 0 or scored per entry) */
    public static function threatKeys(): array
    {
        return array_keys(array_filter(self::all(), static fn($f) => $f['category'] !== 'cloud' && $f['format'] !== 'spamhaus-asndrop'));
    }

    public static function cloudKeys(): array
    {
        return array_keys(array_filter(self::all(), static fn($f) => $f['category'] === 'cloud'));
    }

    /** IPsum: weight from the number of lists that name the address */
    public static function ipsumWeight(int $lists): int
    {
        return min(75, 8 + 9 * $lists);
    }
}
