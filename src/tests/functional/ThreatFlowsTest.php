<?php

namespace tests\functional;

use app\models\AuditLog;
use app\models\Organisation;
use app\modules\intel\components\Http;
use app\modules\threat\components\FeedUrlGuard;
use app\modules\threat\models\ThreatArticle;
use app\modules\threat\models\ThreatPull;
use app\modules\threat\models\ThreatSource;
use app\modules\threat\models\ThreatStory;
use app\modules\threat\models\ThreatTriage;
use app\modules\threat\models\ThreatWatchTerm;
use tests\FunctionalTestCase;
use Yii;

/**
 * Threat Watch pages on the demo data: the queue, a story, triage, the watchlist and
 * sources, who may do what, and what other organisations can reach. No real feed is read.
 */
class ThreatFlowsTest extends FunctionalTestCase
{
    protected static bool $seed = true;

    protected function tearDown(): void
    {
        Http::$fake = null;
        FeedUrlGuard::$resolver = null;
        Organisation::switchTo(null);
        parent::tearDown();
    }

    private function triage(string $title): ThreatTriage
    {
        return ThreatTriage::find()->forOrganisation()->joinWith('story')->andWhere(['like', 'threat_story.title', $title])->one();
    }

    public function testDemoDataRanksThroughThePipeline(): void
    {
        $northgate = $this->triage('Northgate VPN zero-day');
        $this->assertSame('critical', $northgate->priority);
        $this->assertSame(3, (int) $northgate->story->article_count, 'two reports and the KEV entry, but not the weekly recap');
        $this->assertSame('high', $this->triage('Tallowood')->priority);
        $this->assertSame('relevant', $this->triage('Larkspur Mail')->status);
        $this->assertSame('dismissed', $this->triage('Phishing volumes')->status);
        $this->assertSame(1, (int) $this->triage('Weekly Recap')->story->is_roundup);
        $this->assertSame(1, (int) $this->triage('CVE-2026-90005')->is_hidden);
    }

    public function testQueueAndFilters(): void
    {
        $this->loginAs('viewer');
        $this->get('/threat');
        $this->assertOk();
        $this->assertSee('Threat Watch');
        $this->assertSee('Northgate VPN zero-day');
        $this->assertSee('On your watchlist');
        $this->assertNotSee('Phishing volumes', 'dismissed stories leave the queue');
        $this->assertNotSee('CVE-2026-90005 Example', 'background advisories too');
        $this->assertNotSee('Dismiss low priority');
        $this->assertNotSee('id="threat-bulk"');

        foreach (['urgent', 'exploited', 'matched', 'relevant', 'dismissed', 'all', 'background'] as $filter) {
            $this->get('/threat?filter=' . $filter);
            $this->assertOk();
        }
        $this->get('/threat?filter=background');
        $this->assertSee('CVE-2026-90005 Example');
        $this->get('/threat?filter=dismissed');
        $this->assertSee('Phishing volumes');
        $this->get('/threat?filter=all&q=CVE-2026-90002&sort=-last_seen_at');
        $this->assertSee('Tallowood');
        $this->assertNotSee('Larkspur');
        $this->get('/threat?filter=all&priority=critical&sort=-priority');
        $this->assertSee('Northgate');
        $this->assertNotSee('Quillon');
        $this->get('/threat?filter=bogus');
        $this->assertSee('No stories match');
    }

    public function testEmptyQueueAndPullNotices(): void
    {
        $this->loginAs('viewer');
        ThreatPull::deleteAll();
        $this->get('/threat');
        $this->assertSee('Nothing has been read yet');

        (new ThreatPull(['started_at' => date('Y-m-d H:i:s'), 'status' => ThreatPull::STATUS_ERROR, 'message' => 'All down']))->save(false);
        $this->get('/threat');
        $this->assertSee('The last read failed: All down');

        ThreatPull::deleteAll();
        (new ThreatPull(['started_at' => date('Y-m-d H:i:s'), 'status' => ThreatPull::STATUS_PARTIAL]))->save(false);
        ThreatSource::updateAll(['failures' => 2], ['key' => 'krebs']);
        $this->get('/threat');
        $this->assertSee('1 source is failing');

        ThreatTriage::deleteAll();
        $this->get('/threat');
        $this->assertSee('Nothing to triage');
    }

    public function testStoryPage(): void
    {
        $this->loginAs('viewer');
        $model = $this->triage('Northgate VPN zero-day');
        $this->get('/threat/' . $model->id);
        $this->assertOk();
        $this->assertSee('Why it ranks here');
        $this->assertSee('On your watchlist: Northgate VPN');
        $this->assertSee('CVE-2026-90001');
        $this->assertSee('94.3%');
        $this->assertSee('9.8');
        $this->assertSee('CISA deadline');
        $this->assertSee('rel="noopener noreferrer nofollow"');
        $this->assertSee('news.example.com');
        $this->assertNotSee('Decide with a note');

        $roundup = $this->triage('Weekly Recap');
        $this->get('/threat/' . $roundup->id);
        $this->assertSee('roundup of several events');

        $quiet = $this->triage('Phishing volumes');
        $this->get('/threat/' . $quiet->id);
        $this->assertSee('Background reading');
        $this->assertSee('Decided');
        $this->assertSee('Nothing raises this story');

        $this->get('/threat/999999');
        $this->assertStatus(404);
    }

    public function testFeedTextIsNeverRenderedAsMarkup(): void
    {
        $this->loginAs('analyst');
        $model = $this->triage('Quillon');
        ThreatStory::updateAll(['title' => '<img src=x onerror=alert(1)>', 'summary' => '<script>alert(2)</script>'], ['id' => $model->story_id]);
        ThreatArticle::updateAll(['title' => '<b onclick=x>article</b>', 'excerpt' => '<script>alert(3)</script>'], ['story_id' => $model->story_id]);
        foreach (['/threat?filter=all&q=' . urlencode('onerror'), '/threat/' . $model->id] as $url) {
            $this->get($url);
            $this->assertOk();
            $this->assertNotSee('<img src=x');
            $this->assertNotSee('<script>alert(');
            $this->assertSee('&lt;img src=x onerror=alert(1)&gt;');
        }
        $this->get('/threat/' . $model->id);
        $this->assertNotSee('<b onclick');
    }

    public function testTriageActions(): void
    {
        $this->loginAs('analyst');
        $model = $this->triage('Quillon');
        $url = '/threat/' . $model->id;

        $this->post("$url/dismiss", ['note' => 'Not our printer.']);
        $this->assertRedirect($url);
        $model->refresh();
        $this->assertSame('dismissed', $model->status);
        $this->assertSame('Not our printer.', $model->note);
        $this->assertSame($this->userIdFor('analyst'), (int) $model->resolved_by);
        $this->get($url);
        $this->assertSee('Not our printer.');
        $this->assertSee('Reopen');

        $this->post("$url/reopen");
        $model->refresh();
        $this->assertSame('new', $model->status);
        $this->post("$url/relevant", ['note' => ['not', 'a', 'string']]);
        $model->refresh();
        $this->assertSame('relevant', $model->status);
        $this->assertTrue(AuditLog::find()->where(['entity_type' => 'threat-story', 'entity_id' => $model->id])->exists());
        $this->get($url);
        $this->assertSee('History');

        $this->get($url . '/dismiss');
        $this->assertStatus(405);
    }

    public function testDismissLow(): void
    {
        $this->loginAs('analyst');
        $this->get('/threat');
        $this->assertSee('Dismiss low priority');
        $low = (int) ThreatTriage::find()->forOrganisation()->open()->andWhere(['threat_triage.priority' => 'low'])->count();
        $this->assertGreaterThan(0, $low);
        $this->post('/threat/dismiss-low');
        $this->assertRedirect('/threat');
        $this->assertStringContainsString("$low low-priority", $this->flash('info'));
        $this->assertSame(0, (int) ThreatTriage::find()->forOrganisation()->open()->andWhere(['threat_triage.priority' => 'low'])->count());
        $this->assertSame('critical', $this->triage('Northgate VPN zero-day')->priority);
        $this->assertSame('new', $this->triage('Northgate VPN zero-day')->status);
    }

    public function testBulkActions(): void
    {
        $this->loginAs('analyst');
        $a = $this->triage('Quillon');
        $b = $this->triage('Tallowood');
        $this->get('/threat');
        $this->assertSee('id="threat-bulk"');
        $this->assertSee('data-gw-bulk-row');
        $this->assertNotSee('value="reopen"');

        $this->post('/threat/bulk', ['do' => 'dismiss', 'ids' => [$a->id, $b->id], 'filter' => 'new']);
        $this->assertRedirect('filter=new');
        $this->assertStringContainsString('2 stories dismissed', $this->flash('info'));
        $this->assertSame('dismissed', ThreatTriage::findOne($a->id)->status);
        $this->get('/threat?filter=dismissed');
        $this->assertSee('value="reopen"');
    }

    public function testBulkEdgeCases(): void
    {
        $this->loginAs('analyst');
        $a = $this->triage('Quillon');

        $this->post('/threat/bulk', ['do' => 'explode', 'ids' => [$a->id]]);
        $this->assertStringContainsString('Choose an action', $this->flash('warning'));
        $this->post('/threat/bulk', ['do' => ['dismiss'], 'ids' => [$a->id]]);
        $this->assertStringContainsString('Choose an action', $this->flash('warning'));
        $this->post('/threat/bulk', ['do' => 'dismiss', 'ids' => []]);
        $this->assertStringContainsString('No stories selected', $this->flash('warning'));

        $this->post('/threat/bulk', ['do' => 'reopen', 'ids' => [$a->id]]);
        $this->assertStringContainsString('0 stories reopened. 1 already there', $this->flash('info'));

        $this->post('/threat/bulk', ['do' => 'relevant', 'ids' => [$a->id]]);
        $this->assertStringContainsString('1 story marked relevant', $this->flash('info'));
        $this->assertSame('relevant', ThreatTriage::findOne($a->id)->status);
        $this->post('/threat/bulk', ['do' => 'reopen', 'ids' => [$a->id], 'filter' => 'all']);
        $this->assertSame('new', ThreatTriage::findOne($a->id)->status);

        // Every story matching the filters, on any page
        $this->post('/threat/bulk', ['do' => 'dismiss', 'scope' => 'all', 'filter' => 'new', 'q' => 'Quillon']);
        $this->assertSame('dismissed', ThreatTriage::findOne($a->id)->status);
        $this->assertSame('new', $this->triage('Northgate VPN zero-day')->status);
    }

    public function testWatchlist(): void
    {
        $this->loginAs('analyst');
        $this->get('/threat/watchlist');
        $this->assertOk();
        $this->assertSee('Firewalls and VPNs');
        $this->assertSee('Northgate VPN');
        $this->assertSee('Update from assets');

        // Adding a term re-ranks at once
        $this->assertSame('medium', $this->triage('Quillon')->priority);
        $this->post('/threat/watchlist/create', ['ThreatWatchTerm' => ['term' => 'Quillon Print Server', 'category' => 'other', 'organisation_id' => 99, 'origin' => 'device']]);
        $this->assertRedirect('/threat/watchlist');
        $term = ThreatWatchTerm::findOne(['term' => 'Quillon Print Server']);
        $this->assertSame(Organisation::DEFAULT_ID, (int) $term->organisation_id, 'cannot be aimed at another organisation');
        $this->assertSame('manual', $term->origin);
        $this->assertSame('critical', $this->triage('Quillon')->priority);
        $this->assertTrue(AuditLog::find()->where(['entity_type' => 'threat-term', 'action' => 'create'])->exists());

        $this->post('/threat/watchlist/create', ['ThreatWatchTerm' => ['term' => 'quillon print server', 'category' => 'other']]);
        $this->assertStringContainsString('already on the watchlist', $this->flash('danger'));
        $this->post('/threat/watchlist/create', ['ThreatWatchTerm' => ['term' => 'x', 'category' => 'other']]);
        $this->assertNotNull($this->flash('danger'));

        $this->post("/threat/watchlist/{$term->id}/toggle");
        $term->refresh();
        $this->assertSame(0, (int) $term->is_enabled);
        $this->assertSame('medium', $this->triage('Quillon')->priority);
        $this->get('/threat/watchlist');
        $this->assertSee('gw-threat-terms__off');
        $this->post("/threat/watchlist/{$term->id}/toggle");
        $this->assertSame('critical', $this->triage('Quillon')->priority);

        $this->post("/threat/watchlist/{$term->id}/delete");
        $this->assertNull(ThreatWatchTerm::findOne($term->id));
        $this->assertSame('medium', $this->triage('Quillon')->priority);
    }

    public function testWatchlistAssetTerms(): void
    {
        $this->loginAs('analyst');
        $derived = ThreatWatchTerm::find()->where(['origin' => ThreatWatchTerm::DERIVED_ORIGINS])->one();
        $this->assertNotNull($derived);
        $this->post("/threat/watchlist/{$derived->id}/delete");
        $this->assertStringContainsString('Switch it off instead', $this->flash('warning'));
        $this->assertNotNull(ThreatWatchTerm::findOne($derived->id));

        ThreatWatchTerm::deleteAll(['id' => $derived->id]);
        $this->post('/threat/watchlist/sync');
        $this->assertStringContainsString('1 added', $this->flash('success'));
        $this->assertNotNull(ThreatWatchTerm::findOne(['term' => $derived->term]));

        $this->post('/threat/watchlist/999999/toggle');
        $this->assertStatus(404);
    }

    public function testSourcesPage(): void
    {
        $this->loginAs('viewer');
        $this->get('/threat/sources');
        $this->assertOk();
        $this->assertSee('BleepingComputer');
        $this->assertSee('NVD_API_KEY');
        $this->assertSee('Recent reads');
        $this->assertNotSee('Read now');
        $this->assertNotSee('Add a feed');
        $this->assertNotSee('Switch off');

        $this->loginAs('analyst');
        $this->get('/threat/sources');
        $this->assertSee('Read now');
        $this->assertNotSee('Add a feed');

        ThreatPull::deleteAll();
        ThreatSource::updateAll(['failures' => 1, 'last_error' => 'The publisher refused the request (HTTP 403).'], ['key' => 'krebs']);
        putenv('NVD_API_KEY=x');
        $this->loginAs('admin');
        $this->get('/threat/sources');
        putenv('NVD_API_KEY');
        $this->assertSee('Add a feed');
        $this->assertSee('The publisher refused');
        $this->assertSee('1 failing');
        $this->assertSee('Nothing read yet');
        $this->assertNotSee('free NVD API key');
    }

    public function testToggleAndBuiltInSourcesCannotBeRemoved(): void
    {
        $this->loginAs('admin');
        $source = ThreatSource::findOne(['key' => 'schneier']);
        $this->post("/threat/sources/{$source->id}/toggle");
        $source->refresh();
        $this->assertSame(0, (int) $source->is_enabled);
        $this->assertTrue(AuditLog::find()->where(['entity_type' => 'threat-source', 'entity_id' => $source->id])->exists());
        $this->post("/threat/sources/{$source->id}/toggle");
        $this->post("/threat/sources/{$source->id}/delete");
        $this->assertStringContainsString('only be switched off', $this->flash('warning'));
        $this->assertNotNull(ThreatSource::findOne($source->id));
        $this->post('/threat/sources/999999/toggle');
        $this->assertStatus(404);
    }

    private function fakeFeed(array $response): void
    {
        FeedUrlGuard::$resolver = static fn() => ['93.184.216.34'];
        Http::$fake = static fn(array $request) => $response;
    }

    private function addFeed(string $url = 'https://feeds.example.com/rss'): void
    {
        $this->post('/threat/sources/create', ['ThreatSource' => ['name' => 'My feed', 'url' => $url, 'category' => 'research', 'key' => 'cisa-kev', 'is_custom' => 0, 'format' => 'kev']]);
    }

    public function testAddingAFeed(): void
    {
        $this->loginAs('admin');
        $this->fakeFeed(['status' => 200, 'body' => '<rss><channel><item><title>One</title><link>https://feeds.example.com/1</link></item></channel></rss>']);
        $this->addFeed();
        $this->assertStringContainsString('My feed added, with 1 items', $this->flash('success'));
        $source = ThreatSource::findOne(['name' => 'My feed']);
        $this->assertSame(1, (int) $source->is_custom, 'the form cannot pose as a built-in source');
        $this->assertSame('rss', $source->format);
        $this->assertStringStartsWith('custom-', $source->key);
        $this->assertSame('research', $source->category);

        $this->addFeed();
        $this->assertStringContainsString('already on the list', $this->flash('danger'));
    }

    public function testAFeedIsOnlyAddedOnceItCanBeRead(): void
    {
        $this->loginAs('admin');
        $count = (int) ThreatSource::find()->count();

        $this->fakeFeed(['status' => 200, 'body' => '<html>nope</html>']);
        $this->addFeed();
        $this->assertStringContainsString('not an RSS or Atom feed', $this->flash('danger'));
        $this->fakeFeed(['status' => 200, 'body' => '<rss><channel></channel></rss>']);
        $this->addFeed();
        $this->assertStringContainsString('no items', $this->flash('danger'));
        $this->fakeFeed(['status' => 301, 'body' => '', 'redirect' => 'https://other.example.com/rss']);
        $this->addFeed();
        $this->assertStringContainsString('moved to https://other.example.com/rss', $this->flash('danger'));
        $this->fakeFeed(['status' => 200, 'body' => '<rss/>']);
        $this->addFeed('http://feeds.example.com/rss');
        $this->assertStringContainsString('must use https', $this->flash('danger'));
        FeedUrlGuard::$resolver = static fn() => ['10.0.0.8'];
        $this->addFeed();
        $this->assertStringContainsString('private or reserved', $this->flash('danger'));
        $this->post('/threat/sources/create', ['ThreatSource' => ['name' => '', 'url' => '', 'category' => 'news']]);
        $this->assertNotNull($this->flash('danger'));

        $this->assertSame($count, (int) ThreatSource::find()->count());
    }

    public function testRemovingACustomFeedTakesItsOwnStoriesWithIt(): void
    {
        $this->loginAs('admin');
        $now = date('Y-m-d H:i:s');
        $source = new ThreatSource(['key' => 'custom-1', 'name' => 'Mine', 'category' => 'news', 'url' => 'https://mine.example.com/feed', 'is_custom' => 1, 'is_enabled' => 1]);
        $source->save(false);
        $only = new ThreatStory(['title' => 'Only from mine', 'first_seen_at' => $now, 'last_seen_at' => $now, 'article_count' => 1, 'source_count' => 1]);
        $only->save(false);
        $shared = $this->triage('Tallowood')->story;
        $articles = (int) $shared->article_count;
        foreach ([$only->id, $shared->id] as $i => $storyId) {
            (new ThreatArticle(['source_id' => $source->id, 'story_id' => $storyId, 'guid_hash' => hash('sha256', "x$i"), 'title' => 'T', 'url' => 'https://mine.example.com/1', 'published_at' => $now, 'fetched_at' => $now]))->save(false);
        }

        $this->post("/threat/sources/{$source->id}/delete");
        $this->assertStringContainsString('Mine removed', $this->flash('success'));
        $this->assertNull(ThreatSource::findOne($source->id));
        $this->assertNull(ThreatStory::findOne($only->id));
        $this->assertSame($articles, (int) ThreatStory::findOne($shared->id)->article_count, 'what else covers it stays');
    }

    public function testReadNow(): void
    {
        $this->loginAs('analyst');
        Http::$fake = static fn(array $request) => ['status' => 200, 'body' => str_contains($request['url'], 'known_exploited') ? '{"vulnerabilities":[]}' : '<rss><channel></channel></rss>'];
        $this->post('/threat/sources/pull');
        $this->assertRedirect('/threat/sources');
        $this->assertStringContainsString('sources read, 0 failed', $this->flash('success'));

        $mutex = new \yii\mutex\FileMutex();
        $mutex->acquire(\app\modules\threat\components\ThreatPuller::MUTEX);
        try {
            $this->post('/threat/sources/pull');
            $this->assertStringContainsString('Another Threat Watch pull', $this->flash('warning'));
        } finally {
            $mutex->release(\app\modules\threat\components\ThreatPuller::MUTEX);
        }
    }

    public function testAccessControl(): void
    {
        $id = $this->triage('Quillon')->id;
        $term = ThreatWatchTerm::findOne(['term' => 'Okta'])->id;
        $source = ThreatSource::findOne(['key' => 'schneier'])->id;

        $this->get('/threat');
        $this->assertRedirect('login');

        $this->loginAs('viewer');
        foreach (["/threat/$id/dismiss", "/threat/$id/relevant", "/threat/$id/reopen", '/threat/dismiss-low', '/threat/bulk', '/threat/watchlist/create', "/threat/watchlist/$term/toggle",
                     "/threat/watchlist/$term/delete", '/threat/watchlist/sync', '/threat/sources/pull', '/threat/sources/create', "/threat/sources/$source/toggle", "/threat/sources/$source/delete"] as $url) {
            $this->post($url);
            $this->assertStatus(403, $url);
        }

        $this->loginAs('analyst');
        foreach (['/threat/sources/create', "/threat/sources/$source/toggle", "/threat/sources/$source/delete"] as $url) {
            $this->post($url);
            $this->assertStatus(403, $url);
        }
    }

    public function testOtherOrganisationsCannotReachThis(): void
    {
        $this->loginAs('admin');
        $triage = $this->triage('Quillon');
        $term = ThreatWatchTerm::findOne(['term' => 'Northgate VPN']);
        $now = date('Y-m-d H:i:s');
        Yii::$app->db->createCommand()->insert('organisation', ['name' => 'Second', 'created_at' => $now, 'updated_at' => $now])->execute();
        Organisation::switchTo((int) Yii::$app->db->getLastInsertID());

        $this->get('/threat');
        $this->assertNotSee('Quillon');
        $this->get('/threat/' . $triage->id);
        $this->assertStatus(404);
        $this->post("/threat/{$triage->id}/dismiss");
        $this->assertStatus(404);
        $this->post('/threat/bulk', ['do' => 'dismiss', 'ids' => [$triage->id]]);
        $this->assertSame('new', ThreatTriage::findOne($triage->id)->status);
        $this->post("/threat/watchlist/{$term->id}/toggle");
        $this->assertStatus(404);
        $this->get('/threat/watchlist');
        $this->assertNotSee('Northgate VPN');
        $this->get('/search?q=Quillon');
        $this->assertNotSee($triage->getReferenceValue());
        $this->get('/dashboard');
        $this->assertSee('No critical or high stories');
    }

    public function testAuditLogShowsThreatWatchChanges(): void
    {
        $this->loginAs('admin');
        $triage = $this->triage('Quillon');
        $source = ThreatSource::findOne(['key' => 'schneier']);
        $this->post("/threat/{$triage->id}/dismiss", ['note' => 'Audited note']);
        $this->post('/threat/watchlist/create', ['ThreatWatchTerm' => ['term' => 'Audited term', 'category' => 'identity']]);
        $this->post("/threat/sources/{$source->id}/toggle");

        $this->get('/admin/audit');
        $this->assertOk();
        $this->assertSee('Threat Watch story');
        $this->assertSee('Audited term');
        $this->assertSee('Schneier on Security');
        $this->assertSee($triage->getReferenceValue());
        $this->get('/threat/' . $triage->id);
        $this->assertSee('Dismissed');
    }

    public function testRansomwareAndCustomSourcesAreShown(): void
    {
        $this->loginAs('admin');
        $this->get('/threat/' . $this->triage('Tallowood')->id);
        $this->assertSee('Ransomware');
        (new ThreatSource(['key' => 'custom-2', 'name' => 'My own feed', 'category' => 'news', 'url' => 'https://mine.example.com/f', 'is_custom' => 1, 'is_enabled' => 1]))->save(false);
        $this->get('/threat/sources');
        $this->assertSee('My own feed');
        $this->assertSee('Remove');
    }

    public function testDashboardSearchAndNavigation(): void
    {
        $this->loginAs('viewer');
        $this->get('/dashboard');
        $this->assertOk();
        $this->assertSee('gw-dashboard-panel--threat-watch');
        $this->assertSee('Northgate VPN zero-day');
        $this->assertNotSee('more critical or high');
        $this->assertSee('Watchlist');
        $this->assertSee('News Sources');

        $this->get('/search?q=CVE-2026-90002');
        $this->assertSee('Tallowood');
        $this->get('/search?q=Northgate');
        $this->assertSee($this->triage('Northgate VPN zero-day')->getReferenceValue());

        // More than fit on the panel
        $now = date('Y-m-d H:i:s');
        foreach (range(1, 6) as $n) {
            $story = new ThreatStory(['title' => "Urgent story $n", 'first_seen_at' => $now, 'last_seen_at' => $now]);
            $story->save(false);
            Yii::$app->db->createCommand()->insert('threat_triage', ['organisation_id' => Organisation::DEFAULT_ID, 'story_id' => $story->id, 'priority' => 'high', 'created_at' => $now, 'updated_at' => $now])->execute();
        }
        $this->get('/dashboard');
        $this->assertSee('more critical or high');

        ThreatTriage::updateAll(['status' => 'dismissed']);
        $this->get('/dashboard');
        $this->assertSee('No critical or high stories');
    }
}
