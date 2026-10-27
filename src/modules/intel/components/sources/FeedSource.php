<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\FeedCatalogue;
use app\modules\intel\components\Feeds;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\IpAddress;
use app\modules\intel\components\SourceResult;
use yii\helpers\Url;

/**
 * Matches a public indicator against the locally cached bulk feeds (no external query):
 *  IP:  every feed listing the address, cloud provider ranges, ASN-DROP for the origin AS,
 *       and how many listed addresses share its /24 (IPv4) — the "neighbourhood".
 *  ASN: ASN-DROP and the density of listed addresses across the AS's announced prefixes.
 */
class FeedSource extends Source
{
    public function key(): string { return 'feeds'; }
    public function label(): string { return 'Threat feeds (local)'; }
    public function category(): string { return self::CAT_FEEDS; }
    public function description(): string { return 'Feodo Tracker, Spamhaus DROP / ASN-DROP, Emerging Threats, FireHOL, blocklist.de, CINS, IPsum, Tor exits and cloud ranges, matched locally.'; }
    public function homepage(): string { return Url::to(['/intel/source/index']); }
    public function supports(Indicator $indicator): bool { return true; }
    public function ttl(): int { return 0; }
    public function phase(Indicator $indicator): int { return 2; }
    public function isLocal(): bool { return true; }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        // Public feeds only list special-purpose space as "bogons", which says nothing about the host
        if (!$indicator->isPublic()) {
            return SourceResult::skipped('Not applicable: ' . $indicator->specialPurpose());
        }
        if (!Feeds::isLoaded()) {
            Feeds::refreshInBackground();
            return SourceResult::error('Feeds not downloaded yet – updating in the background (or run make intel-update)');
        }
        Feeds::refreshInBackground();
        return $indicator->isIp() ? $this->collectIp($indicator, $facts) : $this->collectAsn($indicator, $facts);
    }

    private function collectIp(Indicator $indicator, array $facts): SourceResult
    {
        $catalogue = FeedCatalogue::all();
        $status = Feeds::status();
        $matches = Feeds::matchIp($indicator->value);
        $asn = isset($facts['asn']) ? (int) $facts['asn'] : null;
        $asnMatches = $asn ? Feeds::matchAsn($asn) : [];
        $result = SourceResult::clean('');

        $rows = [];
        $names = [];
        $threatHits = 0;
        $threatTotal = 0;
        foreach ($catalogue as $key => $def) {
            $loaded = !empty($status[$key]['entry_count']);
            if ($def['format'] === 'spamhaus-asndrop') {
                $hit = $asn && array_key_exists($key, $asnMatches);
                $rows[] = [$def['name'], FeedCatalogue::CATEGORY_LABELS[$def['category']], !$loaded ? 'not loaded' : ($hit ? 'listed' : 'clean'),
                    $hit ? 'Origin AS' . $asn . ($asnMatches[$key] ? ' · ' . $asnMatches[$key] : '') : ($asn ? 'Origin AS' . $asn : 'origin AS unknown'), $key];
                if ($loaded && $asn) {
                    $threatTotal++;
                }
                if ($hit) {
                    $threatHits++;
                    $names[] = $def['name'];
                    $result->signal($def['weight'], $def['tone'], 'Origin network AS' . $asn . ' is on Spamhaus ASN-DROP', $asnMatches[$key]);
                }
                continue;
            }
            $match = $matches[$key] ?? null;
            $isCloud = $def['category'] === 'cloud';
            if (!$isCloud && $loaded) {
                $threatTotal++;
            }
            $rows[] = [$def['name'], FeedCatalogue::CATEGORY_LABELS[$def['category']], !$loaded ? 'not loaded' : ($match ? ($isCloud ? 'match' : 'listed') : 'clean'),
                $match ? trim(($match['range'] !== $indicator->value ? $match['range'] . ' ' : '') . ($match['meta'] ? '· ' . $match['meta'] : ''), ' ·') : '', $key];
            if (!$match) {
                continue;
            }
            if ($isCloud) {
                $result->fact('cloud_provider', $def['name'] . ($match['meta'] ? ' (' . $match['meta'] . ')' : ''))->fact('is_hosting', true);
                if ($key === 'cloudflare') {
                    $result->fact('network_type', 'cdn');
                }
                continue;
            }
            $threatHits++;
            $names[] = $def['name'];
            $weight = $def['weight'];
            $label = $def['name'];
            if ($def['format'] === 'ipsum') {
                $lists = (int) $match['meta'];
                $weight = FeedCatalogue::ipsumWeight($lists);
                $label .= sprintf(': on %d blocklist%s', $lists, $lists === 1 ? '' : 's');
            } elseif ($def['format'] === 'feodo') {
                $offline = str_contains((string) $match['meta'], 'offline');
                $weight = $offline ? 65 : $weight;
                $label .= $offline ? ': former botnet C2' : ': active botnet C2';
            } else {
                $label = 'Listed on ' . $label;
            }
            if ($key === 'tor-exit') {
                $result->fact('is_tor', true);
                $label = 'Tor exit node';
            }
            $tone = $weight >= 75 ? 'critical' : ($weight >= 50 ? 'high' : ($weight >= 25 ? 'medium' : 'low'));
            $result->signal($weight, $tone, $label, trim($def['description'] . ($match['meta'] && $def['format'] !== 'ipsum' ? ' · ' . $match['meta'] : '')),
                ['tor-exit' => 'tor', 'spamhaus-drop' => 'drop'][$key] ?? null);
        }
        $result->table('Feeds', ['Feed', 'Category', 'Result', 'Detail', 'key'], $rows);

        // Neighbourhood: listed addresses in the same /24 (IPv4) or /64 (IPv6), excluding this one
        $block = $indicator->ipVersion() === 4 ? preg_replace('/\.\d+$/', '.0/24', $indicator->value) : $indicator->value . '/64';
        $range = IpAddress::parseCidr($block);
        $neighbours = $range ? Feeds::countInRanges([[$range['start'], $range['end']]]) : [];
        $total = array_sum($neighbours) - $threatHits;
        $result->row('Feeds listing this address', sprintf('%d of %d', $threatHits, $threatTotal))
            ->row('Neighbourhood (' . ($range['cidr'] ?? $block) . ')', $total > 0 ? sprintf('%d other listing%s in the same block', $total, $total === 1 ? '' : 's') : 'No other listings');
        if ($total >= 10) {
            $result->signal(min(20, 5 + $total), 'low', sprintf('Bad neighbourhood: %d other listings in %s', $total, $range['cidr']), 'Abuse often clusters in poorly managed hosting ranges.');
        }
        $result->fact('feed_hits', $threatHits)->fact('feed_total', $threatTotal)->fact('feed_names', $names)->fact('neighbour_listings', max(0, $total));
        $result->status = $threatHits ? SourceResult::HIT : SourceResult::CLEAN;
        $result->summary = $threatHits ? sprintf('Listed on %d of %d feeds', $threatHits, $threatTotal) : sprintf('Clean on %d feeds', $threatTotal);
        return $result;
    }

    private function collectAsn(Indicator $indicator, array $facts): SourceResult
    {
        $catalogue = FeedCatalogue::all();
        $asnMatches = Feeds::matchAsn($indicator->asn);
        $result = SourceResult::clean('');
        foreach ($asnMatches as $key => $meta) {
            if (isset($catalogue[$key])) {
                $result->signal($catalogue[$key]['weight'] + 15, 'critical', 'AS' . $indicator->asn . ' is on ' . $catalogue[$key]['name'], $meta ?: $catalogue[$key]['description']);
            }
        }

        $prefixes = $facts['prefixes'] ?? [];
        $v4 = [];
        foreach ($prefixes as $p) {
            $r = IpAddress::parseCidr($p);
            if ($r && $r['version'] === 4) {
                $v4[] = [$r['start'], $r['end']];
            }
        }
        $counts = $v4 ? Feeds::countInRanges($v4) : [];
        $listed = array_sum($counts);
        $space = (float) ($facts['address_space_v4'] ?? 0);
        $per24 = $space > 0 ? $listed / ($space / 256) : 0.0;

        $rows = [];
        foreach ($counts as $key => $n) {
            $rows[] = [$catalogue[$key]['name'] ?? $key, FeedCatalogue::CATEGORY_LABELS[$catalogue[$key]['category'] ?? ''] ?? '', number_format($n), $key];
        }
        $result->table('Listings inside announced IPv4 space', ['Feed', 'Category', 'Listed', 'key'], $rows);
        $result->row('Spamhaus ASN-DROP', $asnMatches ? 'Listed' . (reset($asnMatches) ? ' · ' . reset($asnMatches) : '') : 'Not listed')
            ->row('Listed IPv4 entries', number_format($listed))
            ->row('Density', $space > 0 ? sprintf('%s per /24 (%s /24s announced)', number_format($per24, 2), number_format($space / 256)) : 'No IPv4 space announced');

        if (!empty($counts['spamhaus-drop'])) {
            $result->signal(65, 'high', sprintf('%d Spamhaus DROP netblock%s inside this AS', $counts['spamhaus-drop'], $counts['spamhaus-drop'] === 1 ? '' : 's'), 'Hijacked or criminal netblocks are announced by this network.');
        }
        if (!empty($counts['feodo'])) {
            $result->signal(min(60, 30 + 5 * $counts['feodo']), 'high', sprintf('Hosts %d botnet C2 server%s (Feodo Tracker)', $counts['feodo'], $counts['feodo'] === 1 ? '' : 's'));
        }
        if ($listed > 0 && $space > 0) {
            $weight = match (true) {
                $per24 >= 5 => 60,
                $per24 >= 1 => 40,
                $per24 >= 0.25 => 20,
                $per24 >= 0.05 => 8,
                default => 0,
            };
            if ($weight > 0) {
                $result->signal($weight, $weight >= 40 ? 'high' : ($weight >= 20 ? 'medium' : 'low'), sprintf('Abuse density: %s listed addresses per /24', number_format($per24, 2)),
                    sprintf('%s listings across %s announced IPv4 addresses.', number_format($listed), number_format($space)));
            }
        }
        $result->fact('feed_listed', $listed)->fact('feed_density', round($per24, 3))->fact('asn_drop', (bool) $asnMatches);
        $result->status = $asnMatches || $result->signals ? SourceResult::HIT : SourceResult::CLEAN;
        $result->summary = $asnMatches ? 'On Spamhaus ASN-DROP' : sprintf('%s listings · %s per /24', number_format($listed), number_format($per24, 2));
        return $result;
    }
}
