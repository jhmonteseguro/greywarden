<?php

namespace tests\unit;

use app\commands\ScheduleController;
use app\components\Actor;
use app\modules\intel\components\Http;
use app\modules\threat\components\CveEnricher;
use app\modules\threat\components\FeedUrlGuard;
use app\modules\threat\components\StoryClusterer;
use app\modules\threat\components\ThreatCatalogue;
use app\modules\threat\components\ThreatPuller;
use app\modules\threat\components\ThreatSettings;
use app\modules\threat\models\ThreatArticle;
use app\modules\threat\models\ThreatCve;
use app\modules\threat\models\ThreatPull;
use app\modules\threat\models\ThreatSource;
use app\modules\threat\models\ThreatStory;
use app\modules\threat\models\ThreatTriage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use tests\DbTestCase;
use tests\support\OutputCapture;
use Yii;
use yii\console\ExitCode;
use yii\db\Query;
use yii\mutex\FileMutex;

/**
 * From feed to story: grouping articles, enrichment, one source failing, the custom-feed
 * guard, and the console and scheduler entry points. HTTP is faked.
 */
class ThreatPipelineTest extends DbTestCase
{
    private int $now;
    private int $next = 0;
    /** @var array<string, array> url fragment => response */
    private array $responses = [];
    /** @var string[] urls requested, in order */
    private array $requested = [];
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = time();
        Http::$fake = function (array $request): array {
            $this->requested[] = $request['url'];
            $this->requests[] = $request;
            foreach ($this->responses as $fragment => $response) {
                if (str_contains($request['url'], $fragment)) {
                    return $response;
                }
            }
            return ['status' => 200, 'body' => str_contains($request['url'], 'known_exploited') ? '{"vulnerabilities":[]}' : '<rss><channel></channel></rss>'];
        };
    }

    protected function tearDown(): void
    {
        Http::$fake = null;
        FeedUrlGuard::$resolver = null;
        Actor::override(null);
        putenv('THREAT_PULL_INTERVAL=0');
        putenv('NVD_API_KEY');
        parent::tearDown();
    }

    private function source(string $key = 'bleepingcomputer'): ThreatSource
    {
        return ThreatSource::findOne(['key' => $key]);
    }

    private function item(string $title, array $cves = [], array $extra = []): array
    {
        $this->next++;
        return $extra + [
            'guid' => 'item-' . $this->next, 'title' => $title, 'url' => 'https://news.example.com/' . $this->next, 'excerpt' => '',
            'cves' => $cves, 'published_at' => $this->now - 3600, 'kev' => null,
        ];
    }

    /** @return int the story the item landed in */
    private function ingest(array $item, string $key = 'bleepingcomputer'): int
    {
        $puller = new ThreatPuller();
        $puller->now = $this->now;
        $puller->ingest($this->source($key), [$item]);
        return (int) ThreatArticle::find()->select('story_id')->where(['guid_hash' => hash('sha256', $item['guid'])])->scalar();
    }

    private function feed(string ...$titles): array
    {
        $items = '';
        foreach ($titles as $i => $title) {
            $items .= sprintf('<item><title>%s</title><link>https://news.example.com/f%d</link><pubDate>%s</pubDate></item>', $title, $i, date(DATE_RSS, $this->now - 3600));
        }
        return ['status' => 200, 'body' => "<rss><channel>$items</channel></rss>"];
    }

    private function pull(bool $force = true): ThreatPull
    {
        $puller = new ThreatPuller();
        $puller->force = $force;
        $puller->now = $this->now;
        return $puller->run();
    }

    // ---- Grouping ----------------------------------------------------------------------

    public function testArticlesOnTheSameCveBecomeOneStory(): void
    {
        $first = $this->ingest($this->item('Acme gateway flaw patched', ['CVE-2026-1001']));
        $second = $this->ingest($this->item('Completely different words here today', ['CVE-2026-1001']), 'securityweek');
        $this->assertSame($first, $second);
        $story = ThreatStory::findOne($first);
        $this->assertSame(2, (int) $story->article_count);
        $this->assertSame(2, (int) $story->source_count);
        $this->assertSame(['CVE-2026-1001'], array_column($story->cves, 'cve_id'));
    }

    public function testAStoryTakesItsHeadlineFromASourcePeopleRead(): void
    {
        $id = $this->ingest($this->item('CVE-2026-1002: Acme Unchecked Return Value', ['CVE-2026-1002'], ['excerpt' => 'Advisory text.']), 'cisa-kev');
        $this->assertSame(1, (int) ThreatStory::findOne($id)->is_quiet);
        $this->ingest($this->item('Hackers hit Acme gateways', ['CVE-2026-1002'], ['excerpt' => 'Readable summary.']));
        $story = ThreatStory::findOne($id);
        $this->assertSame('Hackers hit Acme gateways', $story->title);
        $this->assertSame('Readable summary.', $story->summary);
        $this->assertSame(0, (int) $story->is_quiet);
        // ...and keeps it when more articles arrive
        $this->ingest($this->item('A later article', ['CVE-2026-1002']), 'securityweek');
        $this->assertSame('Hackers hit Acme gateways', ThreatStory::findOne($id)->title);
    }

    public function testRoundupsStayOnTheirOwnAndNothingJoinsThem(): void
    {
        // A digest with a few CVEs: this is what glued unrelated stories together on real data
        $linux = $this->ingest($this->item('Linux TCP flaw', ['CVE-2026-2001']));
        $digest = $this->ingest($this->item('2nd November – Threat Intelligence Report', ['CVE-2026-2001', 'CVE-2026-2002']));
        $this->assertNotSame($linux, $digest);
        $this->assertSame(1, (int) ThreatStory::findOne($digest)->is_roundup);
        $drupal = $this->ingest($this->item('Drupal commerce flaw', ['CVE-2026-2002']));
        $this->assertNotSame($digest, $drupal, 'an article does not join a roundup through its CVEs');

        $many = array_map(static fn($n) => "CVE-2026-300$n", range(1, StoryClusterer::ROUNDUP_CVES + 1));
        $patches = $this->ingest($this->item('A long list of fixes', $many));
        $this->assertSame(1, (int) ThreatStory::findOne($patches)->is_roundup);
        $this->assertCount(count($many), ThreatStory::findOne($patches)->cves, 'its CVEs are still listed');
        $this->assertTrue(StoryClusterer::isRoundup('⚡ Weekly Recap: all of it', []));
        $this->assertFalse(StoryClusterer::isRoundup('Acme fixes a gateway flaw', ['CVE-2026-1']));
    }

    public function testAnArticleJoinsOnlyWhenItIsMainlyAboutTheStory(): void
    {
        $story = $this->ingest($this->item('Linux TCP flaw', ['CVE-2026-4001']));
        $this->assertNotSame($story, $this->ingest($this->item('Three unrelated things', ['CVE-2026-4001', 'CVE-2026-4002', 'CVE-2026-4003'])), 'one of three in common');
        $this->assertSame($story, $this->ingest($this->item('Two related flaws', ['CVE-2026-4001', 'CVE-2026-4009'])), 'one of two in common');
    }

    public function testNearIdenticalHeadlinesJoinAndDifferentOnesDoNot(): void
    {
        $a = $this->ingest($this->item('Hackers breach Harbour Freight shipping portal stealing customer records'));
        $this->assertSame($a, $this->ingest($this->item('Hackers breach Harbour Freight shipping portal, steal customer records'), 'securityweek'));
        $this->assertNotSame($a, $this->ingest($this->item('Harbour Freight announces new warehouse in Leeds this spring')));
        $this->assertNotSame($a, $this->ingest($this->item('Too short')), 'too few words to judge');
        $cve = $this->ingest($this->item('Acme gateway flaw lets attackers bypass login screens', ['CVE-2026-5001']));
        $this->assertNotSame($cve, $this->ingest($this->item('Acme gateway flaw lets attackers bypass login screens again', ['CVE-2026-5002'])), 'different CVEs are different bugs');
        $this->assertEqualsWithDelta(0.0, StoryClusterer::similarity([], []), 0.001);
    }

    public function testOldStoriesAreNotJoined(): void
    {
        $old = $this->item('Acme gateway flaw patched', ['CVE-2026-6001'], ['published_at' => $this->now - 3600]);
        $id = $this->ingest($old);
        ThreatStory::updateAll(['last_seen_at' => date('Y-m-d H:i:s', $this->now - (StoryClusterer::CVE_WINDOW_DAYS + 1) * 86400)], ['id' => $id]);
        $this->assertNotSame($id, $this->ingest($this->item('The same bug, much later', ['CVE-2026-6001'])));
    }

    #[DataProvider('exploitationLanguage')]
    public function testExploitationLanguage(string $text, bool $expected): void
    {
        $this->assertSame($expected, StoryClusterer::reportsExploitation($text));
    }

    public static function exploitationLanguage(): array
    {
        return [
            ['Ivanti zero-days exploited in the wild', true],
            ['Hackers actively exploiting the flaw', true],
            ['Critical bug under active attack', true],
            ['Patches released for a critical flaw', false],
            ['Reported through the Zero Day Initiative', false],
            ['There is no evidence of exploitation in the wild', false],
            ['The vendor said the bug has not been exploited in attacks', false],
        ];
    }

    public function testAdvisoryWordingIsNotEvidenceOfExploitation(): void
    {
        $id = $this->ingest($this->item('ZDI-26-818: (0Day) Example Viewer Parsing Flaw', ['CVE-2026-7001']), 'zdi-published');
        $this->assertSame(0, (int) ThreatStory::findOne($id)->exploited_language);
        $id = $this->ingest($this->item('Example Viewer zero-day used against banks', ['CVE-2026-7002']));
        $this->assertSame(1, (int) ThreatStory::findOne($id)->exploited_language);
    }

    // ---- Ingest ------------------------------------------------------------------------

    public function testOnlyNewRecentItemsAreStored(): void
    {
        $puller = new ThreatPuller();
        $puller->now = $this->now;
        $item = $this->item('Recent story here');
        $old = $this->item('An old story here', [], ['published_at' => $this->now - (ThreatPuller::MAX_AGE_DAYS + 1) * 86400]);
        $this->assertSame(['articles' => 1, 'stories' => 1], $puller->ingest($this->source(), [$item, $old]));
        $this->assertSame(['articles' => 0, 'stories' => 0], $puller->ingest($this->source(), [$item]), 'already read');

        $future = $this->item('Clock ahead of ours', [], ['published_at' => $this->now + 86400]);
        $undated = $this->item('No date at all', [], ['published_at' => null]);
        $this->assertSame(2, $puller->ingest($this->source(), [$future, $undated])['articles']);
        foreach (ThreatArticle::find()->all() as $article) {
            $this->assertLessThanOrEqual($this->now, strtotime($article->published_at));
        }
    }

    public function testExcludedTitlesAreSkipped(): void
    {
        $puller = new ThreatPuller();
        $puller->now = $this->now;
        $result = $puller->ingest($this->source('sans-isc'), [$this->item('ISC Stormcast For Wednesday'), $this->item('A real diary about a vulnerable script')]);
        $this->assertSame(1, $result['articles']);
    }

    public function testKevFactsAreKeptForEveryEntryHoweverOld(): void
    {
        $kev = ['description' => 'Old bug.', 'vendor' => 'Acme', 'product' => 'Gate', 'kev_added_on' => '2021-01-01', 'kev_due_on' => null, 'kev_ransomware' => 1, 'kev_action' => 'Patch.'];
        $item = $this->item('CVE-2021-1234: Acme Gate', ['CVE-2021-1234'], ['guid' => 'CVE-2021-1234', 'kev' => $kev, 'published_at' => strtotime('2021-01-01')]);
        $puller = new ThreatPuller();
        $puller->now = $this->now;
        $this->assertSame(0, $puller->ingest($this->source('cisa-kev'), [$item])['articles'], 'too old to be news');
        $cve = ThreatCve::findOne('CVE-2021-1234');
        $this->assertTrue($cve->getInKev());
        $this->assertSame('Acme', $cve->vendor);
        $this->assertSame('Old bug.', $cve->description);

        // A later change is picked up, and a better description is not overwritten
        ThreatCve::updateAll(['description' => 'From NVD.'], ['cve_id' => 'CVE-2021-1234']);
        $item['kev']['kev_ransomware'] = 0;
        $puller->ingest($this->source('cisa-kev'), [$item]);
        $cve->refresh();
        $this->assertSame(0, (int) $cve->kev_ransomware);
        $this->assertSame('From NVD.', $cve->description);
    }

    // ---- A pull ------------------------------------------------------------------------

    public function testAPullReadsSourcesRanksStoriesAndLogsIt(): void
    {
        $this->responses['bleepingcomputer'] = $this->feed('Okta warns of phishing wave hitting support staff');
        $pull = $this->pull();
        $this->assertSame(ThreatPull::STATUS_OK, $pull->status, (string) $pull->message);
        $this->assertSame(1, (int) $pull->articles_new);
        $this->assertSame(1, (int) $pull->stories_new);
        $this->assertSame((int) ThreatSource::find()->where(['is_enabled' => 1])->count(), (int) $pull->sources_read);
        $this->assertNotNull($pull->id);
        $this->assertNotNull($pull->finished_at);
        $triage = ThreatTriage::find()->forOrganisation()->one();
        $this->assertSame('medium', $triage->priority);
        $this->assertSame(['Okta'], $triage->getMatchedTermList());
        $source = $this->source();
        $this->assertSame('ok', $source->getHealth());
        $this->assertSame(1, (int) $source->items_total);
    }

    public function testOneBadSourceNeverStopsTheOthers(): void
    {
        $this->responses['bleepingcomputer'] = ['status' => 500, 'body' => ''];
        $this->responses['therecord'] = ['status' => 403, 'body' => 'Access Denied'];
        $this->responses['securityweek'] = ['status' => 200, 'body' => '<html>not a feed</html>'];
        // Not a RuntimeException: on real data a PHP error in one source stopped the whole pull
        $this->responses['krebsonsecurity'] = ['status' => 200, 'body' => null];
        $this->responses['darkreading'] = $this->feed('The survivor still gets read today');
        $pull = $this->pull();
        $this->assertSame(ThreatPull::STATUS_PARTIAL, $pull->status);
        $this->assertSame(4, (int) $pull->sources_failed);
        $this->assertSame(1, (int) $pull->articles_new);
        $this->assertStringContainsString('BleepingComputer: HTTP 500', $pull->message);
        $this->assertSame('failing', $this->source()->getHealth());
        $this->assertSame(1, (int) $this->source()->failures);
        $this->assertSame('The publisher refused the request (HTTP 403).', $this->source('the-record')->last_error);
        $this->assertSame('This is not an RSS or Atom feed.', $this->source('securityweek')->last_error);
        $this->assertNotNull($this->source('krebs')->last_error);

        $this->responses = [];
        $this->pull();
        $this->assertSame('ok', $this->source()->getHealth(), 'it recovers');
        $this->assertNull($this->source()->last_error);
    }

    public function testASourceThatFailsHalfwayLeavesNothingBehind(): void
    {
        $this->responses['therecord'] = $this->feed('This one is still read fine today');
        $fake = Http::$fake;
        $body = $this->feed('First article with enough words', 'Second article with enough words');
        // The source vanishes after the fetch, so storing its articles fails
        Http::$fake = function (array $request) use ($fake, $body): array {
            if (str_contains($request['url'], 'bleepingcomputer')) {
                ThreatSource::deleteAll(['key' => 'bleepingcomputer']);
                return $body;
            }
            return $fake($request);
        };
        $pull = $this->pull();
        $this->assertSame(ThreatPull::STATUS_PARTIAL, $pull->status);
        $this->assertSame(1, (int) $pull->sources_failed);
        $this->assertSame(1, (int) ThreatStory::find()->count(), 'only the other source\'s story');
    }

    public function testEverySourceFailingIsAnError(): void
    {
        $this->responses['http'] = ['status' => 0, 'body' => '', 'error' => 'Could not resolve host'];
        $pull = $this->pull();
        $this->assertTrue($pull->getIsError());
        $this->assertStringContainsString('Could not resolve host', $pull->message);
    }

    public function testAnUnexpectedFailureIsLoggedAsAFailedPull(): void
    {
        Http::$fake = static fn() => throw new \LogicException('Boom');
        $pull = $this->pull();
        $this->assertTrue($pull->getIsError());
        $this->assertSame('Boom', $pull->message);
        $this->assertNotNull($pull->id);
    }

    public function testSourcesAreReadAtTheirOwnPaceAndOffOnesNotAtAll(): void
    {
        $this->pull(false);
        $first = count($this->requested);
        $this->assertGreaterThan(20, $first);
        $this->assertNotEmpty(array_filter($this->requested, static fn($u) => str_contains($u, 'cybersecurity-advisories')), 'CISA Advisories is read like the others');

        $this->requested = [];
        $this->pull(false);
        $this->assertSame([], $this->requested, 'nothing is due yet');

        $this->now += 31 * 60;
        $this->pull(false);
        $this->assertContains($this->source()->url, $this->requested, 'news is due after 30 minutes');
        $this->assertNotContains($this->source('talos')->url, $this->requested, 'research is not');

        ThreatSource::updateAll(['is_enabled' => 0], ['key' => 'cisa-advisories']);
        $this->requested = [];
        $this->now += 86400;
        $this->pull(false);
        $this->assertNotEmpty($this->requested, 'a day later everything else is due');
        $this->assertEmpty(array_filter($this->requested, static fn($u) => str_contains($u, 'cybersecurity-advisories')), 'a source switched off is not read');
    }

    public function testAnUnchangedFeedIsNotParsedAgain(): void
    {
        $this->responses['bleepingcomputer'] = $this->feed('First article with enough words');
        $this->pull();
        $hash = $this->source()->body_hash;
        $this->assertNotNull($hash);
        $this->responses['bleepingcomputer'] = $this->feed('First article with enough words');
        $pull = $this->pull();
        $this->assertSame(0, (int) $pull->articles_new);
        $this->assertSame($hash, $this->source()->body_hash);
        $this->assertSame(1, (int) $this->source()->items_total);
    }

    public function testAdvisoriesAreReadAfterArticlesSoTheyJoinThem(): void
    {
        // The headline names three CVEs, each with its own KEV entry; read the other way round it could not join any of them
        $body = sprintf('<rss><channel><item><title>Attackers chain Acme flaws CVE-2026-8001 CVE-2026-8002 CVE-2026-8003</title><link>https://news.example.com/x</link><pubDate>%s</pubDate></item></channel></rss>', date(DATE_RSS, $this->now));
        $this->responses['bleepingcomputer'] = ['status' => 200, 'body' => $body];
        $kev = [];
        foreach ([8001, 8002, 8003] as $n) {
            $kev[] = ['cveID' => "CVE-2026-$n", 'vulnerabilityName' => 'Acme flaw', 'dateAdded' => date('Y-m-d', $this->now), 'vendorProject' => 'Acme', 'product' => 'Gate'];
        }
        $this->responses['known_exploited'] = ['status' => 200, 'body' => json_encode(['vulnerabilities' => $kev])];
        $this->pull();
        $this->assertSame(1, (int) ThreatStory::find()->count());
        $this->assertSame(4, (int) ThreatStory::find()->one()->article_count);
    }

    public function testOnlyOnePullRunsAtATime(): void
    {
        $mutex = new FileMutex();
        $this->assertTrue($mutex->acquire(ThreatPuller::MUTEX));
        try {
            $this->expectException(RuntimeException::class);
            $this->pull();
        } finally {
            $mutex->release(ThreatPuller::MUTEX);
        }
    }

    // ---- Catalogue ---------------------------------------------------------------------

    public function testCatalogueSyncKeepsWhatAdminsChose(): void
    {
        $source = $this->source();
        ThreatSource::updateAll(['is_enabled' => 0, 'url' => 'https://old.example.com/', 'items_total' => 7], ['id' => $source->id]);
        ThreatSource::deleteAll(['key' => 'talos']);
        (new ThreatSource(['key' => 'retired', 'name' => 'Retired', 'category' => 'news', 'url' => 'https://retired.example.com/', 'is_enabled' => 1]))->save(false);
        (new ThreatSource(['key' => 'custom-1', 'name' => 'Mine', 'category' => 'news', 'url' => 'https://mine.example.com/', 'is_custom' => 1, 'is_enabled' => 1]))->save(false);

        ThreatCatalogue::sync();

        $source->refresh();
        $this->assertSame(0, (int) $source->is_enabled, 'switched off stays off');
        $this->assertSame(7, (int) $source->items_total);
        $this->assertStringContainsString('bleepingcomputer.com', $source->url, 'the definition is corrected');
        $this->assertSame(1, (int) $this->source('talos')->is_enabled, 'new sources arrive');
        $this->assertSame(0, (int) $this->source('retired')->is_enabled, 'dropped sources stop');
        $this->assertSame(1, (int) $this->source('custom-1')->is_enabled, 'custom feeds are not touched');
        $this->assertSame(1, (int) $this->source('cisa-advisories')->is_enabled, 'every built-in source starts switched on');
        $this->assertContains('msrc', ThreatCatalogue::keys());
        $this->assertSame(1, (int) $this->source('msrc')->is_quiet);
        $this->assertSame('kev', $this->source('cisa-kev')->format);
    }

    public function testSourceHealth(): void
    {
        $source = new ThreatSource(['is_enabled' => 0]);
        $this->assertSame('off', $source->getHealth());
        $source->is_enabled = 1;
        $this->assertSame('waiting', $source->getHealth());
        $source->failures = 2;
        $this->assertSame('failing', $source->getHealth());
        $source->failures = 0;
        $source->last_success_at = '2026-11-01 10:00:00';
        $this->assertSame('ok', $source->getHealth());
        $this->assertSame('Vendor advisories', (new ThreatSource(['category' => 'advisory']))->getCategoryLabel());
        $this->assertFalse($source->getIsArchived());
        $this->assertSame('News source', ThreatSource::entityLabel());
        $this->assertSame('name', ThreatSource::titleAttribute());
        $this->assertSame('Last read', $source->getAttributeLabel('last_success_at'));
        $this->assertSame(['category'], array_keys($source->enumAttributes()));
        $this->assertContains('is_enabled', $source->booleanAttributes());
    }

    // ---- Enrichment --------------------------------------------------------------------

    private function storyWithCves(array $cves): void
    {
        $this->ingest($this->item('A story about ' . implode(' ', $cves), $cves));
    }

    private function enrich(): CveEnricher
    {
        return new CveEnricher($this->now);
    }

    public function testEpss(): void
    {
        $this->storyWithCves(['CVE-2026-9001', 'CVE-2026-9002']);
        $this->responses['api.first.org'] = ['status' => 200, 'body' => json_encode(['data' => [
            ['cve' => 'CVE-2026-9001', 'epss' => '0.94321', 'percentile' => '0.99'],
            ['cve' => 'CVE-2026-OTHER', 'epss' => '0.5', 'percentile' => '0.5'],
        ]])];
        $this->responses['services.nvd'] = ['status' => 200, 'body' => '{"vulnerabilities":[]}'];
        $this->assertSame(1, $this->enrich()->run());
        $cve = ThreatCve::findOne('CVE-2026-9001');
        $this->assertSame('94.3%', $cve->getEpssPercent());
        $this->assertNotNull($cve->epss_checked_at);
        $other = ThreatCve::findOne('CVE-2026-9002');
        $this->assertNull($other->getEpssPercent(), 'no score yet');
        $this->assertNotNull($other->epss_checked_at, 'looked at again tomorrow, not every run');

        $this->requested = [];
        $this->enrich()->run();
        $this->assertEmpty(array_filter($this->requested, static fn($u) => str_contains($u, 'api.first.org')));
        ThreatCve::updateAll(['epss_checked_at' => date('Y-m-d H:i:s', $this->now - CveEnricher::RECHECK - 60)]);
        $this->enrich()->run();
        $this->assertNotEmpty(array_filter($this->requested, static fn($u) => str_contains($u, 'api.first.org')), 'EPSS changes daily');
    }

    public function testEpssFailureIsAWarningOnly(): void
    {
        $this->storyWithCves(['CVE-2026-9003']);
        $this->responses['api.first.org'] = ['status' => 503, 'body' => ''];
        $this->responses['services.nvd'] = ['status' => 200, 'body' => '{}'];
        $enricher = $this->enrich();
        $this->assertSame(0, $enricher->run());
        $this->assertSame(['EPSS: HTTP 503'], $enricher->warnings);
        $this->assertNull(ThreatCve::findOne('CVE-2026-9003')->epss_checked_at, 'tried again next run');
        $this->responses['api.first.org'] = ['status' => 200, 'body' => '{"unexpected": true}'];
        $enricher = $this->enrich();
        $enricher->run();
        $this->assertSame(['EPSS: unexpected response'], $enricher->warnings);
    }

    public function testNvdFactsPreferTheNewestCvss(): void
    {
        $facts = CveEnricher::nvdFacts([
            'descriptions' => [['lang' => 'es', 'value' => 'Hola'], ['lang' => 'en', 'value' => 'An <b>English</b> text']],
            'metrics' => [
                'cvssMetricV2' => [['cvssData' => ['baseScore' => 5.0], 'baseSeverity' => 'MEDIUM']],
                'cvssMetricV31' => [['cvssData' => ['baseScore' => 9.8, 'baseSeverity' => 'CRITICAL']]],
            ],
        ]);
        $this->assertSame(['description' => 'An English text', 'cvss_score' => 9.8, 'cvss_severity' => 'critical'], $facts);
        $this->assertSame('medium', CveEnricher::nvdFacts(['metrics' => ['cvssMetricV2' => [['cvssData' => ['baseScore' => 5.0], 'baseSeverity' => 'MEDIUM']]]])['cvss_severity']);
        $this->assertSame([], CveEnricher::nvdFacts([]));
    }

    public function testNvdStaysInsideItsRateLimit(): void
    {
        $cves = array_map(static fn($n) => "CVE-2026-$n", range(9100, 9103));
        $this->storyWithCves($cves);
        $this->storyWithCves(['CVE-2026-9104']);
        $this->responses['api.first.org'] = ['status' => 200, 'body' => '{"data":[]}'];
        $this->responses['services.nvd'] = ['status' => 200, 'body' => json_encode(['vulnerabilities' => [['cve' => ['metrics' => ['cvssMetricV31' => [['cvssData' => ['baseScore' => 7.5, 'baseSeverity' => 'HIGH']]]]]]]])];
        $this->assertSame(CveEnricher::NVD_PER_RUN, $this->enrich()->run());
        $nvd = array_filter($this->requests, static fn($r) => str_contains($r['url'], 'services.nvd'));
        $this->assertCount(CveEnricher::NVD_PER_RUN, $nvd);
        $this->assertSame([], array_values($nvd)[0]['headers']);

        // With a key, more at a time, and the key goes in the header
        putenv('NVD_API_KEY=test-key');
        $this->assertSame('test-key', ThreatSettings::nvdApiKey());
        $this->requests = [];
        $this->enrich()->run();
        $nvd = array_values(array_filter($this->requests, static fn($r) => str_contains($r['url'], 'services.nvd')));
        $this->assertCount(1, $nvd, 'only the one still waiting');
        $this->assertSame(['apiKey: test-key'], $nvd[0]['headers']);
    }

    public function testNvdRetriesMissingScoresDailyAndSurvivesFailures(): void
    {
        $this->storyWithCves(['CVE-2026-9200']);
        $this->responses['api.first.org'] = ['status' => 200, 'body' => '{"data":[]}'];
        $this->responses['services.nvd'] = ['status' => 404, 'body' => ''];
        $this->enrich()->run();
        $this->assertNotNull(ThreatCve::findOne('CVE-2026-9200')->nvd_checked_at, 'not there yet is an answer');

        $this->requested = [];
        $this->enrich()->run();
        $this->assertEmpty(array_filter($this->requested, static fn($u) => str_contains($u, 'services.nvd')));

        ThreatCve::updateAll(['nvd_checked_at' => date('Y-m-d H:i:s', $this->now - CveEnricher::RECHECK - 60)]);
        $this->responses['services.nvd'] = ['status' => 429, 'body' => ''];
        $enricher = $this->enrich();
        $enricher->run();
        $this->assertStringContainsString('NVD: Rate limited', $enricher->warnings[0]);
        $this->assertLessThan(date('Y-m-d H:i:s', $this->now - 60), ThreatCve::findOne('CVE-2026-9200')->nvd_checked_at, 'a failure does not count as checked');
    }

    public function testNothingToEnrichMakesNoRequests(): void
    {
        $this->assertSame(0, $this->enrich()->run());
        $this->assertSame([], $this->requested);
        $this->storyWithCves(['CVE-2026-9300']);
        ThreatStory::updateAll(['last_seen_at' => date('Y-m-d H:i:s', $this->now - (CveEnricher::RECENT_DAYS + 1) * 86400)]);
        $this->assertSame(0, $this->enrich()->run());
        $this->assertSame([], $this->requested, 'old stories are left alone');
    }

    public function testCveHelpers(): void
    {
        $cve = new ThreatCve(['cve_id' => 'CVE-2026-1', 'epss_score' => '0.5']);
        $this->assertSame('50%', $cve->getEpssPercent());
        $this->assertSame('https://nvd.nist.gov/vuln/detail/CVE-2026-1', $cve->getNvdUrl());
        ThreatCve::ensure([]);
        $this->assertSame(0, (int) (new Query())->from('threat_cve')->where(['cve_id' => 'CVE-2026-1'])->count());
    }

    // ---- Custom feeds ------------------------------------------------------------------

    private function resolvesTo(string ...$ips): void
    {
        FeedUrlGuard::$resolver = static fn(string $host): array => $ips;
    }

    #[DataProvider('refusedUrls')]
    public function testCustomFeedAddressesThatAreRefused(string $url, array $ips, string $message): void
    {
        $this->resolvesTo(...$ips);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        FeedUrlGuard::check($url);
    }

    public static function refusedUrls(): array
    {
        $public = ['93.184.216.34'];
        return [
            'not a url' => ['feed', $public, 'full feed address'],
            'file' => ['file:///etc/passwd', $public, 'full feed address'],
            'plain http' => ['http://example.com/feed', $public, 'must use https'],
            'credentials' => ['https://user:pass@example.com/feed', $public, 'username or password'],
            'other port' => ['https://example.com:8443/feed', $public, 'standard https port'],
            'private literal' => ['https://10.0.0.1/feed', [], 'private or reserved'],
            'loopback literal' => ['https://127.0.0.1/feed', [], 'private or reserved'],
            'ipv6 loopback' => ['https://[::1]/feed', [], 'private or reserved'],
            'metadata service' => ['https://169.254.169.254/latest/meta-data/', [], 'private or reserved'],
            'name to private' => ['https://db/feed', ['172.18.0.2'], 'private or reserved'],
            'one private among public' => ['https://example.com/feed', ['93.184.216.34', '10.0.0.5'], 'private or reserved'],
            'unknown host' => ['https://nowhere.example/feed', [], 'could not be found'],
        ];
    }

    public function testCustomFeedRequestsArePinnedAndDoNotFollowRedirects(): void
    {
        $this->resolvesTo('93.184.216.34');
        $request = FeedUrlGuard::request('https://example.com:443/feed', 20);
        $this->assertFalse($request['follow']);
        $this->assertSame(['example.com:443:93.184.216.34'], $request['resolve']);

        $this->resolvesTo('2606:4700:4700::1111');
        $this->assertSame(['example.com:443:[2606:4700:4700::1111]'], FeedUrlGuard::request('https://example.com/feed', 20)['resolve']);

        $source = new ThreatSource(['url' => 'https://example.com/feed', 'is_custom' => 1]);
        $this->assertFalse(ThreatPuller::request($source)['follow']);
        $this->assertArrayNotHasKey('follow', ThreatPuller::request(new ThreatSource(['url' => 'https://example.com/feed', 'is_custom' => 0])));
    }

    public function testACustomFeedThatTurnsPrivateLaterIsNotFetched(): void
    {
        (new ThreatSource(['key' => 'custom-x', 'name' => 'Turned', 'category' => 'news', 'url' => 'https://turned.example.com/feed', 'is_custom' => 1, 'is_enabled' => 1, 'interval_minutes' => 60]))->save(false);
        $this->resolvesTo('10.1.2.3');
        $pull = $this->pull();
        $this->assertSame(ThreatPull::STATUS_PARTIAL, $pull->status);
        $this->assertNotContains('https://turned.example.com/feed', $this->requested);
        $this->assertSame('failing', $this->source('custom-x')->getHealth());
    }

    public function testARedirectIsReportedWithWhereItPoints(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('moved to https://elsewhere.example.com/rss');
        ThreatPuller::body(['status' => 301, 'body' => '', 'redirect' => 'https://elsewhere.example.com/rss']);
    }

    // ---- Console and scheduler ---------------------------------------------------------

    public function testConsolePullAndStatus(): void
    {
        $this->responses['bleepingcomputer'] = $this->feed('A story from the console today');
        [$code, $out] = OutputCapture::run('threat/pull', ['force' => 1]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('1 new articles', $out);

        [$code, $out] = OutputCapture::run('threat/status');
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('BleepingComputer', $out);
        $this->assertStringContainsString('Scheduled pulls are off', $out);
        $this->assertStringContainsString('NVD_API_KEY is not set', $out);
        $this->assertStringContainsString('Recent pulls', $out);

        putenv('THREAT_PULL_INTERVAL=20');
        putenv('NVD_API_KEY=x');
        [, $out] = OutputCapture::run('threat/status');
        $this->assertStringContainsString('every 20 minutes', $out);
        $this->assertStringContainsString('NVD_API_KEY is set', $out);
    }

    public function testConsoleReportsProblems(): void
    {
        [$code, $out] = OutputCapture::run('threat/pull', ['user' => 'nobody-here']);
        $this->assertSame(ExitCode::DATAERR, $code);
        $this->assertStringContainsString('not found', $out);

        $this->responses['bleepingcomputer'] = ['status' => 500, 'body' => ''];
        [$code, $out] = OutputCapture::run('threat/pull', ['force' => 1]);
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('BleepingComputer: HTTP 500', $out);

        $this->responses = ['http' => ['status' => 500, 'body' => '']];
        [$code, $out] = OutputCapture::run('threat/pull', ['force' => 1]);
        $this->assertSame(ExitCode::UNAVAILABLE, $code);
        $this->assertStringContainsString('Pull failed', $out);

        $mutex = new FileMutex();
        $mutex->acquire(ThreatPuller::MUTEX);
        try {
            [$code] = OutputCapture::run('threat/pull');
            $this->assertSame(ExitCode::TEMPFAIL, $code);
        } finally {
            $mutex->release(ThreatPuller::MUTEX);
        }
    }

    public function testConsoleRecordsTheUser(): void
    {
        $user = \app\models\User::findOne($this->userId());
        OutputCapture::run('threat/pull', ['user' => $user->username]);
        $this->assertSame($user->id, (int) ThreatPull::find()->orderBy(['id' => SORT_DESC])->one()->run_by);
    }

    public function testTheSchedulerRunsThePull(): void
    {
        $controller = new ScheduleController('schedule', Yii::$app);
        $controller->once = true;
        $this->assertSame('THREAT_PULL_INTERVAL=0', $controller->jobs()['threat-pull']['off']);
        OutputCapture::start();
        $this->assertNotContains('threat-pull', $controller->tick($this->now));
        OutputCapture::stop();

        putenv('THREAT_PULL_INTERVAL=30');
        $this->assertSame(30, ThreatSettings::pullInterval());
        $job = $controller->jobs()['threat-pull'];
        $this->assertNull($job['off']);
        $this->assertSame(30, $job['interval']);
        OutputCapture::start();
        $ran = $controller->tick($this->now);
        $out = OutputCapture::stop();
        $this->assertContains('threat-pull', $ran);
        $this->assertStringContainsString('threat-pull ok', $out);

        $mutex = new FileMutex();
        $mutex->acquire(ThreatPuller::MUTEX);
        OutputCapture::start();
        try {
            $controller->tick($this->now + 3600);
        } finally {
            $out = OutputCapture::stop();
            $mutex->release(ThreatPuller::MUTEX);
        }
        $this->assertStringContainsString('threat-pull skipped', $out);
    }

    public function testIntervalDefaultsAndLimits(): void
    {
        putenv('THREAT_PULL_INTERVAL');
        $this->assertSame(ThreatSettings::DEFAULT_INTERVAL, ThreatSettings::pullInterval());
        putenv('THREAT_PULL_INTERVAL=99999');
        $this->assertSame(1440, ThreatSettings::pullInterval());
        putenv('NVD_API_KEY=   ');
        $this->assertNull(ThreatSettings::nvdApiKey());
    }
}
