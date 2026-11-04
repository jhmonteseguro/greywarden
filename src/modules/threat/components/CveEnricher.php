<?php

namespace app\modules\threat\components;

use app\components\Actor;
use app\modules\intel\components\Http;
use app\modules\threat\models\ThreatCve;
use yii\db\Expression;
use yii\db\Query;

/**
 * Fills in EPSS (FIRST) and CVSS (NVD) for the CVEs in recent stories, stories people read first.
 *
 * EPSS changes daily and is fetched in batches. NVD allows 5 requests in 30 seconds
 * without a key and 50 with one, so a run stays under that and the rest wait for the next
 * run. New CVEs often have no score yet, so those are retried daily.
 */
final class CveEnricher
{
    public const EPSS_URL = 'https://api.first.org/data/v1/epss';
    public const NVD_URL = 'https://services.nvd.nist.gov/rest/json/cves/2.0';
    public const EPSS_BATCH = 50;
    public const EPSS_PER_RUN = 300;
    public const NVD_PER_RUN = 4;
    public const NVD_PER_RUN_KEYED = 40;
    public const RECHECK = 86400;
    public const RECENT_DAYS = 14;
    public const TIMEOUT = 20;

    /** @var string[] problems worth reporting; they never fail the pull */
    public array $warnings = [];

    public function __construct(private readonly int $now)
    {
    }

    /** @return int CVEs with new facts */
    public function run(): int
    {
        return count(array_unique(array_merge($this->epss(), $this->nvd())));
    }

    /** CVE ids in recent stories matching a condition, stories from sources people read first */
    private function due(array $condition, int $limit): array
    {
        return (new Query())->select('c.cve_id')->from(['c' => 'threat_cve'])
            ->innerJoin(['l' => 'threat_story_cve'], 'l.cve_id = c.cve_id')
            ->innerJoin(['s' => 'threat_story'], 's.id = l.story_id')
            ->where(['>=', 's.last_seen_at', date('Y-m-d H:i:s', $this->now - self::RECENT_DAYS * 86400)])
            ->andWhere($condition)
            ->groupBy('c.cve_id')
            ->orderBy([new Expression('MIN(s.is_quiet)'), new Expression('MAX(s.last_seen_at) DESC')])
            ->limit($limit)->column();
    }

    /** @return string[] ids updated */
    private function epss(): array
    {
        $stale = date('Y-m-d H:i:s', $this->now - self::RECHECK);
        $ids = $this->due(['or', ['c.epss_checked_at' => null], ['<', 'c.epss_checked_at', $stale]], self::EPSS_PER_RUN);
        if (!$ids) {
            return [];
        }
        $batches = array_chunk($ids, self::EPSS_BATCH);
        $requests = [];
        foreach ($batches as $i => $batch) {
            $requests[$i] = ['url' => self::EPSS_URL . '?' . http_build_query(['cve' => implode(',', $batch), 'limit' => self::EPSS_BATCH]), 'timeout' => self::TIMEOUT];
        }
        $updated = [];
        $checkedAt = Actor::now();
        foreach (Http::fetchAll($requests, self::TIMEOUT) as $i => $response) {
            $data = $response['status'] === 200 ? Http::json($response) : null;
            if (!is_array($data['data'] ?? null)) {
                $this->warnings[] = 'EPSS: ' . ($response['status'] === 200 ? 'unexpected response' : Http::errorText($response));
                continue;
            }
            foreach ($data['data'] as $row) {
                if (in_array($row['cve'] ?? null, $batches[$i], true) && is_numeric($row['epss'] ?? null)) {
                    ThreatCve::updateAll(['epss_score' => (float) $row['epss'], 'epss_percentile' => is_numeric($row['percentile'] ?? null) ? (float) $row['percentile'] : null], ['cve_id' => $row['cve']]);
                    $updated[] = $row['cve'];
                }
            }
            // Those FIRST has no score for yet are looked at again tomorrow
            ThreatCve::updateAll(['epss_checked_at' => $checkedAt], ['cve_id' => $batches[$i]]);
        }
        return $updated;
    }

    /** @return string[] ids updated */
    private function nvd(): array
    {
        $key = ThreatSettings::nvdApiKey();
        $stale = date('Y-m-d H:i:s', $this->now - self::RECHECK);
        $ids = $this->due(['or', ['c.nvd_checked_at' => null], ['and', ['c.cvss_score' => null], ['<', 'c.nvd_checked_at', $stale]]], $key ? self::NVD_PER_RUN_KEYED : self::NVD_PER_RUN);
        if (!$ids) {
            return [];
        }
        $requests = [];
        foreach ($ids as $id) {
            $requests[$id] = ['url' => self::NVD_URL . '?' . http_build_query(['cveId' => $id]), 'timeout' => self::TIMEOUT, 'headers' => $key ? ['apiKey: ' . $key] : []];
        }
        $updated = [];
        $checkedAt = Actor::now();
        $failed = null;
        foreach (Http::fetchAll($requests, self::TIMEOUT) as $id => $response) {
            if (!in_array($response['status'], [200, 404], true)) {
                $failed = Http::errorText($response);
                continue;
            }
            $values = ['nvd_checked_at' => $checkedAt];
            $cve = Http::json($response)['vulnerabilities'][0]['cve'] ?? null;
            if (is_array($cve)) {
                $values += self::nvdFacts($cve);
                if (isset($values['cvss_score'])) {
                    $updated[] = $id;
                }
            }
            ThreatCve::updateAll($values, ['cve_id' => $id]);
        }
        if ($failed !== null) {
            $this->warnings[] = 'NVD: ' . $failed;
        }
        return $updated;
    }

    /** The newest CVSS version NVD holds for a CVE, and its English description */
    public static function nvdFacts(array $cve): array
    {
        $facts = [];
        foreach ($cve['descriptions'] ?? [] as $description) {
            if (($description['lang'] ?? '') === 'en' && is_string($description['value'] ?? null) && $description['value'] !== '') {
                $facts['description'] = FeedParser::clean($description['value']);
                break;
            }
        }
        foreach (['cvssMetricV40', 'cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2'] as $version) {
            $metric = $cve['metrics'][$version][0] ?? null;
            $score = $metric['cvssData']['baseScore'] ?? null;
            if (is_numeric($score)) {
                $facts['cvss_score'] = (float) $score;
                $severity = $metric['cvssData']['baseSeverity'] ?? $metric['baseSeverity'] ?? null;
                $facts['cvss_severity'] = is_string($severity) ? mb_substr(strtolower($severity), 0, 16) : null;
                break;
            }
        }
        return $facts;
    }
}
