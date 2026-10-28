<?php

namespace tests\functional;

use app\models\AuditLog;
use app\modules\intel\components\Http;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\IntelIndicator;
use app\modules\intel\models\IntelNote;
use tests\FunctionalTestCase;
use yii\db\Query;

/**
 * Threat intel pages: investigation (documentation addresses only, every lookup
 * faked), analyst notes and verdicts, the blocklist lifecycle and feed updates.
 */
class IntelFlowsTest extends FunctionalTestCase
{
    protected static bool $seed = true;

    private function blockForm(array $attrs = []): array
    {
        return ['BlocklistEntry' => $attrs + ['value' => '192.0.2.99', 'severity' => 'high', 'direction' => 'inbound', 'reason' => 'Functional block']];
    }

    public function testInvestigationSearchAndCanonicalUrls(): void
    {
        $this->loginAs('viewer');
        $this->get('/intel?q=' . urlencode('hxxp://203.0.113[.]66:443/x'));
        $this->assertRedirect('/intel/ip/203.0.113.66');
        $this->get('/intel?q=' . urlencode('seen from [2001:db8::5] today'));
        $this->assertRedirect('/intel/ip/2001:db8::5');
        $this->get('/intel?q=' . urlencode('origin AS 64500'));
        $this->assertRedirect('/intel/asn/AS64500');
        $this->get('/intel?q=nothing-here');
        $this->assertOk();
        $this->assertSee('Enter an IPv4 / IPv6 address');

        // Non-canonical forms redirect to the canonical report URL
        $this->get('/intel/ip/2001:DB8:0:0::5');
        $this->assertRedirect('/intel/ip/2001:db8::5');
        $this->get('/intel/ip/2001:DB8::5?refresh=1');
        $this->assertRedirect('refresh=1');
        $this->get('/intel/asn/64500');
        $this->assertRedirect('/intel/asn/AS64500');
        $this->get('/intel/asn/as64500?refresh=1');
        $this->assertRedirect('refresh=1');
        $this->get('/intel/asn/AS64500?refresh=1');
        $this->assertOk();

        // Each report is recorded, and the audit log links back to it
        $this->get('/intel/ip/203.0.113.66');
        $this->assertOk();
        // Multi-source groups render as two balanced stacks, a lone source spans the width
        $this->assertSee('class="gw-intel-stack"');
        $this->assertSee('gw-intel-grid gw-intel-grid--single');
        $this->assertSee('style="order: 0"');
        // The analyst verdict sits under "Why this rating", the sweep has the other column
        $signals = strpos($this->body, 'gw-intel-signals');
        $verdict = strpos($this->body, 'gw-intel-verdict-panel');
        $this->assertTrue($signals < $verdict && $verdict < strpos($this->body, 'gw-panel gw-intel-sweep'));
        $model = IntelIndicator::findOne(['value' => '203.0.113.66']);
        $this->assertGreaterThan(0, (int) $model->lookup_count);
        $this->get("/intel/indicator/{$model->id}");
        $this->assertRedirect('/intel/ip/203.0.113.66');
    }

    public function testNotesAndVerdicts(): void
    {
        $this->loginAs('analyst');
        $this->post('/intel/note', ['indicator' => '192.0.2.77', 'IntelNote' => ['body' => 'Functional note']]);
        $this->assertRedirect('/intel/ip/192.0.2.77');
        $indicator = IntelIndicator::findOne(['value' => '192.0.2.77']);
        $this->assertNotNull($indicator, 'notes on a new indicator create it');
        $this->assertTrue(IntelNote::find()->where(['indicator_id' => $indicator->id, 'body' => 'Functional note'])->exists());

        $this->post('/intel/note', ['indicator' => '192.0.2.77', 'IntelNote' => ['body' => '']]);
        $this->assertStringContainsString('Note not added', $this->flash('danger'));
        $this->post('/intel/note', ['IntelNote' => ['body' => 'x']]);
        $this->assertStatus(400);

        $this->post('/intel/verdict', ['indicator' => '192.0.2.77', 'verdict' => IntelIndicator::VERDICT_SUSPICIOUS]);
        $this->assertRedirect();
        $this->assertStringContainsString('marked as suspicious', $this->flash('success'));
        $this->get('/intel/ip/192.0.2.77');
        $this->assertSee('Functional note');
        $this->assertSee('Analyst: Suspicious');
        $this->get('/intel');
        $this->assertSee('192.0.2.77');

        $this->post('/intel/verdict', ['indicator' => '192.0.2.77', 'verdict' => 'nonsense']);
        $this->assertSame('Unknown verdict.', $this->flash('danger'));
        $this->post('/intel/verdict', ['indicator' => '192.0.2.77', 'verdict' => '']);
        $this->assertStringContainsString('verdict cleared', $this->flash('success'));
        $this->assertNull(IntelIndicator::findOne($indicator->id)->verdict);
    }

    public function testBlocklistLifecycle(): void
    {
        $this->loginAs('analyst');
        $incident = (int) (new Query())->select('id')->from('incident')->where(['reference' => 'INC-0001'])->scalar();
        $this->get("/intel/blocklist/create?value=192.0.2.99&incident_id=$incident");
        $this->assertOk();
        $this->assertSee('192.0.2.99');

        // From an investigation page: back to the report
        $this->post('/intel/blocklist/create', $this->blockForm(['incident_id' => $incident, 'expires_at' => date('Y-m-d\TH:i', strtotime('+2 days'))]) + ['return' => 'report']);
        $this->assertRedirect('/intel/ip/192.0.2.99');
        $entry = BlocklistEntry::findOne(['value' => '192.0.2.99']);
        $this->assertSame($incident, (int) $entry->incident_id);
        $this->get('/intel/ip/192.0.2.99');
        $this->assertSee('gw-intel-flagchip--blocked');

        $this->post('/intel/blocklist/create', $this->blockForm(['reason' => 'dup']));
        $this->assertOk();
        $this->assertSee('is already blocked');
        $this->post('/intel/blocklist/create', $this->blockForm(['value' => '10.0.0.0/6', 'reason' => 'broad']));
        $this->assertSee('cannot be blocked');

        $this->post("/intel/blocklist/{$entry->id}/update", $this->blockForm(['value' => '192.0.2.0/28', 'severity' => 'critical', 'direction' => 'both']));
        $this->assertRedirect("/intel/blocklist/{$entry->id}");
        $this->get("/intel/blocklist/{$entry->id}");
        $this->assertSee('192.0.2.0/28');
        $this->assertSee('gw-diff');

        // Unblock (archive) from the report returns to it; then it is read-only
        $this->post("/intel/blocklist/{$entry->id}/archive", ['return' => 'report']);
        $this->assertRedirect('/intel/');
        $this->assertStringContainsString('unblocked', $this->flash('info'));
        $this->get("/intel/blocklist/{$entry->id}/update");
        $this->assertRedirect("/intel/blocklist/{$entry->id}");
        $this->get('/intel/blocklist?filter=unblocked');
        $this->assertSee('192.0.2.0/28');

        // Re-instating is refused while another active entry blocks the same value
        $other = new BlocklistEntry(['value' => '192.0.2.0/28', 'severity' => 'high', 'direction' => 'both', 'reason' => 'Other']);
        $this->assertTrue($other->save(), json_encode($other->getErrors()));
        $this->post("/intel/blocklist/{$entry->id}/restore");
        $this->assertStringContainsString('is already blocked by ' . $other->reference, $this->flash('warning'));
        $other->archive();
        $this->post("/intel/blocklist/{$entry->id}/restore");
        $this->assertStringContainsString('blocked again', $this->flash('success'));
        $this->post("/intel/blocklist/{$entry->id}/archive");
        $this->assertRedirect("/intel/blocklist/{$entry->id}");

        $this->post("/intel/blocklist/{$entry->id}/delete");
        $this->assertStatus(403);
        $this->loginAs('admin');
        $this->post("/intel/blocklist/{$other->id}/restore");
        $this->post("/intel/blocklist/{$other->id}/delete");
        $this->assertStringContainsString('Unblock the entry before deleting', $this->flash('warning'));
        $this->post("/intel/blocklist/{$entry->id}/delete");
        $this->assertRedirect('filter=unblocked');
        $this->assertNull(BlocklistEntry::findOne($entry->id));
        $this->assertSame(['create', 'update', 'archive', 'restore', 'archive', 'delete'],
            AuditLog::find()->select('action')->where(['entity_type' => 'blocklist', 'entity_id' => $entry->id])->orderBy('id')->column());
    }

    public function testCloudflareFailureKeepsEntryBlocked(): void
    {
        putenv('CLOUDFLARE_API_TOKEN=test-token');
        putenv('CLOUDFLARE_ZONE_ID=zone1');
        try {
            Http::$fake = static fn(array $r) => ['status' => 500, 'body' => json_encode(['success' => false, 'errors' => [['message' => 'Edge unavailable']]])];
            $entry = new BlocklistEntry(['value' => '192.0.2.55', 'severity' => 'high', 'direction' => 'both', 'reason' => 'CF']);
            $this->assertTrue($entry->save(), json_encode($entry->getErrors()));
            $entry->updateAttributes(['cloudflare_added_at' => date('Y-m-d H:i:s')]);

            $this->loginAs('analyst');
            $this->post("/intel/blocklist/{$entry->id}/archive");
            $this->assertRedirect("/intel/blocklist/{$entry->id}");
            $this->assertStringContainsString('was not unblocked: Cloudflare: Edge unavailable', $this->flash('danger'));
            $this->assertFalse(BlocklistEntry::findOne($entry->id)->getIsArchived());

            $this->post('/intel/blocklist/create', $this->blockForm(['value' => '192.0.2.56', 'cloudflare' => 1]));
            $this->assertOk();
            $this->assertSee('Edge unavailable');
            $this->assertNull(BlocklistEntry::findOne(['value' => '192.0.2.56']));
        } finally {
            putenv('CLOUDFLARE_API_TOKEN');
            putenv('CLOUDFLARE_ZONE_ID');
        }
    }

    public function testSourcesPageAndFeedUpdate(): void
    {
        $this->loginAs('analyst');
        $this->get('/intel/sources');
        $this->assertOk();
        // CLI has no fastcgi_finish_request(): the update runs inline (every download faked as failing)
        $this->post('/intel/sources/update');
        $this->assertRedirect('/intel/sources');
        $this->assertStringContainsString('0 feeds updated; failed:', $this->flash('warning'));
        $this->get('/intel/sources');
        $this->assertSee('HTTP 404');
    }
}
