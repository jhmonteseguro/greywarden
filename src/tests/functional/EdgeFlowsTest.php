<?php

namespace tests\functional;

use app\models\AuditLog;
use app\models\Setting;
use app\modules\incident\models\Incident;
use app\modules\intel\components\edge\CloudflareTraffic;
use app\modules\intel\components\edge\EdgeSettings;
use app\modules\intel\components\Http;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\EdgeFinding;
use app\modules\intel\models\EdgePull;
use tests\FunctionalTestCase;
use tests\support\FakeCloudflare;
use Yii;

/**
 * Edge findings pages on the demo data: the triage queue, a finding with its
 * recommendations, and every action (block, raise incident, dismiss, ignore,
 * reopen, bulk dismiss, pull now), plus the Edge monitoring settings.
 * Cloudflare is faked or not configured.
 */
class EdgeFlowsTest extends FunctionalTestCase
{
    protected static bool $seed = true;

    protected function tearDown(): void
    {
        putenv('CLOUDFLARE_API_TOKEN');
        putenv('CLOUDFLARE_ZONE_ID');
        Yii::$app->cache->flush();
        parent::tearDown();
    }

    private function finding(string $ip): EdgeFinding
    {
        return EdgeFinding::findOne(['ip' => $ip]);
    }

    public function testQueueAndFilters(): void
    {
        $this->loginAs('viewer');
        $this->get('/intel/edge');
        $this->assertOk();
        $this->assertSee('Edge findings');
        $this->assertSee('192.0.2.150');
        $this->assertSee('Sensitive file served');
        $this->assertSee('Cloudflare is not configured');
        $this->assertNotSee('203.0.113.45', 'blocked findings are not in the queue');
        $this->assertNotSee('Pull now');
        $this->assertNotSee('Dismiss low priority');
        // Suggested WAF rule, repeat networks, pulls
        $this->assertSee('id="edge-waf"');
        $this->assertSee('lower(http.request.uri.path) contains &quot;/.env&quot;');
        $this->assertSee('AS64501');
        $this->assertSee('Recent pulls');

        foreach (['high', 'exposure', 'blocked', 'escalated', 'dismissed', 'ignored', 'all'] as $filter) {
            $this->get('/intel/edge?filter=' . $filter);
            $this->assertOk();
        }
        $this->get('/intel/edge?filter=blocked');
        $this->assertSee('203.0.113.45');
        $this->get('/intel/edge?filter=all&q=AS64501&sort=-score&priority=high');
        $this->assertOk();
        $this->assertSee('192.0.2.150');
        $this->get('/intel/edge?filter=all&q=wp-login');
        $this->assertSee('2001:db8::66');
        $this->get('/intel/edge?filter=bogus');
        $this->assertSee('No findings match');

        // Sidebar, global search, dashboard
        $this->assertSee('Edge Findings');
        $this->get('/search?q=192.0.2.150');
        $this->assertSee($this->finding('192.0.2.150')->reference);
        $this->get('/dashboard');
        $this->assertSee('High-priority edge findings');
    }

    public function testBeforeTheFirstPull(): void
    {
        EdgePull::deleteAll();
        $this->loginAs('viewer');
        $this->get('/intel/edge');
        $this->assertSee('Not pulled yet');
        $this->assertSee('waiting for the scheduler');
        $this->assertSee('The scheduler container pulls every 15 minutes once Cloudflare is configured');
        putenv('EDGE_PULL_INTERVAL=0');
        try {
            $this->get('/intel/edge');
        } finally {
            putenv('EDGE_PULL_INTERVAL');
        }
        $this->assertSee('Scheduled pulls are off');
    }

    public function testFindingPage(): void
    {
        $f = $this->finding('192.0.2.150');
        $this->loginAs('viewer');
        $this->get("/intel/edge/{$f->id}");
        $this->assertOk();
        $this->assertSee('Recommended actions');
        $this->assertSee('Raise an incident and check the exposed path');
        $this->assertSee('A path that should never be public');
        $this->assertSee('Why it was flagged');
        $this->assertSee('/.env');
        $this->assertSee('gw-edge-status--ok');
        $this->assertNotSee('name="days"', 'viewers get no buttons');

        $this->loginAs('analyst');
        $this->get("/intel/edge/{$f->id}");
        $this->assertSee('name="days"');
        $this->assertSee('For 7 days');
        $this->assertSee('Ignore this address');

        // Payloads, agents and history on the scanner
        $scanner = $this->finding('203.0.113.45');
        $this->get("/intel/edge/{$scanner->id}");
        $this->assertSee('Attack payloads');
        $this->assertSee('sqlmap');
        $this->assertSee($scanner->blocklistEntry->reference);
        $this->assertSee('Reopen');

        // The survey scanner suggests ignoring it, the dismissed one shows its note
        $this->get('/intel/edge/' . $this->finding('203.0.113.9')->id);
        $this->assertSee('Ignore it if it is an internet survey scanner');
        $this->get('/intel/edge/' . $this->finding('2001:db8::66')->id);
        $this->assertSee('we do not run WordPress');

        $this->get('/intel/edge/999999');
        $this->assertStatus(404);
    }

    public function testBlock(): void
    {
        $f = $this->finding('192.0.2.151');
        $this->loginAs('viewer');
        $this->post("/intel/edge/{$f->id}/block", ['days' => 7]);
        $this->assertStatus(403);

        $this->loginAs('analyst');
        $this->get("/intel/edge/{$f->id}/block");
        $this->assertStatus(405);
        $this->post("/intel/edge/{$f->id}/block", ['days' => 30]);
        $this->assertRedirect("/intel/edge/{$f->id}");
        $this->assertStringContainsString('blocked as BLK-', $this->flash('success'));
        $f->refresh();
        $this->assertSame(EdgeFinding::STATUS_BLOCKED, $f->status);
        $entry = $f->blocklistEntry;
        $this->assertSame('192.0.2.151', $entry->value);
        $this->assertSame('inbound', $entry->direction);
        $this->assertStringContainsString($f->reference, $entry->reason);
        $this->assertEqualsWithDelta(strtotime('+30 days'), strtotime($entry->expires_at), 120);
        $this->assertNull($entry->cloudflare_added_at, 'Cloudflare is not configured');

        // Blocking again links the entry in force; a permanent block has no expiry
        $f->reopen();
        $this->post("/intel/edge/{$f->id}/block", ['days' => 0]);
        $this->assertStringContainsString('already blocked by ' . $entry->reference, $this->flash('success'));
        $other = $this->finding('203.0.113.9');
        $this->post("/intel/edge/{$other->id}/block", ['days' => 0]);
        $other->refresh();
        $this->assertNull($other->blocklistEntry->expires_at);
        // An odd length falls back to the default
        $third = $this->finding('192.0.2.150');
        $this->post("/intel/edge/{$third->id}/block", ['days' => 9999]);
        $third->refresh();
        $this->assertEqualsWithDelta(strtotime('+' . EdgeSettings::blockDays() . ' days'), strtotime($third->blocklistEntry->expires_at), 120);
    }

    public function testBlockPushesToCloudflareAndReportsFailures(): void
    {
        putenv('CLOUDFLARE_API_TOKEN=t');
        putenv('CLOUDFLARE_ZONE_ID=z');
        $f = $this->finding('192.0.2.151');
        $this->loginAs('analyst');
        // The Cloudflare API refuses: nothing is blocked
        Http::$fake = static fn() => ['status' => 403, 'body' => json_encode(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]])];
        $this->post("/intel/edge/{$f->id}/block", ['days' => 7]);
        $this->assertStringContainsString('was not blocked: Cloudflare: Authentication error', $this->flash('danger'));
        $this->assertSame(EdgeFinding::STATUS_NEW, $this->finding('192.0.2.151')->status);

        $cf = (new FakeCloudflare(null, false))->install();
        $this->post("/intel/edge/{$f->id}/block", ['days' => 7]);
        $flash = $this->flash('success');
        $this->assertStringContainsString('Added to the Cloudflare list “greywarden_blocklist”', $flash);
        $this->assertStringContainsString('Greywarden created the custom rule "Greywarden Blocklist" in Cloudflare, disabled', $flash);
        $this->assertSame(['192.0.2.151'], $cf->items());
        $this->assertFalse($cf->rule()['enabled']);
        FakeCloudflare::uninstall();
    }

    public function testRaiseIncident(): void
    {
        $f = $this->finding('192.0.2.150');
        $this->loginAs('analyst');
        $this->post("/intel/edge/{$f->id}/incident");
        $f->refresh();
        $incident = $f->incident;
        $this->assertNotNull($incident);
        $this->assertRedirect("/incidents/{$incident->id}/update");
        $this->assertStringContainsString('raised from ' . $f->reference, $this->flash('success'));
        $this->assertSame(EdgeFinding::STATUS_ESCALATED, $f->status);
        $this->assertSame(Incident::SEVERITY_HIGH, $incident->severity);
        $this->assertSame('Vulnerability Exploitation', $incident->category->name);
        $this->assertStringContainsString('Possible exposure', $incident->title);
        $this->assertStringContainsString('/.env', $incident->description);
        $this->assertSame($f->first_seen_at, $incident->detected_at);
        $this->assertEqualsCanonicalizing(['T1190', 'T1595'], array_column($incident->techniques, 'external_id'));

        // Again: goes to the linked incident
        $this->post("/intel/edge/{$f->id}/incident");
        $this->assertRedirect("/incidents/{$incident->id}");
        $this->assertStringContainsString('already linked', $this->flash('info'));

        // A plain scanning finding becomes a low "Other" incident with only T1595
        $survey = $this->finding('203.0.113.9');
        $this->post("/intel/edge/{$survey->id}/incident");
        $survey->refresh();
        $this->assertSame(Incident::SEVERITY_LOW, $survey->incident->severity);
        $this->assertSame('Other', $survey->incident->category->name);
        $this->assertSame(['T1595'], array_column($survey->incident->techniques, 'external_id'));
        $this->assertStringContainsString('Hostile scanning from 203.0.113.9', $survey->incident->title);
    }

    public function testRaiseIncidentFailure(): void
    {
        // No incident category to file it under
        \app\models\Lookup::updateAll(['is_active' => 0], ['type' => \app\models\Lookup::TYPE_INCIDENT_CATEGORY]);
        $f = $this->finding('192.0.2.150');
        $this->loginAs('analyst');
        $this->post("/intel/edge/{$f->id}/incident");
        $this->assertRedirect("/intel/edge/{$f->id}");
        $this->assertStringContainsString('The incident was not raised', $this->flash('danger'));
        $this->assertSame(EdgeFinding::STATUS_NEW, $this->finding('192.0.2.150')->status);
    }

    public function testDismissIgnoreReopenAndBulk(): void
    {
        $f = $this->finding('203.0.113.9');
        $this->loginAs('analyst');
        $this->post("/intel/edge/{$f->id}/ignore", ['note' => 'Censys']);
        $this->assertRedirect("/intel/edge/{$f->id}");
        $this->assertStringContainsString('ignored', $this->flash('info'));
        $f->refresh();
        $this->assertSame([EdgeFinding::STATUS_IGNORED, 'Censys'], [$f->status, $f->note]);
        $this->post("/intel/edge/{$f->id}/reopen");
        $this->assertSame(EdgeFinding::STATUS_NEW, $this->finding('203.0.113.9')->status);
        $this->post("/intel/edge/{$f->id}/dismiss");
        $this->assertStringContainsString('dismissed', $this->flash('info'));
        $this->assertSame(EdgeFinding::STATUS_DISMISSED, $this->finding('203.0.113.9')->status);
        $this->assertTrue(AuditLog::find()->where(['entity_type' => 'edge-finding', 'entity_id' => $f->id, 'action' => 'update'])->exists());
        $this->get("/intel/edge/{$f->id}");
        $this->assertSee('Dismissed');

        // Bulk: only new low-priority findings
        $f->reopen();
        $f->priority = 'low';
        $f->save(false);
        $this->get('/intel/edge');
        $this->assertSee('Dismiss low priority');
        $this->post('/intel/edge/dismiss-low');
        $this->assertSame('1 low-priority finding dismissed.', $this->flash('info'));
        $this->assertSame(EdgeFinding::STATUS_DISMISSED, $this->finding('203.0.113.9')->status);
        $this->assertSame(EdgeFinding::STATUS_NEW, $this->finding('192.0.2.150')->status);
        $this->post('/intel/edge/dismiss-low');
        $this->assertSame('0 low-priority findings dismissed.', $this->flash('info'));
    }

    public function testBulkSelectionOnTheGrid(): void
    {
        $this->loginAs('viewer');
        $this->get('/intel/edge');
        $this->assertNotSee('id="edge-bulk"', 'viewers get no bulk actions');
        $this->assertNotSee('data-gw-bulk-row');
        $this->post('/intel/edge/bulk', ['do' => 'dismiss', 'ids' => [$this->finding('192.0.2.150')->id]]);
        $this->assertStatus(403);

        $this->loginAs('analyst');
        $this->get('/intel/edge/bulk');
        $this->assertStatus(405);
        $this->get('/intel/edge?filter=new&q=192.0.2');
        $this->assertSee('id="edge-bulk"');
        $this->assertSee('data-gw-bulk-page-toggle');
        $this->assertSee('form="edge-bulk"');
        $this->assertSee('value="' . $this->finding('192.0.2.150')->id . '"');
        $this->assertSee('Select all 2 matching findings');
        $this->assertSee('name="q" value="192.0.2"', 'the filters go with the form');
        $this->assertSee('name="days"');
        $this->assertNotSee('value="reopen"', 'nothing to reopen in the triage queue');
        $this->get('/intel/edge?filter=all');
        $this->assertSee('value="reopen"');
        // No rows, no bar
        $this->get('/intel/edge?filter=escalated');
        $this->assertNotSee('id="edge-bulk"');
    }

    public function testBulkDismissIgnoreReopen(): void
    {
        $a = $this->finding('192.0.2.150');
        $b = $this->finding('203.0.113.9');
        $dismissed = $this->finding('2001:db8::66');
        $this->loginAs('analyst');

        // Dismiss: only findings to triage; the filters come back on the redirect
        $this->post('/intel/edge/bulk', ['do' => 'dismiss', 'ids' => [$a->id, $b->id, $dismissed->id], 'filter' => 'all', 'q' => '', 'priority' => 'high']);
        $this->assertRedirect('/intel/edge?filter=all&priority=high');
        $this->assertSame('2 findings dismissed. 1 finding skipped (not to triage).', $this->flash('info'));
        $this->assertSame(EdgeFinding::STATUS_DISMISSED, $this->finding('192.0.2.150')->status);
        $this->assertSame('Dismissed in bulk.', $this->finding('203.0.113.9')->note);

        // Ignore takes dismissed findings too, but not blocked ones
        $blocked = $this->finding('203.0.113.45');
        $this->post('/intel/edge/bulk', ['do' => 'ignore', 'ids' => [$a->id, $blocked->id]]);
        $this->assertSame('1 finding ignored. 1 finding skipped (already closed).', $this->flash('info'));
        $this->assertSame(EdgeFinding::STATUS_IGNORED, $this->finding('192.0.2.150')->status);

        // Reopen everything matching a filter
        $this->post('/intel/edge/bulk', ['do' => 'reopen', 'scope' => 'all', 'filter' => 'all', 'q' => '192.0.2']);
        $this->assertSame('1 finding reopened. 1 finding skipped (already to triage).', $this->flash('info'));
        $this->assertSame(EdgeFinding::STATUS_NEW, $this->finding('192.0.2.150')->status);

        // Nothing chosen
        $this->post('/intel/edge/bulk', ['do' => 'dismiss']);
        $this->assertRedirect('/intel/edge');
        $this->assertSame('No findings selected.', $this->flash('warning'));
        $this->post('/intel/edge/bulk', ['do' => 'dismiss', 'ids' => ['x', '']]);
        $this->assertSame('No findings selected.', $this->flash('warning'));
        $this->post('/intel/edge/bulk', ['do' => 'delete', 'ids' => [$a->id]]);
        $this->assertSame('Choose an action for the selected findings.', $this->flash('warning'));
        $this->post('/intel/edge/bulk', ['do' => ['block'], 'ids' => [$a->id], 'filter' => ['all']]);
        $this->assertRedirect('/intel/edge');
        $this->assertSame('Choose an action for the selected findings.', $this->flash('warning'));
    }

    public function testBulkBlock(): void
    {
        $a = $this->finding('192.0.2.150');
        $b = $this->finding('192.0.2.151');
        $blocked = $this->finding('203.0.113.45');
        $this->loginAs('analyst');
        $before = BlocklistEntry::find()->count();

        $this->post('/intel/edge/bulk', ['do' => 'block', 'days' => 7, 'ids' => [$a->id, $b->id, $blocked->id], 'filter' => 'new']);
        $this->assertRedirect('/intel/edge?filter=new');
        $this->assertSame('2 addresses blocked for 7 days. 1 finding already blocked.', $this->flash('success'));
        $this->assertSame($before + 2, (int) BlocklistEntry::find()->count());
        foreach (['192.0.2.150', '192.0.2.151'] as $ip) {
            $f = $this->finding($ip);
            $this->assertSame(EdgeFinding::STATUS_BLOCKED, $f->status);
            $this->assertSame($ip, $f->blocklistEntry->value);
            $this->assertStringContainsString($f->reference, $f->blocklistEntry->reason);
            $this->assertEqualsWithDelta(strtotime('+7 days'), strtotime($f->blocklistEntry->expires_at), 120);
            $this->assertNull($f->blocklistEntry->cloudflare_added_at);
        }
        $this->assertSame('high', $this->finding('192.0.2.150')->blocklistEntry->severity);

        // Reopened, an address with an entry in force is linked to it; scope=all blocks every match, permanently
        $this->finding('192.0.2.150')->reopen();
        $this->post('/intel/edge/bulk', ['do' => 'block', 'days' => 0, 'scope' => 'all', 'filter' => 'new']);
        $this->assertSame('1 address blocked permanently. 1 address already on the blocklist.', $this->flash('success'));
        $this->assertNull($this->finding('203.0.113.9')->blocklistEntry->expires_at);
        $this->assertSame(0, (int) EdgeFinding::find()->where(['status' => EdgeFinding::STATUS_NEW])->count());

        // An address whose entry has expired fails validation (edit the entry instead) and is reported with the reason
        $first = $this->finding('192.0.2.150');
        $second = $this->finding('192.0.2.151');
        BlocklistEntry::updateAll(['expires_at' => '2020-01-01 00:00:00'], ['id' => [$first->blocklist_id, $second->blocklist_id]]);
        $this->post('/intel/edge/bulk', ['do' => 'block', 'days' => 9999, 'ids' => [$first->id, $second->id]]);
        $flash = $this->flash('danger');
        $this->assertStringStartsWith(sprintf('2 findings not blocked: %s: 192.0.2.150 is already blocked (%s). %s:', $first->reference, $first->blocklistEntry->reference, $second->reference), $flash);
        $this->assertSame($first->blocklist_id, $this->finding('192.0.2.150')->blocklist_id);
    }

    public function testBulkBlockPushesToCloudflareOnce(): void
    {
        putenv('CLOUDFLARE_API_TOKEN=t');
        putenv('CLOUDFLARE_ZONE_ID=z');
        $ids = [$this->finding('192.0.2.150')->id, $this->finding('192.0.2.151')->id, $this->finding('203.0.113.9')->id];
        $this->loginAs('analyst');
        $this->get('/intel/edge');
        $this->assertSee('They are added to the Cloudflare blocklist.');

        // The Cloudflare API refuses: nothing is blocked, one reason for all
        Http::$fake = static fn() => ['status' => 403, 'body' => json_encode(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]])];
        $this->post('/intel/edge/bulk', ['do' => 'block', 'ids' => $ids]);
        $this->assertStringStartsWith('3 findings not blocked: Cloudflare: Authentication error', $this->flash('danger'));
        $this->assertSame(3, (int) EdgeFinding::find()->where(['id' => $ids, 'status' => EdgeFinding::STATUS_NEW])->count());

        $cf = (new FakeCloudflare(null, true))->install();
        $this->post('/intel/edge/bulk', ['do' => 'block', 'days' => 30, 'ids' => $ids]);
        $flash = $this->flash('success');
        $this->assertStringContainsString('3 addresses blocked for 30 days. Added to the Cloudflare list “greywarden_blocklist”', $flash);
        $this->assertEqualsCanonicalizing(['192.0.2.150', '192.0.2.151', '203.0.113.9'], $cf->items());
        $this->assertCount(1, $cf->requests('POST', '/items'), 'one list update for every address');
        $this->assertNotNull($this->finding('192.0.2.150')->blocklistEntry->cloudflare_added_at);
        FakeCloudflare::uninstall();
    }

    public function testPullNow(): void
    {
        putenv('CLOUDFLARE_API_TOKEN=t');
        putenv('CLOUDFLARE_ZONE_ID=z');
        $this->loginAs('analyst');
        $this->get('/intel/edge');
        $this->assertSee('Pull now');
        $this->assertNotSee('Cloudflare is not configured');

        // Analytics refused: the pull fails and the page says so
        Http::$fake = static fn() => ['status' => 403, 'body' => json_encode(['data' => null, 'errors' => [['message' => 'not authorized for that account']]])];
        $this->post('/intel/edge/pull');
        $this->assertRedirect('/intel/edge');
        $this->assertStringContainsString('Pull failed: Cloudflare analytics: not authorized', $this->flash('danger'));
        $this->get('/intel/edge');
        $this->assertSee('The last pull failed');

        Yii::$app->cache->flush();
        Http::$fake = static function (array $r) {
            $q = json_decode($r['body'], true)['query'];
            if (str_contains($q, 'EdgeSettings')) {
                $l = ['enabled' => true, 'availableFields' => null, 'maxDuration' => 86400, 'maxPageSize' => 1000, 'notOlderThan' => 86400];
                return ['body' => json_encode(['data' => ['viewer' => ['zones' => [['settings' => [CloudflareTraffic::HTTP => $l, CloudflareTraffic::FIREWALL => $l]]]]]])];
            }
            $rows = str_contains($q, 'clientAsn') ? [['count' => 9, 'dimensions' => ['clientIP' => '192.0.2.170', 'clientAsn' => 64509, 'clientCountryName' => 'FR', 'userAgent' => 'nuclei', 'clientRequestHTTPMethodName' => 'GET']]] : [];
            return ['body' => json_encode(['data' => ['viewer' => ['zones' => [['rows' => $rows]]]]])];
        };
        $this->post('/intel/edge/pull');
        $this->assertMatchesRegularExpression('/Pulled .* from 1 addresses: 1 new finding, 0 updated/', $this->flash('success'));
        $this->assertTrue(EdgeFinding::find()->where(['ip' => '192.0.2.170'])->exists());
        $this->assertSame($this->userIdFor('analyst'), (int) EdgePull::latest()->run_by);

        // Straight away again: nothing new to read yet
        $this->post('/intel/edge/pull');
        $this->assertStringContainsString('Nothing new to read yet', $this->flash('success'));

        $this->loginAs('viewer');
        $this->post('/intel/edge/pull');
        $this->assertStatus(403);
    }

    public function testPullNowWhileAnotherRuns(): void
    {
        putenv('CLOUDFLARE_API_TOKEN=t');
        putenv('CLOUDFLARE_ZONE_ID=z');
        $this->loginAs('analyst');
        $mutex = new \yii\mutex\FileMutex();
        $mutex->acquire(\app\modules\intel\components\edge\EdgePuller::MUTEX);
        try {
            $this->post('/intel/edge/pull');
        } finally {
            $mutex->release(\app\modules\intel\components\edge\EdgePuller::MUTEX);
        }
        $this->assertSame('Another edge pull is running.', $this->flash('warning'));
    }

    public function testSettings(): void
    {
        $this->loginAs('admin');
        $this->get('/admin/settings');
        $this->assertSee('id="edge-form"');
        $this->assertSee('Edge monitoring');
        $this->assertSee('name="EdgeForm[allowlist]"');
        $this->assertSee('name="EdgeForm[dns_only]"');
        $this->assertSee('DNS only on purpose');

        $form = ['EdgeForm' => ['ignore_paths' => "/wp-admin\n/api/", 'allowlist' => "203.0.113.10 # office\nAS64500", 'min_score' => '40', 'block_days' => '7', 'volume_rpm' => '120']];
        $this->post('/admin/settings', $form);
        $this->assertRedirect('/admin/settings#edge');
        $this->assertSame('Edge monitoring settings saved.', $this->flash('success'));
        Setting::reset();
        $this->assertSame(['/wp-admin', '/api/'], EdgeSettings::ignoredPaths());
        $this->assertSame(40, EdgeSettings::minScore());
        $this->assertNull(Setting::get('edge.block_days'), 'an unchanged default is not stored');
        $entry = AuditLog::find()->where(['entity_type' => 'setting', 'entity_label' => 'Edge monitoring'])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry);
        $this->get("/admin/audit/{$entry->id}");
        $this->assertSee('Open a finding from score');

        // Saving unchanged writes nothing
        $count = AuditLog::find()->where(['entity_label' => 'Edge monitoring'])->count();
        $this->post('/admin/settings', $form);
        $this->assertSame($count, AuditLog::find()->where(['entity_label' => 'Edge monitoring'])->count());

        // Records that are DNS only on purpose
        $this->post('/admin/settings', ['EdgeForm' => ['dns_only' => "mail.example.com # MX
*.lab.example.com"] + $form['EdgeForm']]);
        $this->assertRedirect('/admin/settings#edge');
        Setting::reset();
        $this->assertSame(['mail.example.com', '*.lab.example.com'], EdgeSettings::dnsOnly());
        $this->post('/admin/settings', ['EdgeForm' => ['dns_only' => 'not a host'] + $form['EdgeForm']]);
        $this->assertOk();
        $this->assertSee('Not a hostname: not a host.');

        // Validation
        $bad = ['EdgeForm' => ['ignore_paths' => "wp-admin\n/ok", 'allowlist' => "not-an-ip\n203.0.113.10", 'min_score' => '0', 'block_days' => '400', 'volume_rpm' => 'x']];
        $this->post('/admin/settings', $bad);
        $this->assertOk();
        $this->assertSee('Each line must be a path starting with');
        $this->assertSee('Not an address, network or ASN: not-an-ip');
        $this->assertSame(40, EdgeSettings::minScore(), 'nothing saved');

        $this->loginAs('analyst');
        $this->post('/admin/settings', $form);
        $this->assertStatus(403);
    }
}
