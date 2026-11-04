<?php

namespace app\modules\threat\components;

use app\modules\threat\models\ThreatArticle;
use app\modules\threat\models\ThreatCve;
use app\modules\threat\models\ThreatSource;
use app\modules\threat\models\ThreatStory;
use Yii;
use yii\db\Expression;
use yii\db\Query;

/**
 * Files each new article under a story, so twelve reports of one bug read as one entry.
 *
 *   1. An article naming a CVE joins the recent story sharing the most CVEs with it, as long as
 *      at least half of its CVEs are shared: it has to be mainly about that story.
 *   2. Otherwise a headline close enough to a story from the last few days joins it.
 *   3. Anything else starts a story.
 *
 * Merging two different events hides one of them, which is worse than showing a duplicate, so
 * both matches are strict. Roundups (Patch Tuesday, weekly bulletins) mention several events and
 * would glue them together: they stay on their own and nothing joins them.
 *
 * A story takes its headline from the first article from a source people read, not from
 * an advisory or KEV entry, which are named after the CVE.
 */
final class StoryClusterer
{
    public const ROUNDUP_CVES = 5;
    public const CVE_WINDOW_DAYS = 30;
    public const TITLE_WINDOW_DAYS = 3;
    public const TITLE_SIMILARITY = 0.6;
    public const TITLE_MIN_WORDS = 4;

    private const STOP_WORDS = ['the', 'and', 'for', 'with', 'from', 'that', 'this', 'are', 'was', 'were', 'has', 'have', 'had', 'its', 'after', 'over', 'into', 'how', 'why', 'what', 'new', 'says', 'via', 'not', 'but', 'you', 'your', 'can', 'may', 'more', 'now'];

    /** Headlines of regular digests, which may name only a few CVEs */
    private const ROUNDUP_TITLE = '/\b(threat intelligence report|weekly|week in (?:review|security)|bulletin|recap|round-?up|newsletter|digest|wrap-?up|patch tuesday)\b/iu';

    /** Reporting of real attacks, as opposed to a bug merely existing. "Zero Day Initiative" is an organisation. */
    private const EXPLOITED = '/\b(zero[- ]?days?(?!\s+(?:initiative|quest))|0[- ]?days?|(?:actively|being|widely|mass)[- ]exploit\w*|exploited in (?:the )?(?:wild|attacks)|in[- ]the[- ]wild|under (?:active )?(?:attack|exploitation)|active exploitation|mass exploitation|exploitation (?:attempts|detected|observed))\b/iu';
    private const NOT_EXPLOITED = '/\b(no|not|without|unaware|never)\b[^.]{0,60}\b(exploit\w*|in[- ]the[- ]wild)/iu';

    /** @var array<int, array{words: string[], cves: bool}>|null story id => headline words, whether it has CVEs */
    private ?array $recent = null;

    public function __construct(private readonly int $now)
    {
    }

    public static function reportsExploitation(string $text): bool
    {
        return preg_match(self::EXPLOITED, $text) === 1 && preg_match(self::NOT_EXPLOITED, $text) !== 1;
    }

    public static function isRoundup(string $title, array $cves): bool
    {
        return count($cves) > self::ROUNDUP_CVES || preg_match(self::ROUNDUP_TITLE, $title) === 1;
    }

    /** Adds an item as an article. Returns true when it started a new story. */
    public function add(ThreatSource $source, array $item, string $publishedAt, string $fetchedAt): bool
    {
        $cves = $item['cves'];
        ThreatCve::ensure($cves);
        $roundup = self::isRoundup($item['title'], $cves);
        $story = $roundup ? null : ($this->byCve($cves) ?? (!$source->is_quiet ? $this->byTitle($item['title'], $cves !== []) : null));
        $new = $story === null;
        if ($new) {
            $story = new ThreatStory([
                'title' => $item['title'], 'summary' => $item['excerpt'] ?: null, 'is_quiet' => 1, 'is_roundup' => (int) $roundup,
                'first_seen_at' => $publishedAt, 'last_seen_at' => $publishedAt,
            ]);
            $story->save(false);
        }

        $article = new ThreatArticle([
            'source_id' => $source->id, 'story_id' => $story->id, 'guid_hash' => hash('sha256', $item['guid']),
            'title' => $item['title'], 'url' => $item['url'], 'excerpt' => $item['excerpt'] ?: null,
            'published_at' => $publishedAt, 'fetched_at' => $fetchedAt,
        ]);
        $article->save(false);

        if (!$source->is_quiet && $story->is_quiet) {
            $story->title = $item['title'];
            $story->summary = $item['excerpt'] ?: $story->summary;
            $story->is_quiet = 0;
        }
        $story->first_seen_at = min($story->first_seen_at, $publishedAt);
        $story->last_seen_at = max($story->last_seen_at, $publishedAt);
        // Advisories use these words for unpatched bugs (ZDI's "0Day"); for them, KEV is the evidence
        $story->exploited_language = (int) ($story->exploited_language || (!$source->is_quiet && self::reportsExploitation($item['title'] . '. ' . $item['excerpt'])));
        $story->refreshCounts();

        $this->link($story->id, $cves);
        $this->remember($story, $cves !== []);
        return $new;
    }

    private function link(int $storyId, array $cves): void
    {
        if (!$cves) {
            return;
        }
        $known = (new Query())->select('cve_id')->from('threat_story_cve')->where(['story_id' => $storyId, 'cve_id' => $cves])->column();
        $missing = array_values(array_diff($cves, $known));
        if ($missing) {
            Yii::$app->db->createCommand()->batchInsert('threat_story_cve', ['story_id', 'cve_id'], array_map(static fn($id) => [$storyId, $id], $missing))->execute();
        }
    }

    /** The recent story sharing the most CVEs, the latest when tied, if the article is mainly about it */
    private function byCve(array $cves): ?ThreatStory
    {
        if (!$cves) {
            return null;
        }
        $best = (new Query())->select(['s.id', 'shared' => new Expression('COUNT(*)')])->from(['l' => 'threat_story_cve'])->innerJoin(['s' => 'threat_story'], 's.id = l.story_id')
            ->where(['l.cve_id' => $cves, 's.is_roundup' => 0])
            ->andWhere(['>=', 's.last_seen_at', date('Y-m-d H:i:s', $this->now - self::CVE_WINDOW_DAYS * 86400)])
            ->groupBy('s.id')->orderBy([new Expression('COUNT(*) DESC'), 's.last_seen_at' => SORT_DESC])->limit(1)->one();
        return $best && (int) $best['shared'] * 2 >= count($cves) ? ThreatStory::findOne($best['id']) : null;
    }

    private function byTitle(string $title, bool $hasCves): ?ThreatStory
    {
        $words = self::words($title);
        if (count($words) < self::TITLE_MIN_WORDS) {
            return null;
        }
        $this->loadRecent();
        $best = null;
        $bestScore = self::TITLE_SIMILARITY;
        foreach ($this->recent as $id => $story) {
            // Two CVE-bearing stories that reached here name different CVEs, so they differ
            if ($hasCves && $story['cves']) {
                continue;
            }
            $score = self::similarity($words, $story['words']);
            if ($score >= $bestScore) {
                $best = $id;
                $bestScore = $score;
            }
        }
        return $best !== null ? ThreatStory::findOne($best) : null;
    }

    private function loadRecent(): void
    {
        if ($this->recent !== null) {
            return;
        }
        $this->recent = [];
        $withCves = (new Query())->select(new Expression('1'))->from('threat_story_cve')->where('threat_story_cve.story_id = threat_story.id');
        $rows = (new Query())->select(['id', 'title', 'cves' => new Expression('EXISTS(' . $withCves->createCommand()->getRawSql() . ')')])->from('threat_story')
            ->where(['is_quiet' => 0, 'is_roundup' => 0])
            ->andWhere(['>=', 'last_seen_at', date('Y-m-d H:i:s', $this->now - self::TITLE_WINDOW_DAYS * 86400)])->all();
        foreach ($rows as $row) {
            $this->recent[(int) $row['id']] = ['words' => self::words($row['title']), 'cves' => (bool) $row['cves']];
        }
    }

    private function remember(ThreatStory $story, bool $hasCves): void
    {
        if ($this->recent === null || $story->is_quiet || $story->is_roundup) {
            return;
        }
        $this->recent[$story->id] = ['words' => self::words($story->title), 'cves' => $hasCves || ($this->recent[$story->id]['cves'] ?? false)];
    }

    /** @return string[] the distinct meaningful words of a headline */
    public static function words(string $title): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}-]{2,}/u', mb_strtolower($title), $matches);
        return array_values(array_unique(array_diff($matches[0], self::STOP_WORDS)));
    }

    /** Words in common over words in either (Jaccard) */
    public static function similarity(array $a, array $b): float
    {
        $union = count(array_unique(array_merge($a, $b)));
        return $union === 0 ? 0.0 : count(array_intersect($a, $b)) / $union;
    }
}
