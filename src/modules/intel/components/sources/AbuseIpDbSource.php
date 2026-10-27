<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\SourceResult;

/**
 * AbuseIPDB: crowd-sourced abuse reports with a 0-100 confidence score.
 * Free account + API key (1,000 checks/day): ABUSEIPDB_API_KEY.
 */
class AbuseIpDbSource extends Source
{
    /** AbuseIPDB usage type => normalised network type */
    public const USAGE_TYPES = [
        'Data Center/Web Hosting/Transit' => 'hosting',
        'Fixed Line ISP' => 'isp',
        'Mobile ISP' => 'mobile',
        'Commercial' => 'enterprise',
        'Organization' => 'enterprise',
        'University/College/School' => 'education',
        'Government' => 'government',
        'Military' => 'government',
        'Library' => 'education',
        'Content Delivery Network' => 'cdn',
        'Search Engine Spider' => 'content',
        'Reserved' => 'reserved',
    ];

    public function key(): string { return 'abuseipdb'; }
    public function label(): string { return 'AbuseIPDB'; }
    public function category(): string { return self::CAT_REPUTATION; }
    public function description(): string { return 'Crowd-sourced abuse reports (brute force, scanning, spam…) with a confidence score.'; }
    public function homepage(): string { return 'https://www.abuseipdb.com/'; }
    public function supports(Indicator $indicator): bool { return $indicator->isIp(); }
    public function apiKeyEnv(): ?string { return 'ABUSEIPDB_API_KEY'; }
    public function terms(): ?string { return 'Free account: 1,000 checks per day.'; }

    public function reportUrl(Indicator $indicator): ?string
    {
        return 'https://www.abuseipdb.com/check/' . rawurlencode($indicator->value);
    }

    public function requests(Indicator $indicator, array $facts): array
    {
        return ['check' => [
            'url' => 'https://api.abuseipdb.com/api/v2/check?' . http_build_query(['ipAddress' => $indicator->value, 'maxAgeInDays' => 90]),
            'headers' => ['Key: ' . $this->apiKey(), 'Accept: application/json'],
        ]];
    }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $response = $responses['check'] ?? null;
        $data = Http::json($response)['data'] ?? null;
        if (($response['status'] ?? 0) !== 200 || !$data) {
            $errors = Http::json($response)['errors'][0]['detail'] ?? null;
            return SourceResult::error($errors ?: Http::errorText($response));
        }
        $score = (int) ($data['abuseConfidenceScore'] ?? 0);
        $reports = (int) ($data['totalReports'] ?? 0);
        $result = $reports > 0 ? SourceResult::hit(sprintf('%d%% confidence · %d reports', $score, $reports)) : SourceResult::clean('No reports in 90 days');
        $result->row('Abuse confidence', $score . '%')
            ->row('Reports (90 days)', (string) $reports)
            ->row('Distinct reporters', (string) ($data['numDistinctUsers'] ?? 0))
            ->row('Last reported', isset($data['lastReportedAt']) ? substr($data['lastReportedAt'], 0, 16) : null)
            ->row('Usage type', $data['usageType'] ?? null)
            ->row('ISP', $data['isp'] ?? null)
            ->row('Domain', $data['domain'] ?? null)
            ->row('Tor', !empty($data['isTor']) ? 'Yes' : null)
            ->row('Whitelisted', !empty($data['isWhitelisted']) ? 'Yes' : null);
        $result->fact('abuse_score', $score)
            ->fact('network_type', self::USAGE_TYPES[$data['usageType'] ?? ''] ?? null)
            ->fact('is_tor', !empty($data['isTor']) ? true : null)
            ->fact('country', $data['countryCode'] ?? null);
        if (!empty($data['isWhitelisted'])) {
            $result->signal(-40, 'success', 'AbuseIPDB: whitelisted');
        } elseif ($score > 0) {
            $tone = $score >= 75 ? 'critical' : ($score >= 40 ? 'high' : ($score >= 15 ? 'medium' : 'low'));
            $result->signal($score, $tone, sprintf('AbuseIPDB: %d%% abuse confidence', $score), sprintf('%d reports from %d users in the last 90 days.', $reports, $data['numDistinctUsers'] ?? 0));
        } elseif ($reports > 0) {
            $result->signal(10, 'low', sprintf('AbuseIPDB: %d old report%s', $reports, $reports === 1 ? '' : 's'));
        }
        return $result->rawJson($data);
    }
}
