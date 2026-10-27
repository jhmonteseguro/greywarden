<?php

namespace app\modules\intel\components;

/**
 * Turns the signals of all sources into one explainable verdict.
 *
 * Score (0-100): risk signals combine like independent probabilities
 *   p = 1 - Π(1 - w/100)
 * so two moderate listings outweigh one, but nothing exceeds 100. The strongest
 * known-good signal (negative weight, e.g. GreyNoise RIOT, a whitelist) then
 * scales the result down: score = p × (1 - 0.8 × benign/100).
 *
 * Rating: malicious ≥ 70, suspicious ≥ 40, elevated ≥ 15; below that "no known
 * threat" when enough reputation sources answered, otherwise "insufficient data".
 * Special-purpose addresses / ASNs are rated "non-routable".
 */
final class Assessment
{
    public const RATINGS = [
        'malicious' => ['label' => 'Malicious', 'tone' => 'critical', 'icon' => 'radioactive'],
        'suspicious' => ['label' => 'Suspicious', 'tone' => 'high', 'icon' => 'exclamation-octagon'],
        'elevated' => ['label' => 'Elevated risk', 'tone' => 'medium', 'icon' => 'exclamation-triangle'],
        'clean' => ['label' => 'No known threat', 'tone' => 'success', 'icon' => 'shield-check'],
        'unknown' => ['label' => 'Insufficient data', 'tone' => 'neutral', 'icon' => 'question-circle'],
        'special' => ['label' => 'Non-routable', 'tone' => 'info', 'icon' => 'diagram-3'],
    ];

    public const NETWORK_TYPES = [
        'hosting' => 'Hosting / data centre',
        'cloud' => 'Cloud provider',
        'cdn' => 'CDN / edge network',
        'content' => 'Content provider',
        'transit' => 'Transit / backbone',
        'isp' => 'ISP / broadband',
        'residential' => 'Residential / dynamic',
        'mobile' => 'Mobile network',
        'enterprise' => 'Enterprise',
        'education' => 'Education / research',
        'government' => 'Government',
        'reserved' => 'Reserved',
    ];

    /** Short names for the summary tile */
    public const NETWORK_SHORT = [
        'hosting' => 'Hosting', 'cloud' => 'Cloud', 'cdn' => 'CDN', 'content' => 'Content', 'transit' => 'Transit', 'isp' => 'ISP',
        'residential' => 'Residential', 'mobile' => 'Mobile', 'enterprise' => 'Enterprise', 'education' => 'Education',
        'government' => 'Government', 'reserved' => 'Reserved',
    ];

    /** AS / organisation names that indicate hosting */
    public const HOSTING_PATTERN = '/\b(hosting|hoster|servers?|vps|dedicated|data ?cent(er|re)|datacenter|colo(cation)?|cloud|digitalocean|ovh|hetzner|linode|akamai|vultr|choopa|contabo|leaseweb|m247|colocrossing|scaleway|amazon|aws|azure|oracle|alibaba|tencent|ionos|hostinger|hostwinds|interserver|frantech|buyvm|psychz|quadranet|servermania|zenlayer|aeza|stark industries|pq hosting|selectel|timeweb)\b/i';

    public int $score = 0;
    public string $rating = 'unknown';
    public int $confidence = 0;
    public string $headline = '';
    /** Signals with their source: [..., 'source' => key, 'sourceLabel' => label, 'counted' => bool], strongest first */
    public array $signals = [];
    /** Summary tiles: [label, value, caption, tone, icon] */
    public array $tiles = [];
    public ?string $networkType = null;

    public static function evaluate(Report $report): self
    {
        $a = new self();
        $indicator = $report->indicator;
        foreach ($report->results as $key => $result) {
            foreach ($result->signals as $signal) {
                $a->signals[] = $signal + ['source' => $key, 'sourceLabel' => SourceRegistry::get($key)?->label() ?? $key];
            }
        }
        $a->networkType = self::networkType($report->facts);
        if (in_array($a->networkType, ['hosting', 'cloud'], true) && $indicator->isIp()) {
            $a->signals[] = ['weight' => 5, 'tone' => 'info', 'label' => 'Hosted in a data centre / cloud',
                'detail' => 'Not malicious in itself, but most attack infrastructure is rented hosting.', 'source' => 'assessment', 'sourceLabel' => 'Assessment'];
        }
        usort($a->signals, static fn($x, $y) => abs($y['weight']) <=> abs($x['weight']) ?: $y['weight'] <=> $x['weight']);
        // Corroborating signals (same group) describe one fact: only the strongest is counted
        $seen = [];
        foreach ($a->signals as &$s) {
            $group = $s['group'] ?? null;
            $s['counted'] = $group === null || !isset($seen[$group]);
            if ($group !== null) {
                $seen[$group] = true;
            }
        }
        unset($s);

        $a->score = self::score(array_column(array_filter($a->signals, static fn($s) => $s['counted']), 'weight'));
        $a->confidence = $indicator->isPublic() ? self::confidence($report) : 0;

        if (!$indicator->isPublic()) {
            $a->rating = 'special';
            $a->score = 0;
        } elseif ($a->score >= 70) {
            $a->rating = 'malicious';
        } elseif ($a->score >= 40) {
            $a->rating = 'suspicious';
        } elseif ($a->score >= 15) {
            $a->rating = 'elevated';
        } else {
            $a->rating = $a->confidence >= 40 ? 'clean' : 'unknown';
        }
        $a->headline = $a->buildHeadline($report);
        $a->tiles = $indicator->isIp() ? $a->ipTiles($report) : $a->asnTiles($report);
        return $a;
    }

    /** @param int[] $weights */
    public static function score(array $weights): int
    {
        $keep = 1.0;
        $benign = 0;
        foreach ($weights as $w) {
            if ($w > 0) {
                $keep *= 1 - min(100, $w) / 100;
            } elseif ($w < 0) {
                $benign = max($benign, -$w);
            }
        }
        return (int) round(100 * (1 - $keep) * (1 - 0.8 * $benign / 100));
    }

    /**
     * Share of reputation sources (feeds, DNSBLs, reputation services) that answered.
     * Sources without an API key count half against it, so adding keys raises confidence.
     */
    public static function confidence(Report $report): int
    {
        $attempted = 0.0;
        $answered = 0;
        foreach ($report->results as $key => $result) {
            $source = SourceRegistry::get($key);
            if (!$source || ($report->indicator->isIp() && !$source->isReputation())) {
                continue;
            }
            if ($result->status === SourceResult::UNCONFIGURED) {
                $attempted += 0.5;
            } elseif ($result->status !== SourceResult::SKIPPED) {
                $attempted++;
                $answered += $result->answered() ? 1 : 0;
            }
        }
        return $attempted > 0 ? (int) round(100 * $answered / $attempted) : 0;
    }

    public static function confidenceLabel(int $confidence): string
    {
        return $confidence >= 75 ? 'High' : ($confidence >= 45 ? 'Medium' : 'Low');
    }

    public static function networkType(array $facts): ?string
    {
        if (!empty($facts['network_type'])) {
            return $facts['network_type'];
        }
        if (!empty($facts['cloud_provider'])) {
            return 'cloud';
        }
        if (!empty($facts['is_hosting'])) {
            return 'hosting';
        }
        $name = trim(($facts['as_name'] ?? '') . ' ' . ($facts['org'] ?? ''));
        if (!empty($facts['as_network_type']) && $facts['as_network_type'] !== 'transit') {
            return $facts['as_network_type'] === 'content' && preg_match(self::HOSTING_PATTERN, $name) ? 'hosting' : $facts['as_network_type'];
        }
        if ($name !== '' && preg_match(self::HOSTING_PATTERN, $name)) {
            return 'hosting';
        }
        if (($facts['network_type_hint'] ?? null) === 'residential') {
            return 'residential';
        }
        return $facts['as_network_type'] ?? null;
    }

    public function ratingLabel(): string
    {
        return self::RATINGS[$this->rating]['label'];
    }

    public function tone(): string
    {
        return self::RATINGS[$this->rating]['tone'];
    }

    public function icon(): string
    {
        return self::RATINGS[$this->rating]['icon'];
    }

    public function riskSignals(): array
    {
        return array_values(array_filter($this->signals, static fn($s) => $s['weight'] > 0));
    }

    public function benignSignals(): array
    {
        return array_values(array_filter($this->signals, static fn($s) => $s['weight'] < 0));
    }

    public function contextSignals(): array
    {
        return array_values(array_filter($this->signals, static fn($s) => $s['weight'] === 0));
    }

    public static function countryName(?string $cc): ?string
    {
        if (!$cc || strlen($cc) !== 2) {
            return null;
        }
        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-' . strtoupper($cc), 'en') : '';
        return $name !== '' && strtoupper($name) !== strtoupper($cc) ? $name : strtoupper($cc);
    }

    /** "Hosting / data centre · DigitalOcean (AS14061) · Netherlands" */
    public static function networkLine(Report $report, ?string $type): string
    {
        $f = $report->facts;
        $asn = $report->indicator->isAsn() ? $report->indicator->asn : ($f['asn'] ?? null);
        $owner = $f['as_name'] ?? $f['org'] ?? null;
        $parts = array_filter([
            $type ? self::NETWORK_TYPES[$type] ?? ucfirst($type) : null,
            $owner ? $owner . ($asn && $report->indicator->isIp() ? ' (AS' . $asn . ')' : '') : ($asn ? 'AS' . $asn : null),
            self::countryName($f['country'] ?? $f['registered_country'] ?? null),
        ]);
        return implode(' · ', $parts);
    }

    private function buildHeadline(Report $report): string
    {
        $i = $report->indicator;
        if ($this->rating === 'special') {
            return sprintf('%s is %s space. It is not routed on the public internet, so no external intelligence sources were queried.',
                $i->value, lcfirst((string) $i->specialPurpose()));
        }
        $risk = $this->riskSignals();
        $strong = array_values(array_filter($risk, static fn($s) => $s['weight'] >= 15));
        if ($strong) {
            $labels = array_values(array_unique(array_column(array_slice($strong, 0, 2), 'label')));
            $more = count($strong) - count($labels);
            $text = implode('; ', $labels) . ($more > 0 ? sprintf(' and %d more signal%s', $more, $more === 1 ? '' : 's') : '') . '.';
        } elseif ($this->benignSignals()) {
            $text = $this->benignSignals()[0]['label'] . '. No source reports malicious activity.';
        } elseif ($this->rating === 'clean') {
            $text = 'No intelligence source reports malicious activity.';
        } else {
            $text = 'Too few reputation sources answered to rate this ' . ($i->isIp() ? 'address' : 'network') . ' with confidence.';
        }
        return $text;
    }

    private static function tile(string $label, string $value, ?string $caption, string $tone, string $icon): array
    {
        return compact('label', 'value', 'caption', 'tone', 'icon');
    }

    private function ipTiles(Report $report): array
    {
        $f = $report->facts;
        $special = !$report->indicator->isPublic();
        $feedHits = (int) ($f['feed_hits'] ?? 0);
        $feedSignals = array_filter($this->signals, static fn($s) => $s['source'] === 'feeds' && $s['weight'] > 0);
        $maxFeed = $feedSignals ? max(array_column($feedSignals, 'weight')) : 0;
        $feedResult = $report->result('feeds');
        $tiles = [];
        $tiles[] = self::tile('Threat feeds', isset($f['feed_total']) ? $feedHits . ' / ' . $f['feed_total'] : '—',
            $feedHits ? implode(', ', array_slice($f['feed_names'] ?? [], 0, 2)) : ($special ? 'not applicable' : ($feedResult && !$feedResult->answered() ? 'not loaded' : 'not listed')),
            $feedHits ? ($maxFeed >= 75 ? 'critical' : ($maxFeed >= 50 ? 'high' : 'medium')) : (isset($f['feed_total']) ? 'success' : 'neutral'), 'collection');

        $dnsbl = $report->result('dnsbl');
        $listed = (int) ($f['dnsbl_listed'] ?? 0);
        $tiles[] = self::tile('DNS blocklists', $dnsbl && $dnsbl->answered() ? $listed . ' / ' . ($f['dnsbl_total'] ?? 0) : '—',
            $dnsbl && $dnsbl->answered() ? ($listed ? 'listed' : 'not listed') : ($dnsbl ? ($special ? 'not queried' : ($report->indicator->ipVersion() === 6 ? 'IPv4 only' : 'no answer')) : 'IPv4 only'),
            $listed ? ($listed >= 3 ? 'high' : 'medium') : ($dnsbl && $dnsbl->answered() ? 'success' : 'neutral'), 'envelope-x');

        // Reputation: the strongest crowd / sensor verdict available
        [$repValue, $repCaption, $repTone] = ['—', $special ? 'not queried' : 'no data', 'neutral'];
        if (isset($f['abuse_score'])) {
            $s = (int) $f['abuse_score'];
            [$repValue, $repCaption, $repTone] = [$s . '%', 'AbuseIPDB confidence', $s >= 75 ? 'critical' : ($s >= 40 ? 'high' : ($s >= 15 ? 'medium' : 'success'))];
        } elseif (!empty($f['vt_malicious'])) {
            [$repValue, $repCaption, $repTone] = [(string) $f['vt_malicious'], 'VirusTotal detections', 'high'];
        } elseif (isset($f['greynoise'])) {
            $g = $f['greynoise'];
            [$repValue, $repCaption, $repTone] = [str_contains($g, 'riot') ? 'Benign' : ucfirst(explode(' ', $g)[0]), 'GreyNoise classification',
                str_contains($g, 'malicious') ? 'high' : (str_contains($g, 'riot') || str_contains($g, 'benign') ? 'success' : 'medium')];
        } elseif (!empty($f['otx_pulses'])) {
            [$repValue, $repCaption, $repTone] = [(string) $f['otx_pulses'], 'OTX pulses', 'medium'];
        } elseif (($report->result('greynoise')?->status ?? null) === SourceResult::CLEAN || ($report->result('otx')?->status ?? null) === SourceResult::CLEAN) {
            [$repValue, $repCaption, $repTone] = ['Clean', 'no reports', 'success'];
        }
        $tiles[] = self::tile('Reputation', $repValue, $repCaption, $repTone, 'people');

        $anon = array_keys(array_filter(['Tor exit' => $f['is_tor'] ?? false, 'Proxy' => $f['is_proxy'] ?? false, 'VPN' => $f['is_vpn'] ?? false]));
        $tiles[] = self::tile('Anonymiser', $anon ? $anon[0] : ($special ? '—' : 'None'), $anon ? implode(', ', $anon) . ' detected' : ($special ? 'not queried' : 'no Tor / proxy / VPN'),
            $anon ? (in_array('Tor exit', $anon, true) ? 'high' : 'medium') : ($special ? 'neutral' : 'success'), 'incognito');

        $ports = $f['ports'] ?? [];
        $vulns = $f['vulns'] ?? [];
        $exposure = $report->result('internetdb');
        $tiles[] = self::tile('Exposure', $exposure && $exposure->answered() ? count($ports) . ' port' . (count($ports) === 1 ? '' : 's') : '—',
            $exposure && $exposure->answered() ? ($vulns ? count($vulns) . ' known CVE' . (count($vulns) === 1 ? '' : 's') : ($ports ? implode(', ', array_slice($ports, 0, 5)) : 'nothing exposed')) : ($special ? 'not queried' : 'no data'),
            $vulns ? 'high' : ($ports ? 'info' : ($exposure && $exposure->answered() ? 'success' : 'neutral')), 'hdd-network');

        $tiles[] = self::tile('Network', $this->networkType ? self::NETWORK_SHORT[$this->networkType] ?? ucfirst($this->networkType) : ($special ? 'Special' : 'Unknown'),
            $special ? $report->indicator->specialPurpose() : (self::countryName($f['country'] ?? null) ?? ($f['cloud_provider'] ?? null)),
            in_array($this->networkType, ['hosting', 'cloud'], true) ? 'info' : 'neutral', 'globe2');
        return $tiles;
    }

    private function asnTiles(Report $report): array
    {
        $f = $report->facts;
        $special = !$report->indicator->isPublic();
        $drop = !empty($f['asn_drop']);
        $density = (float) ($f['feed_density'] ?? 0);
        $feeds = $report->result('feeds');
        $tiles = [];
        $tiles[] = self::tile('ASN-DROP', $drop ? 'Listed' : ($feeds && $feeds->answered() ? 'Not listed' : '—'), $drop ? 'criminal / hijacked AS' : 'Spamhaus',
            $drop ? 'critical' : ($feeds && $feeds->answered() ? 'success' : 'neutral'), 'sign-stop');
        $tiles[] = self::tile('Abuse density', isset($f['feed_density']) ? number_format($density, 2) : '—', 'listed addresses per /24',
            $density >= 1 ? 'high' : ($density >= 0.25 ? 'medium' : (isset($f['feed_density']) ? 'success' : 'neutral')), 'grid-3x3');
        $tiles[] = self::tile('Listed addresses', isset($f['feed_listed']) ? number_format((int) $f['feed_listed']) : '—', 'in announced IPv4 space',
            ($f['feed_listed'] ?? 0) > 0 ? 'medium' : 'neutral', 'collection');
        $tiles[] = self::tile('Prefixes', isset($f['prefix_count_v4']) ? number_format((int) $f['prefix_count_v4']) . ' / ' . number_format((int) ($f['prefix_count_v6'] ?? 0)) : '—',
            isset($f['address_space_v4']) ? 'v4 / v6 · ' . self::compactNumber((float) $f['address_space_v4']) . ' addresses' : ($special ? 'not queried' : 'IPv4 / IPv6'), 'info', 'diagram-2');
        $n = $f['neighbours'] ?? [];
        $tiles[] = self::tile('Peers', $n ? number_format((int) ($n['left'] ?? 0)) . ' / ' . number_format((int) ($n['right'] ?? 0)) : '—', 'upstream / downstream', 'neutral', 'share');
        $tiles[] = self::tile('Network', $this->networkType ? self::NETWORK_SHORT[$this->networkType] ?? ucfirst($this->networkType) : ($special ? 'Special' : 'Unknown'),
            $special ? $report->indicator->specialPurpose() : self::countryName($f['country'] ?? $f['registered_country'] ?? null),
            in_array($this->networkType, ['hosting', 'cloud'], true) ? 'info' : 'neutral', 'globe2');
        return $tiles;
    }

    public static function compactNumber(float $n): string
    {
        foreach ([1e9 => 'B', 1e6 => 'M', 1e3 => 'k'] as $div => $suffix) {
            if ($n >= $div) {
                return rtrim(rtrim(number_format($n / $div, 1), '0'), '.') . $suffix;
            }
        }
        return number_format($n);
    }
}
