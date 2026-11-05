<?php

namespace app\modules\threat\components;

use app\components\Actor;
use app\models\Organisation;
use app\modules\intel\components\Http;
use app\modules\threat\models\ThreatArticle;
use app\modules\threat\models\ThreatCve;
use app\modules\threat\models\ThreatPull;
use app\modules\threat\models\ThreatSource;
use InvalidArgumentException;
use RuntimeException;
use Yii;
use yii\mutex\FileMutex;

/**
 * One pull: reads the sources that are due, files new articles into stories, notes the threat
 * groups they name, enriches their CVEs, then ranks the stories for each organisation. A source that fails is recorded and
 * skipped, so one bad feed never stops the others.
 *
 * Only the feed itself is fetched, never the pages it links to.
 */
final class ThreatPuller
{
    public const MUTEX = 'threat-pull';
    public const MAX_AGE_DAYS = 14;
    public const TIMEOUT = 25;
    public const MAX_BODY = 16 * 1024 * 1024;

    /** Read every enabled source, due or not */
    public bool $force = false;
    /** Current time; tests set it */
    public ?int $now = null;

    /**
     * @throws RuntimeException when another pull is running
     */
    public function run(): ThreatPull
    {
        $mutex = new FileMutex();
        if (!$mutex->acquire(self::MUTEX)) {
            throw new RuntimeException('Another Threat Watch pull is running.');
        }
        try {
            return $this->pull();
        } finally {
            $mutex->release(self::MUTEX);
        }
    }

    private function pull(): ThreatPull
    {
        $this->now ??= time();
        $pull = new ThreatPull(['started_at' => Actor::now(), 'status' => ThreatPull::STATUS_ERROR, 'run_by' => Actor::id()]);
        try {
            ThreatCatalogue::sync();
            $failures = $this->readSources($pull);
            ActorTagger::run();

            $enricher = new CveEnricher($this->now);
            $pull->cves_enriched = $enricher->run();
            foreach (Organisation::allIds() as $organisationId) {
                Watchlist::sync($organisationId);
                StoryScorer::run($organisationId, $this->now);
            }

            $pull->status = match (true) {
                $failures === [] => ThreatPull::STATUS_OK,
                $pull->sources_read > 0 => ThreatPull::STATUS_PARTIAL,
                default => ThreatPull::STATUS_ERROR,
            };
            $notes = array_merge(array_map(static fn($name, $why) => "$name: $why", array_keys($failures), $failures), $enricher->warnings);
            $pull->message = $notes ? mb_substr(implode('; ', $notes), 0, 500) : null;
        } catch (\Throwable $e) {
            Yii::error("Threat Watch pull failed: $e", __METHOD__);
            $pull->status = ThreatPull::STATUS_ERROR;
            $pull->message = mb_substr($e->getMessage(), 0, 500);
        }
        $pull->finished_at = Actor::now();
        $pull->save(false);
        return $pull;
    }

    /** @return array<string, string> source name => why it failed */
    private function readSources(ThreatPull $pull): array
    {
        $sources = array_filter(ThreatSource::find()->where(['is_enabled' => 1])->orderBy(['id' => SORT_ASC])->all(), fn(ThreatSource $s) => $this->force || $s->isDue($this->now));
        $failures = [];
        $requests = [];
        foreach ($sources as $source) {
            try {
                $requests[$source->id] = self::request($source);
            } catch (InvalidArgumentException $e) {
                $failures[$source->name] = $e->getMessage();
                $source->recordFailure(Actor::now(), $e->getMessage());
            }
        }
        $byId = array_column($sources, null, 'id');
        $responses = Http::fetchAll($requests, self::TIMEOUT);
        // Sources people read first, so an advisory joins the article about it and not the reverse:
        // an article naming several CVEs has too little in common with one single-CVE entry to join it
        uksort($responses, static fn($a, $b) => (int) $byId[$a]->is_quiet <=> (int) $byId[$b]->is_quiet);
        foreach ($responses as $id => $response) {
            $source = $byId[$id];
            try {
                $result = $this->read($source, $response);
                $pull->sources_read++;
                $pull->articles_new += $result['articles'];
                $pull->stories_new += $result['stories'];
            } catch (\Throwable $e) {
                // Whatever went wrong, it stays with this source
                if (!$e instanceof RuntimeException) {
                    Yii::error("Threat Watch source {$source->key} failed: $e", __METHOD__);
                }
                $failures[$source->name] = $e->getMessage();
                $source->recordFailure(Actor::now(), $e->getMessage());
            }
        }
        $pull->sources_failed = count($failures);
        return $failures;
    }

    /**
     * The Http request for a source.
     * @throws InvalidArgumentException when a custom feed's address is not allowed
     */
    public static function request(ThreatSource $source): array
    {
        return $source->is_custom
            ? FeedUrlGuard::request($source->url, self::TIMEOUT)
            : ['url' => $source->url, 'timeout' => self::TIMEOUT, 'max_body' => self::MAX_BODY];
    }

    /**
     * The text of a response, or why there is none.
     * @throws RuntimeException
     */
    public static function body(array $response): string
    {
        if (($response['redirect'] ?? null) !== null && $response['status'] >= 300 && $response['status'] < 400) {
            throw new RuntimeException(sprintf('The feed has moved to %s: use that address.', mb_substr($response['redirect'], 0, 200)));
        }
        if (in_array($response['status'], [401, 403], true)) {
            throw new RuntimeException(sprintf('The publisher refused the request (HTTP %d).', $response['status']));
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException(Http::errorText($response));
        }
        return $response['body'];
    }

    /** @throws RuntimeException */
    private function read(ThreatSource $source, array $response): array
    {
        $body = self::body($response);
        $hash = hash('sha256', $body);
        if ($hash === $source->body_hash) {
            $source->recordSuccess(Actor::now(), $hash, 0);
            return ['articles' => 0, 'stories' => 0];
        }
        $items = FeedParser::parse($body, $source->format);
        $tx = Yii::$app->db->beginTransaction();
        try {
            $result = $this->ingest($source, $items);
            $source->recordSuccess(Actor::now(), $hash, $result['articles']);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
        return $result;
    }

    /**
     * Stores what is new in a list of parsed items.
     * @return array{articles: int, stories: int}
     */
    public function ingest(ThreatSource $source, array $items): array
    {
        $this->now ??= time();
        self::storeKevFacts($items);

        $cutoff = $this->now - self::MAX_AGE_DAYS * 86400;
        $exclude = ThreatCatalogue::exclude($source->key);
        $fresh = [];
        foreach ($items as $item) {
            // Undated items count as new; a clock ahead of ours must not sort above everything
            $item['published_at'] = min($item['published_at'] ?? $this->now, $this->now);
            if ($item['published_at'] >= $cutoff && ($exclude === null || !preg_match($exclude, $item['title']))) {
                $fresh[hash('sha256', $item['guid'])] = $item;
            }
        }
        $known = $fresh ? ThreatArticle::find()->select('guid_hash')->where(['source_id' => $source->id, 'guid_hash' => array_keys($fresh)])->column() : [];
        $fresh = array_diff_key($fresh, array_flip($known));
        uasort($fresh, static fn(array $a, array $b) => $a['published_at'] <=> $b['published_at']);

        $clusterer = new StoryClusterer($this->now);
        $stories = 0;
        $fetchedAt = Actor::now();
        foreach ($fresh as $item) {
            $stories += (int) $clusterer->add($source, $item, date('Y-m-d H:i:s', $item['published_at']), $fetchedAt);
        }
        return ['articles' => count($fresh), 'stories' => $stories];
    }

    /** Records the catalogue's facts for every entry, however old: a story about an old bug should still show it is in KEV */
    private static function storeKevFacts(array $items): void
    {
        $facts = [];
        foreach ($items as $item) {
            if ($item['kev'] !== null) {
                $facts[$item['guid']] = $item['kev'];
            }
        }
        if (!$facts) {
            return;
        }
        ThreatCve::ensure(array_map('strval', array_keys($facts)));
        $columns = ['vendor', 'product', 'kev_added_on', 'kev_due_on', 'kev_ransomware', 'kev_action'];
        $stored = ThreatCve::find()->select(array_merge(['cve_id', 'description'], $columns))->where(['cve_id' => array_keys($facts)])->indexBy('cve_id')->asArray()->all();
        foreach ($facts as $id => $fact) {
            $values = array_intersect_key($fact, array_flip($columns));
            $changed = array_filter($values, static fn($value, $name) => (string) $value !== (string) ($stored[$id][$name] ?? ''), ARRAY_FILTER_USE_BOTH);
            if (($stored[$id]['description'] ?? null) === null && $fact['description'] !== null) {
                $changed['description'] = $fact['description'];
            }
            if ($changed) {
                ThreatCve::updateAll($changed, ['cve_id' => $id]);
            }
        }
    }
}
