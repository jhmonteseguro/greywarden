<?php

namespace app\modules\threat\components;

use app\components\Actor;
use app\modules\threat\models\ThreatCve;
use app\modules\threat\models\ThreatStory;
use app\modules\threat\models\ThreatTriage;
use Yii;

/**
 * Ranks stories for one organisation, explainably: each thing that raises a story is a
 * signal shown on its page.
 *
 *   critical  being exploited (in KEV, or reported) and it names something on the watchlist
 *   high      in KEV; or on the watchlist with a severe (CVSS 9+) or likely exploited (EPSS 50%+) CVE
 *   medium    on the watchlist; or exploitation reported; or a severe or likely exploited CVE
 *   low       everything else
 *
 * "On the watchlist" means in a headline or a KEV product name. A mention further down an
 * excerpt is shown but does not raise the priority, as vendors name their own products in
 * everything they publish. A roundup mentions many things without being about any of them, so
 * its CVEs, headline and wording raise nothing: the stories it mentions are ranked on their own.
 * A story whose CVEs span several vendors counts as one, however it is titled: real ones are
 * blog posts citing other people's bugs as examples.
 *
 * Background advisories (quiet sources only) stay out of the queue unless they are in KEV, or on
 * the watchlist with a severe or likely exploited CVE. Matching alone is not enough: a broad
 * term such as Windows matches a hundred advisories every Patch Tuesday. Once a source people
 * read covers one, it is no longer a background advisory.
 */
final class StoryScorer
{
    public const WINDOW_DAYS = 30;
    public const SEVERE_CVSS = 9.0;
    public const HIGH_CVSS = 7.0;
    public const LIKELY_EPSS = 0.5;
    public const RAISED_EPSS = 0.1;
    public const BROAD_VENDORS = 3;

    /** Mentions many things without being about any one of them */
    public static function isBroad(ThreatStory $story): bool
    {
        $vendors = array_unique(array_filter(array_map(static fn(ThreatCve $c) => mb_strtolower((string) $c->vendor), $story->cves)));
        return (bool) $story->is_roundup || count($vendors) >= self::BROAD_VENDORS;
    }

    /**
     * @return array{priority: string, score: int, signals: array[], terms: string[], matched: bool, exploited: bool, hidden: bool}
     */
    public static function evaluate(ThreatStory $story, Watchlist $watchlist): array
    {
        /** @var ThreatCve[] $cves */
        $broad = self::isBroad($story);
        $cves = $broad ? [] : $story->cves;
        $signals = [];
        $add = static function (string $key, string $label, int $weight) use (&$signals): void {
            $signals[] = ['key' => $key, 'label' => $label, 'weight' => $weight];
        };

        $kev = array_values(array_filter($cves, static fn(ThreatCve $c) => $c->getInKev()));
        if ($kev) {
            $add('kev', sprintf('In the CISA Known Exploited Vulnerabilities catalogue (%s)', implode(', ', array_map(static fn(ThreatCve $c) => $c->cve_id, array_slice($kev, 0, 3)))), 50);
            if (array_filter($kev, static fn(ThreatCve $c) => (bool) $c->kev_ransomware)) {
                $add('ransomware', 'Known to be used in ransomware campaigns', 10);
            }
        }
        $reported = $story->exploited_language && !$broad;
        if ($reported) {
            $add('reported', 'Coverage reports a zero-day or exploitation in the wild', 25);
        }

        $headlines = implode(' | ', array_map(static fn($a) => $a->title, $story->articles));
        $products = implode(' | ', array_map(static fn(ThreatCve $c) => trim($c->vendor . ' ' . $c->product), $cves));
        $excerpts = implode(' | ', array_filter(array_map(static fn($a) => $a->excerpt, $story->articles)));
        $strong = $broad ? [] : $watchlist->match($headlines . ' | ' . $products);
        $weak = array_values(array_diff($watchlist->match($broad ? "$headlines | $excerpts" : $excerpts), $strong));
        if ($strong) {
            $add('watchlist', 'On your watchlist: ' . implode(', ', $strong), 30);
        } elseif ($weak) {
            $add('mention', 'Mentioned in passing: ' . implode(', ', $weak), 5);
        }

        $cvss = max([0.0, ...array_map(static fn(ThreatCve $c) => (float) $c->cvss_score, $cves)]);
        if ($cvss >= self::HIGH_CVSS) {
            $add('cvss', sprintf('CVSS %.1f', $cvss), $cvss >= self::SEVERE_CVSS ? 15 : 8);
        }
        $epss = max([0.0, ...array_map(static fn(ThreatCve $c) => (float) $c->epss_score, $cves)]);
        if ($epss >= self::RAISED_EPSS) {
            $add('epss', sprintf('EPSS %.0f%%: chance of exploitation in the next 30 days', $epss * 100), $epss >= self::LIKELY_EPSS ? 15 : 8);
        }
        if ($story->source_count >= 3) {
            $add('coverage', sprintf('Reported by %d sources', $story->source_count), $story->source_count >= 6 ? 10 : 5);
        }

        usort($signals, static fn(array $a, array $b) => $b['weight'] <=> $a['weight']);
        $inKev = $kev !== [];
        $exploited = $inKev || $reported;
        $matched = $strong !== [];
        $serious = $cvss >= self::SEVERE_CVSS || $epss >= self::LIKELY_EPSS;
        $priority = match (true) {
            $exploited && $matched => 'critical',
            $inKev, $matched && $serious => 'high',
            $matched, $exploited, $serious => 'medium',
            default => 'low',
        };
        return [
            'priority' => $priority,
            'score' => min(100, array_sum(array_column($signals, 'weight'))),
            'signals' => $signals,
            'terms' => array_merge($strong, $weak),
            'matched' => $matched,
            'exploited' => $exploited,
            'hidden' => (bool) $story->is_quiet && !$inKev && !($matched && $serious),
        ];
    }

    /**
     * Ranks the recent stories for an organisation, adding triage rows for new ones. A dismissed
     * story comes back if it has become critical or high since.
     *
     * @return array{scored: int, reopened: int}
     */
    public static function run(int $organisationId, ?int $now = null): array
    {
        $now ??= time();
        $db = Yii::$app->db;
        $watchlist = Watchlist::forOrganisation($organisationId);
        $since = date('Y-m-d H:i:s', $now - self::WINDOW_DAYS * 86400);
        $timestamp = Actor::now();
        $scored = $reopened = 0;

        $stories = ThreatStory::find()->where(['>=', 'last_seen_at', $since])->with(['articles', 'cves']);
        foreach ($stories->batch(200) as $batch) {
            $existing = ThreatTriage::find()->forOrganisation($organisationId)->andWhere(['story_id' => array_map(static fn($s) => $s->id, $batch)])->indexBy('story_id')->all();
            $insert = [];
            foreach ($batch as $story) {
                $result = self::evaluate($story, $watchlist);
                $values = [
                    'priority' => $result['priority'], 'score' => $result['score'],
                    'signals' => json_encode($result['signals'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'matched_terms' => json_encode($result['terms'], JSON_UNESCAPED_UNICODE),
                    'is_matched' => (int) $result['matched'], 'is_exploited' => (int) $result['exploited'], 'is_hidden' => (int) $result['hidden'],
                ];
                $scored++;
                $triage = $existing[$story->id] ?? null;
                if ($triage === null) {
                    $insert[] = [$organisationId, $story->id, ...array_values($values), $timestamp, $timestamp];
                    continue;
                }
                $risen = ThreatTriage::rank($result['priority']) > ThreatTriage::rank($triage->priority);
                $changed = array_filter($values, static fn($value, $name) => (string) $value !== (string) $triage->$name, ARRAY_FILTER_USE_BOTH);
                if ($changed) {
                    ThreatTriage::updateAll($changed, ['id' => $triage->id]);
                }
                if ($risen && $triage->status === ThreatTriage::STATUS_DISMISSED && ThreatTriage::rank($result['priority']) >= ThreatTriage::rank('high')) {
                    $triage->reopen(sprintf('Reopened: priority rose to %s since it was dismissed.', $result['priority']));
                    $reopened++;
                }
            }
            if ($insert) {
                $db->createCommand()->batchInsert('threat_triage', ['organisation_id', 'story_id', 'priority', 'score', 'signals', 'matched_terms', 'is_matched', 'is_exploited', 'is_hidden', 'created_at', 'updated_at'], $insert)->execute();
            }
        }
        return ['scored' => $scored, 'reopened' => $reopened];
    }
}
