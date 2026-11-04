<?php

namespace tests\unit;

use app\models\AuditLog;
use app\models\Organisation;
use app\modules\cloud\models\CloudAccount;
use app\modules\inventory\models\Device;
use app\modules\supplier\models\Supplier;
use app\modules\threat\components\StoryScorer;
use app\modules\threat\components\ThreatPuller;
use app\modules\threat\components\Watchlist;
use app\modules\threat\models\ThreatArticle;
use app\modules\threat\models\ThreatCve;
use app\modules\threat\models\ThreatSearch;
use app\modules\threat\models\ThreatSource;
use app\modules\threat\models\ThreatStory;
use app\modules\threat\models\ThreatTriage;
use app\modules\threat\models\ThreatWatchTerm;
use PHPUnit\Framework\Attributes\DataProvider;
use tests\DbTestCase;
use Yii;

/**
 * How stories rank, the watchlist that drives it, and that one organisation's watchlist
 * and decisions never reach another.
 */
class ThreatScoringTest extends DbTestCase
{
    private int $now;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = time();
        ThreatWatchTerm::deleteAll();
    }

    protected function tearDown(): void
    {
        Organisation::switchTo(null);
        parent::tearDown();
    }

    private function term(string $term, int $organisation = Organisation::DEFAULT_ID, string $origin = 'manual', int $enabled = 1): ThreatWatchTerm
    {
        $model = new ThreatWatchTerm(['organisation_id' => $organisation, 'term' => $term, 'category' => 'other', 'origin' => $origin, 'is_enabled' => $enabled]);
        $model->save(false);
        return $model;
    }

    private function story(string $title, array $cves = [], string $source = 'bleepingcomputer', string $excerpt = ''): ThreatStory
    {
        $this->n++;
        $puller = new ThreatPuller();
        $puller->now = $this->now;
        $guid = "score-{$this->n}";
        $puller->ingest(ThreatSource::findOne(['key' => $source]), [[
            'guid' => $guid, 'title' => $title, 'url' => "https://news.example.com/{$this->n}", 'excerpt' => $excerpt,
            'cves' => $cves, 'published_at' => $this->now - 60, 'kev' => null,
        ]]);
        return ThreatArticle::find()->where(['guid_hash' => hash('sha256', $guid)])->one()->story;
    }

    private function evaluate(ThreatStory $story, array $terms = ['Acme']): array
    {
        return StoryScorer::evaluate(ThreatStory::findOne($story->id), new Watchlist($terms));
    }

    private function kev(string $id, array $extra = []): void
    {
        ThreatCve::updateAll($extra + ['kev_added_on' => date('Y-m-d', $this->now)], ['cve_id' => $id]);
    }

    public function testExploitedAndOnTheWatchlistIsCritical(): void
    {
        $result = $this->evaluate($story = $this->story('Acme gateway flaw patched', ['CVE-2026-1']));
        $this->assertSame('medium', $result['priority'], 'on the watchlist only, until the wording or KEV says more');

        $this->kev('CVE-2026-1');
        $result = $this->evaluate($story);
        $this->assertSame('critical', $result['priority']);
        $this->assertTrue($result['matched']);
        $this->assertTrue($result['exploited']);
        $this->assertSame(['Acme'], $result['terms']);
        $this->assertSame('kev', $result['signals'][0]['key'], 'strongest first');

        $this->assertSame('critical', $this->evaluate($this->story('Acme gateway zero-day exploited in the wild'))['priority'], 'reported is enough');
    }

    public function testAKevProductNameCountsAsAMatch(): void
    {
        $story = $this->story('CVE-2026-2: Input Validation Flaw', ['CVE-2026-2'], 'cisa-kev');
        $this->kev('CVE-2026-2', ['vendor' => 'Acme', 'product' => 'Gateway']);
        $this->assertSame('critical', $this->evaluate($story)['priority']);
    }

    public function testKevAloneIsHighAndRansomwareIsShown(): void
    {
        $story = $this->story('Something else entirely', ['CVE-2026-3']);
        $this->kev('CVE-2026-3', ['kev_ransomware' => 1]);
        $result = $this->evaluate($story);
        $this->assertSame('high', $result['priority']);
        $this->assertContains('ransomware', array_column($result['signals'], 'key'));
    }

    public function testSeverityRaisesMatchedStories(): void
    {
        $story = $this->story('Acme patches a flaw', ['CVE-2026-4']);
        ThreatCve::updateAll(['cvss_score' => 9.8], ['cve_id' => 'CVE-2026-4']);
        $this->assertSame('high', $this->evaluate($story)['priority']);
        ThreatCve::updateAll(['cvss_score' => 7.5, 'epss_score' => .6], ['cve_id' => 'CVE-2026-4']);
        $this->assertSame('high', $this->evaluate($story)['priority'], 'likely exploited also counts');
        ThreatCve::updateAll(['cvss_score' => 7.5, 'epss_score' => .2], ['cve_id' => 'CVE-2026-4']);
        $result = $this->evaluate($story);
        $this->assertSame('medium', $result['priority']);
        $this->assertSame(['watchlist', 'cvss', 'epss'], array_column($result['signals'], 'key'));
    }

    public function testWithoutAMatchSeriousThingsAreMediumAndTheRestLow(): void
    {
        $this->assertSame('medium', $this->evaluate($this->story('Unknown product zero-day exploited in the wild'))['priority']);
        $story = $this->story('Another product patched today', ['CVE-2026-5']);
        ThreatCve::updateAll(['cvss_score' => 9.1], ['cve_id' => 'CVE-2026-5']);
        $this->assertSame('medium', $this->evaluate($story)['priority']);
        $result = $this->evaluate($this->story('Conference season is here again'));
        $this->assertSame('low', $result['priority']);
        $this->assertSame(0, $result['score']);
        $this->assertSame([], $result['signals']);
    }

    public function testAMentionInPassingIsShownButDoesNotRaiseThePriority(): void
    {
        $result = $this->evaluate($this->story('Market report on endpoint tools', [], 'bleepingcomputer', 'Vendors such as Acme are adding features.'));
        $this->assertSame('low', $result['priority']);
        $this->assertFalse($result['matched']);
        $this->assertSame(['Acme'], $result['terms']);
        $this->assertSame('mention', $result['signals'][0]['key']);
    }

    public function testRoundupsAreNotRaisedByTheirCves(): void
    {
        $story = $this->story('Weekly Recap: lots happened', ['CVE-2026-6']);
        $this->kev('CVE-2026-6', ['vendor' => 'Acme']);
        ThreatCve::updateAll(['cvss_score' => 10, 'epss_score' => .9], ['cve_id' => 'CVE-2026-6']);
        $result = $this->evaluate($story);
        $this->assertSame('low', $result['priority']);
        $this->assertFalse($result['exploited']);
    }

    public function testARoundupHeadlineDoesNotRankItEither(): void
    {
        $result = $this->evaluate($this->story('Weekly Recap: Acme attacks, Globex zero-day and more'));
        $this->assertSame('low', $result['priority'], 'mentioning something is not being about it');
        $this->assertFalse($result['matched']);
        $this->assertFalse($result['exploited']);
        $this->assertSame(['Acme'], $result['terms'], 'still shown as a mention');
        $this->assertSame(['mention'], array_column($result['signals'], 'key'));
    }

    public function testCvesFromSeveralVendorsMeanItIsNotAboutAnyOfThem(): void
    {
        // A real vendor blog post citing other people's KEV entries as examples topped the queue at 100
        $story = $this->story('The race against the clock: meeting the new directive', ['CVE-2026-21', 'CVE-2026-22', 'CVE-2026-23']);
        foreach (['CVE-2026-21' => 'Acme', 'CVE-2026-22' => 'Globex', 'CVE-2026-23' => 'Initech'] as $id => $vendor) {
            $this->kev($id, ['vendor' => $vendor]);
        }
        $this->assertTrue(StoryScorer::isBroad(ThreatStory::findOne($story->id)));
        $result = $this->evaluate($story);
        $this->assertSame('low', $result['priority']);
        $this->assertFalse($result['exploited']);

        // Several bugs from one vendor are one event: attackers chaining flaws
        $chain = $this->story('Attackers chain Acme flaws to gain root', ['CVE-2026-24', 'CVE-2026-25', 'CVE-2026-26', 'CVE-2026-27']);
        foreach (['CVE-2026-24', 'CVE-2026-25', 'CVE-2026-26', 'CVE-2026-27'] as $id) {
            $this->kev($id, ['vendor' => 'Acme']);
        }
        $this->assertFalse(StoryScorer::isBroad(ThreatStory::findOne($chain->id)));
        $this->assertSame('critical', $this->evaluate($chain)['priority']);
    }

    public function testWideCoverageAddsToTheScore(): void
    {
        $story = $this->story('Conference season is here again');
        ThreatStory::updateAll(['source_count' => 3], ['id' => $story->id]);
        $this->assertSame(5, $this->evaluate($story)['score']);
        ThreatStory::updateAll(['source_count' => 6], ['id' => $story->id]);
        $this->assertSame(10, $this->evaluate($story)['score']);
    }

    public function testTheScoreIsCapped(): void
    {
        $story = $this->story('Acme zero-day exploited in the wild', ['CVE-2026-7']);
        $this->kev('CVE-2026-7', ['kev_ransomware' => 1, 'cvss_score' => 10, 'epss_score' => .9]);
        ThreatStory::updateAll(['source_count' => 8], ['id' => $story->id]);
        $this->assertSame(100, $this->evaluate($story)['score']);
    }

    public function testBackgroundAdvisoriesStayOutOfTheQueueUnlessTheyMatter(): void
    {
        // A broad term matches a hundred advisories every Patch Tuesday: matching alone is not enough
        $story = $this->story('CVE-2026-8 Acme Kernel Elevation of Privilege', ['CVE-2026-8'], 'msrc');
        $result = $this->evaluate($story);
        $this->assertTrue($result['matched']);
        $this->assertTrue($result['hidden']);

        ThreatCve::updateAll(['cvss_score' => 9.8], ['cve_id' => 'CVE-2026-8']);
        $this->assertFalse($this->evaluate($story)['hidden'], 'severe and yours');

        $unmatched = $this->story('CVE-2026-9 Some Other Thing', ['CVE-2026-9'], 'msrc');
        ThreatCve::updateAll(['cvss_score' => 9.8], ['cve_id' => 'CVE-2026-9']);
        $this->assertTrue($this->evaluate($unmatched)['hidden'], 'severe but not yours');
        $this->kev('CVE-2026-9');
        $this->assertFalse($this->evaluate($unmatched)['hidden'], 'being exploited always shows');

        $this->assertFalse($this->evaluate($this->story('A normal article with words'))['hidden']);
    }

    // ---- Writing the results -----------------------------------------------------------

    private function triage(ThreatStory $story, int $organisation = Organisation::DEFAULT_ID): ThreatTriage
    {
        return ThreatTriage::find()->forOrganisation($organisation)->andWhere(['story_id' => $story->id])->one();
    }

    public function testRunAddsAndUpdatesTriageWithoutTouchingDecisions(): void
    {
        $this->term('Acme');
        $story = $this->story('Acme patches a flaw', ['CVE-2026-10']);
        $this->assertSame(['scored' => 1, 'reopened' => 0], StoryScorer::run(Organisation::DEFAULT_ID, $this->now));
        $triage = $this->triage($story);
        $this->assertSame('new', $triage->status);
        $this->assertSame('medium', $triage->priority);
        $this->assertSame(['Acme'], $triage->getMatchedTermList());
        $this->assertNotEmpty($triage->getSignalList());
        $this->assertSame(sprintf('TW-%04d', $triage->id), $triage->getReferenceValue());
        $this->assertSame('Acme patches a flaw', $triage->getTitleValue());

        $triage->resolve(ThreatTriage::STATUS_RELEVANT, 'We run this.');
        $this->kev('CVE-2026-10');
        StoryScorer::run(Organisation::DEFAULT_ID, $this->now);
        $triage = $this->triage($story);
        $this->assertSame('critical', $triage->priority);
        $this->assertSame('relevant', $triage->status, 'a decision survives re-ranking');
        $this->assertSame('We run this.', $triage->note);
    }

    public function testADismissedStoryComesBackWhenItBecomesUrgent(): void
    {
        $this->term('Acme');
        $story = $this->story('Acme patches a flaw', ['CVE-2026-11']);
        StoryScorer::run(Organisation::DEFAULT_ID, $this->now);
        $this->triage($story)->resolve(ThreatTriage::STATUS_DISMISSED);

        StoryScorer::run(Organisation::DEFAULT_ID, $this->now);
        $this->assertSame('dismissed', $this->triage($story)->status, 'nothing changed');

        $this->kev('CVE-2026-11');
        $this->assertSame(1, StoryScorer::run(Organisation::DEFAULT_ID, $this->now)['reopened']);
        $triage = $this->triage($story);
        $this->assertSame('new', $triage->status);
        $this->assertNull($triage->resolved_at);
        $this->assertStringContainsString('priority rose to critical', $triage->note);
        $this->assertTrue(AuditLog::find()->where(['entity_type' => 'threat-story', 'entity_id' => $triage->id, 'action' => 'update'])->exists());
    }

    public function testAMinorRiseDoesNotReopenADismissedStory(): void
    {
        $story = $this->story('A low story about nothing much');
        StoryScorer::run(Organisation::DEFAULT_ID, $this->now);
        $this->triage($story)->resolve(ThreatTriage::STATUS_DISMISSED);
        $this->term('nothing much');
        $this->assertSame(0, StoryScorer::run(Organisation::DEFAULT_ID, $this->now)['reopened']);
        $triage = $this->triage($story);
        $this->assertSame('medium', $triage->priority);
        $this->assertSame('dismissed', $triage->status);
    }

    public function testScoringIsNotAuditedButDecisionsAre(): void
    {
        $story = $this->story('Something to rank today');
        StoryScorer::run(Organisation::DEFAULT_ID, $this->now);
        $triage = $this->triage($story);
        $this->assertFalse(AuditLog::find()->where(['entity_type' => 'threat-story'])->exists());
        $triage->resolve(ThreatTriage::STATUS_RELEVANT);
        $entry = AuditLog::find()->where(['entity_type' => 'threat-story', 'entity_id' => $triage->id])->one();
        $this->assertArrayHasKey('status', $entry->getChangesArray());
        $this->assertStringContainsString('Something to rank today', $entry->entity_label);
        $triage->reopen();
        $this->assertNull($triage->resolved_by);
    }

    public function testOldStoriesAreLeftAlone(): void
    {
        $story = $this->story('An old story nobody needs');
        ThreatStory::updateAll(['last_seen_at' => date('Y-m-d H:i:s', $this->now - (StoryScorer::WINDOW_DAYS + 1) * 86400)], ['id' => $story->id]);
        $this->assertSame(0, StoryScorer::run(Organisation::DEFAULT_ID, $this->now)['scored']);
    }

    // ---- Organisations -----------------------------------------------------------------

    private function secondOrganisation(): int
    {
        $now = date('Y-m-d H:i:s');
        Yii::$app->db->createCommand()->insert('organisation', ['name' => 'Second', 'created_at' => $now, 'updated_at' => $now])->execute();
        return (int) Yii::$app->db->getLastInsertID();
    }

    public function testTheSameStoryRanksDifferentlyForEachOrganisation(): void
    {
        $second = $this->secondOrganisation();
        $this->assertSame([Organisation::DEFAULT_ID, $second], Organisation::allIds());
        $this->term('Acme');
        $this->term('Other', $second);
        $story = $this->story('Acme patches a flaw');
        StoryScorer::run(Organisation::DEFAULT_ID, $this->now);
        StoryScorer::run($second, $this->now);

        $this->assertSame('medium', $this->triage($story)->priority);
        $this->assertSame('low', $this->triage($story, $second)->priority);

        $this->triage($story)->resolve(ThreatTriage::STATUS_DISMISSED);
        $this->assertSame('new', $this->triage($story, $second)->status, 'decisions are not shared');
    }

    public function testQueriesOnlySeeTheCurrentOrganisation(): void
    {
        $second = $this->secondOrganisation();
        $story = $this->story('Something to see here today');
        StoryScorer::run(Organisation::DEFAULT_ID, $this->now);
        $this->assertSame(1, ThreatSearch::counts()['new']);

        Organisation::switchTo($second);
        $this->assertSame($second, Organisation::currentId());
        $this->assertSame(0, ThreatSearch::counts()['new']);
        $this->assertNull(ThreatTriage::find()->forOrganisation()->andWhere(['story_id' => $story->id])->one());
        $this->assertSame(0, (new ThreatSearch())->search([])->getTotalCount());

        Organisation::switchTo(null);
        $this->assertSame(Organisation::DEFAULT_ID, Organisation::currentId());
    }

    public function testTermsBelongToAnOrganisation(): void
    {
        $second = $this->secondOrganisation();
        $term = $this->term('Acme');
        $this->term('Acme', $second);
        $this->assertSame(['Acme'], (new Watchlist(['Acme']))->match('Acme'));
        Organisation::switchTo($second);
        $this->assertNull(ThreatWatchTerm::findOwn($term->id));
        Organisation::switchTo(null);
        $this->assertSame($term->id, ThreatWatchTerm::findOwn($term->id)->id);
        $duplicate = new ThreatWatchTerm(['organisation_id' => Organisation::DEFAULT_ID, 'term' => 'acme', 'category' => 'other', 'origin' => 'manual']);
        $this->assertFalse($duplicate->validate());
    }

    // ---- Watchlist ---------------------------------------------------------------------

    public function testMatching(): void
    {
        $watchlist = new Watchlist(['Duo', 'AWS', 'Palo Alto', 'PAN-OS', 'C++', 'Windows', 'Okta']);
        $this->assertSame(['Palo Alto', 'PAN-OS'], $watchlist->match('palo alto fixes PAN-OS'));
        $this->assertSame(['Duo'], $watchlist->match('Duo fixes a bug'));
        $this->assertSame([], $watchlist->match('the duo were arrested'), 'short terms match their exact case');
        $this->assertSame([], $watchlist->match('laws and paws'), 'whole words only');
        $this->assertSame(['AWS'], $watchlist->match('Breach at AWS, says report'));
        $this->assertSame(['C++'], $watchlist->match('A C++ library'), 'terms are not regular expressions');
        $this->assertSame([], $watchlist->match('Windowsill repairs'));
        $this->assertSame(['Windows'], $watchlist->match('WINDOWS update breaks printing'), 'longer terms ignore case');
    }

    public function testDisabledTermsDoNotMatch(): void
    {
        $this->term('Acme', Organisation::DEFAULT_ID, 'manual', 0);
        $this->term('Other');
        $watchlist = Watchlist::forOrganisation(Organisation::DEFAULT_ID);
        $this->assertSame([], $watchlist->match('Acme'));
        $this->assertSame(['Other'], $watchlist->match('Other'));
    }

    #[DataProvider('operatingSystems')]
    public function testOsFamily(string $os, ?string $family): void
    {
        $this->assertSame($family, Watchlist::osFamily($os));
    }

    public static function operatingSystems(): array
    {
        return [
            ['macOS 14.2', 'macOS'], ['Mac OS X', 'macOS'], ['MacOS', 'macOS'], ['iOS 18', 'iOS'], ['iPadOS 17', 'iPadOS'],
            ['Windows 11', 'Windows'], ['Windows Server 2012 R2', 'Windows Server'], ['Android 14', 'Android'], ['Ubuntu 22.04', 'Ubuntu'],
            ['Unknown', null], ['', null], ['Other', null],
        ];
    }

    public function testSupplierName(): void
    {
        $this->assertSame('Acme Hosting', Watchlist::supplierName('Acme Hosting Ltd.'));
        $this->assertSame('Amazon Web Services', Watchlist::supplierName('Amazon Web Services EMEA SARL'));
        $this->assertSame('Globex', Watchlist::supplierName('Globex, Inc'));
        $this->assertNull(Watchlist::supplierName('X Ltd'), 'nothing usable left');
    }

    private function device(string $type, string $make, string $os, string $status = Device::STATUS_ACTIVE): void
    {
        $this->n++;
        $now = date('Y-m-d H:i:s');
        Yii::$app->db->createCommand()->insert('device', ['asset_tag' => "TW-{$this->n}", 'name' => "Device {$this->n}", 'device_type' => $type, 'ownership' => 'company', 'manufacturer' => $make, 'os' => $os,
            'status' => $status, 'criticality' => 'low', 'data_classification' => 'internal', 'created_at' => $now, 'updated_at' => $now])->execute();
    }

    private function clearRegisters(): void
    {
        foreach ([Device::tableName(), CloudAccount::tableName(), Supplier::tableName()] as $table) {
            Yii::$app->db->createCommand()->delete($table)->execute();
        }
    }

    public function testTermsComeFromWhatTheOrganisationRuns(): void
    {
        $this->clearRegisters();
        $this->device('laptop', 'Apple', 'macOS 14');
        $this->device('mobile', 'Google', 'Android 14');
        $this->device('firewall', 'Fortinet', 'FortiOS 7.4');
        $this->device('server', 'Dell', 'Unknown');
        $this->device('server', 'Retired Corp', 'OpenBSD', 'retired');
        $now = date('Y-m-d H:i:s');
        Yii::$app->db->createCommand()->insert('cloud_account', ['reference' => 'ACC-T1', 'name' => 'Main', 'provider' => 'aws', 'environment' => 'production', 'status' => 'active', 'criticality' => 'high', 'data_classification' => 'internal', 'created_at' => $now, 'updated_at' => $now])->execute();

        $terms = Watchlist::derived();
        $this->assertSame(['device', 'device'], [$terms['macOS'][1], $terms['Android'][1]]);
        $this->assertArrayHasKey('Fortinet', $terms, 'the maker of a firewall says what is running');
        $this->assertArrayHasKey('FortiOS', $terms);
        $this->assertArrayHasKey('Dell', $terms);
        $this->assertArrayNotHasKey('Apple', $terms, 'a laptop brand does not: "Apple" or "Google" would match too much');
        $this->assertArrayNotHasKey('Google', $terms);
        $this->assertArrayNotHasKey('Retired Corp', $terms);
        $this->assertArrayNotHasKey('OpenBSD', $terms);
        $this->assertSame('cloud', current(array_filter($terms, static fn($t) => $t[0] === 'platform'))[1]);
    }

    public function testSyncAddsRemovesAndRespectsDecisions(): void
    {
        $this->clearRegisters();
        $this->device('firewall', 'Fortinet', 'FortiOS');
        $this->term('fortinet', Organisation::DEFAULT_ID, 'starter');
        $this->assertSame(['added' => 1, 'removed' => 0], Watchlist::sync(Organisation::DEFAULT_ID), 'FortiOS is new; Fortinet is already there in any case');
        $this->assertSame('starter', ThreatWatchTerm::findOne(['term' => 'fortinet'])->origin);
        $this->assertSame('device', ThreatWatchTerm::findOne(['term' => 'FortiOS'])->origin);

        $this->term('Gone', Organisation::DEFAULT_ID, 'device');
        $this->term('Muted', Organisation::DEFAULT_ID, 'supplier', 0);
        $this->term('Mine');
        $this->assertSame(['added' => 0, 'removed' => 1], Watchlist::sync(Organisation::DEFAULT_ID));
        $this->assertNull(ThreatWatchTerm::findOne(['term' => 'Gone']));
        $this->assertNotNull(ThreatWatchTerm::findOne(['term' => 'Muted']), 'switched off is a decision worth keeping');
        $this->assertNotNull(ThreatWatchTerm::findOne(['term' => 'Mine']));
    }

    public function testCountsAfterArticlesGo(): void
    {
        $story = $this->story('A story from one source only');
        $story->refreshCounts();
        $this->assertSame(1, (int) $story->article_count);
        ThreatArticle::deleteAll(['story_id' => $story->id]);
        $story->refreshCounts();
        $this->assertNull(ThreatStory::findOne($story->id), 'nothing left to show');
    }
}
