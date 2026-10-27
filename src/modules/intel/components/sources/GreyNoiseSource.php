<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\SourceResult;

/**
 * GreyNoise Community API: is this IP mass-scanning the internet (noise), is it a
 * known business service (RIOT), and how is it classified? Keyless (rate limited);
 * GREYNOISE_API_KEY raises the limit.
 */
class GreyNoiseSource extends Source
{
    public function key(): string { return 'greynoise'; }
    public function label(): string { return 'GreyNoise'; }
    public function category(): string { return self::CAT_REPUTATION; }
    public function description(): string { return 'Internet background noise: mass scanners and their classification, plus known-good business services (RIOT).'; }
    public function homepage(): string { return 'https://www.greynoise.io/'; }
    public function supports(Indicator $indicator): bool { return $indicator->isIp() && $indicator->ipVersion() === 4; }
    public function terms(): ?string { return 'Community API: free, rate limited (optional GREYNOISE_API_KEY).'; }

    public function reportUrl(Indicator $indicator): ?string
    {
        return 'https://viz.greynoise.io/ip/' . rawurlencode($indicator->value);
    }

    public function requests(Indicator $indicator, array $facts): array
    {
        $key = gw_env('GREYNOISE_API_KEY');
        return ['community' => [
            'url' => 'https://api.greynoise.io/v3/community/' . rawurlencode($indicator->value),
            'headers' => $key ? ['key: ' . $key] : [],
        ]];
    }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $response = $responses['community'] ?? null;
        $data = Http::json($response);
        $status = $response['status'] ?? 0;
        if ($status === 404 && $data !== null) {
            return SourceResult::clean('Not observed scanning the internet')->row('Noise', 'No')->row('RIOT', 'No')->rawJson($data);
        }
        if ($status !== 200 || !$data) {
            return SourceResult::error(Http::errorText($response));
        }
        $classification = $data['classification'] ?? 'unknown';
        $name = ($data['name'] ?? '') !== 'unknown' ? ($data['name'] ?? null) : null;
        $result = new SourceResult(SourceResult::INFO, '');
        $result->row('Noise (scanning)', !empty($data['noise']) ? 'Yes' : 'No')
            ->row('RIOT (business service)', !empty($data['riot']) ? 'Yes' : 'No')
            ->row('Classification', ucfirst($classification))
            ->row('Actor / service', $name)
            ->row('Last seen', $data['last_seen'] ?? null)
            ->row('Message', $data['message'] ?? null);

        if (!empty($data['riot'])) {
            $result->summary = 'Known business service' . ($name ? ': ' . $name : '');
            $result->signal(-50, 'success', 'GreyNoise RIOT: common business service' . ($name ? ' (' . $name . ')' : ''), 'Traffic from this address is usually benign (SaaS, CDN, update servers…).');
            $result->status = SourceResult::CLEAN;
        } elseif ($classification === 'malicious') {
            $result->summary = 'Malicious scanner' . ($name ? ' · ' . $name : '');
            $result->signal(60, 'high', 'GreyNoise: malicious internet scanner', 'Observed scanning / exploiting the internet; last seen ' . ($data['last_seen'] ?? '?') . '.', 'scanner');
            $result->status = SourceResult::HIT;
        } elseif ($classification === 'benign') {
            $result->summary = 'Benign scanner' . ($name ? ' · ' . $name : '');
            $result->signal(-25, 'success', 'GreyNoise: benign scanner' . ($name ? ' (' . $name . ')' : ''), 'A known research / search-engine scanner.');
            $result->fact('scanner', $name ?: 'benign');
        } elseif (!empty($data['noise'])) {
            $result->summary = 'Mass scanner (unclassified)';
            $result->signal(25, 'medium', 'GreyNoise: mass-scanning the internet', 'Unclassified scanner; last seen ' . ($data['last_seen'] ?? '?') . '.', 'scanner');
            $result->status = SourceResult::HIT;
        } else {
            $result->summary = 'No notable activity';
            $result->status = SourceResult::CLEAN;
        }
        $result->fact('greynoise', $classification . (!empty($data['riot']) ? ' (riot)' : ''));
        return $result->rawJson($data);
    }
}
