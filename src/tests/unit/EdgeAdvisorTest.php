<?php

namespace tests\unit;

use app\components\Actor;
use app\models\Setting;
use app\modules\intel\components\edge\EdgeAdvisor;
use app\modules\intel\components\edge\EdgeAnalyzer;
use app\modules\intel\components\Http;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\EdgeFinding;
use tests\DbTestCase;

/**
 * Recommendations for edge findings, the suggested WAF rule, repeat networks,
 * and the finding model itself.
 */
class EdgeAdvisorTest extends DbTestCase
{
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Actor::override($this->userId(), 'tester');
        putenv('CLOUDFLARE_API_TOKEN');
        putenv('CLOUDFLARE_ZONE_ID');
    }

    protected function tearDown(): void
    {
        Actor::override(null);
        Http::$fake = null;
        parent::tearDown();
    }

    /** A finding scored from a profile; $paths: path => [status => n] */
    private function finding(array $paths = [], array $extra = [], array $attrs = []): EdgeFinding
    {
        $profile = EdgeAnalyzer::emptyProfile();
        foreach ($paths as $path => $statuses) {
            $profile['paths'][$path] = ['count' => array_sum($statuses), 'statuses' => $statuses, 'group' => \app\modules\intel\components\edge\EdgeCatalogue::probeGroup($path)];
        }
        $profile = array_merge($profile, $extra);
        $profile['requests'] = max(1, $profile['requests']);
        $scored = EdgeAnalyzer::score($profile, ['volume_rpm' => 120]);
        $f = new EdgeFinding(array_merge([
            'ip' => '192.0.2.' . (100 + ++$this->n), 'status' => EdgeFinding::STATUS_NEW, 'score' => $scored['score'],
            'priority' => EdgeAnalyzer::priority($scored['score'], $scored['exposure']), 'headline' => $scored['headline'], 'exposure' => (int) $scored['exposure'],
            'signals' => json_encode($scored['signals']), 'requests' => $profile['requests'], 'stopped' => $profile['stopped'],
            'first_seen_at' => Actor::now(), 'last_seen_at' => Actor::now(),
        ], $attrs));
        $f->setProfile($profile);
        $this->assertTrue($f->save(), json_encode($f->getErrors()));
        return $f;
    }

    private static function keys(EdgeFinding $f): array
    {
        return array_column(EdgeAdvisor::recommendations($f), 'key');
    }

    public function testExposureRecommendsIncidentBlockAndWafRule(): void
    {
        $f = $this->finding(['/.env' => [200 => 1], '/.git/config' => [403 => 1]]);
        $recs = EdgeAdvisor::recommendations($f);
        $this->assertSame(['incident', 'block', 'waf'], array_column($recs, 'key'));
        $this->assertStringContainsString('/.env', $recs[0]['detail']);
        $this->assertSame('Block the address for 7 days', $recs[1]['title']);
        $this->assertStringContainsString('only recorded on the blocklist', $recs[1]['detail'], 'Cloudflare not configured');

        putenv('CLOUDFLARE_API_TOKEN=t');
        putenv('CLOUDFLARE_ZONE_ID=z');
        Setting::put(['edge.block_days' => '30']);
        $recs = EdgeAdvisor::recommendations($f);
        $this->assertSame('Block the address for 30 days', $recs[1]['title']);
        $this->assertStringContainsString('taken out again when it expires', $recs[1]['detail']);

        // Once an incident is linked and the finding is closed, only the rule advice is left
        $f->incident_id = null;
        $f->status = EdgeFinding::STATUS_ESCALATED;
        $this->assertSame(['incident', 'waf'], self::keys($f), 'still no incident linked');
    }

    public function testOtherRecommendations(): void
    {
        // Low score probe: only the WAF rule advice (no block)
        $this->assertSame(['waf'], self::keys($this->finding(['/wp-login.php' => [404 => 1]])));
        // A flood
        $this->assertSame(['rate-limit'], self::keys($this->finding([], ['requests' => 6000, 'peak_rpm' => 400])));
        // A survey scanner
        $this->assertSame(['research'], self::keys($this->finding([], ['agents' => ['CensysInspect/1.1' => 3]])));
        // Nothing specific: dismiss
        $this->assertSame(['dismiss'], self::keys($this->finding([], ['agents' => ['curl/8' => 1]])));
        // Intel says malicious: block even with a low edge score
        $this->assertSame(['block'], self::keys($this->finding([], ['agents' => ['curl/8' => 1]], ['intel_rating' => 'malicious'])));
        // Closed findings get no dismiss / block advice
        $this->assertSame([], self::keys($this->finding([], ['agents' => ['curl/8' => 1]], ['status' => EdgeFinding::STATUS_DISMISSED])));
        // A rule gap
        $gap = $this->finding(['/.env' => [404 => 1]]);
        $gap->signals = json_encode([['key' => 'rule-gap', 'weight' => 45, 'tone' => 'high', 'short' => 'x', 'label' => 'x', 'detail' => 'Check the rule.']]);
        $recs = EdgeAdvisor::recommendations($gap);
        $this->assertSame(['rule-gap'], array_column($recs, 'key'), 'already blocked, so no block advice');
        $this->assertSame('Check the rule.', $recs[0]['detail']);
    }

    public function testAsnClusters(): void
    {
        $a = $this->finding(['/wp-login.php' => [404 => 1]], [], ['asn' => 64501]);
        $this->finding(['/wp-login.php' => [404 => 1]], [], ['asn' => 64501, 'status' => EdgeFinding::STATUS_DISMISSED]);
        $this->assertSame([], EdgeAdvisor::asnClusters(), 'two are not a cluster');
        $this->finding(['/xmlrpc.php' => [404 => 1]], [], ['asn' => 64501]);
        $this->finding([], [], ['asn' => 64501, 'status' => EdgeFinding::STATUS_IGNORED]);
        $this->finding([], [], ['asn' => 64501, 'last_seen_at' => date('Y-m-d H:i:s', strtotime('-40 days'))]);
        $this->assertSame([64501 => ['count' => 3, 'open' => 2, 'blocked' => false]], EdgeAdvisor::asnClusters(), 'ignored and old ones do not count');
        $recs = EdgeAdvisor::recommendations($a);
        $asn = array_values(array_filter($recs, static fn($r) => $r['key'] === 'asn'))[0];
        $this->assertSame(64501, $asn['asn']);
        $this->assertSame('AS64501 has 3 findings in 30 days', $asn['title']);

        $block = new BlocklistEntry(['value' => 'AS64501', 'severity' => 'high', 'direction' => 'inbound', 'reason' => 'test']);
        $this->assertTrue($block->save());
        $this->assertTrue(EdgeAdvisor::asnClusters()[64501]['blocked']);
        $this->assertNotContains('asn', self::keys($a), 'already blocked');
    }

    public function testWafRule(): void
    {
        $this->assertSame(['groups' => [], 'expression' => null], EdgeAdvisor::wafRule());
        $this->finding(['/.env' => [404 => 3], '/wp-login.php' => [404 => 1], '/plain' => [404 => 1]]);
        $this->finding(['/wp-login.php' => [404 => 2], '/xmlrpc.php' => [404 => 5]]);
        $this->finding(['/phpmyadmin/' => [404 => 1]], [], ['status' => EdgeFinding::STATUS_IGNORED]);
        $rule = EdgeAdvisor::wafRule();
        $this->assertSame(['wordpress', 'secrets'], array_keys($rule['groups']), 'most findings first; ignored ones left out');
        $this->assertSame(2, $rule['groups']['wordpress']['findings']);
        $this->assertSame(['/xmlrpc.php' => 5, '/wp-login.php' => 3], $rule['groups']['wordpress']['paths']);
        $this->assertStringContainsString('"/wp-login.php"', $rule['expression']);
        $this->assertStringContainsString('"/.env"', $rule['expression']);
        $this->assertStringNotContainsString('phpmyadmin', $rule['expression']);

        Setting::put(['edge.ignore_paths' => "/wp-\n/xmlrpc.php"]);
        $rule = EdgeAdvisor::wafRule();
        $this->assertSame(['secrets'], array_keys($rule['groups']), 'paths your sites serve drop out');
        $this->assertStringNotContainsString('wp-', $rule['expression']);
    }

    public function testFindingModel(): void
    {
        $f = $this->finding(['/.env' => [200 => 1]], ['requests' => 10, 'stopped' => 4]);
        $this->assertMatchesRegularExpression('/^EDG-\d{4}$/', $f->reference);
        $this->assertSame(6, $f->getReached());
        $this->assertTrue($f->getIsOpen());
        $this->assertSame('New', $f->getStatusLabel());
        $this->assertSame(['/intel/investigate/ip', 'ip' => $f->ip], array_intersect_key($f->getIndicatorUrl(), [0 => 1, 'ip' => 1]));
        $this->assertSame($f->ip, $f->getTitleValue());
        $this->assertSame(['/intel/edge/view', 'id' => $f->id], $f->getViewUrl());
        $this->assertSame('exposure', $f->getSignalList()[0]['key']);
        $this->assertSame(10, $f->getProfile()['requests']);
        $this->assertSame([], (new EdgeFinding())->getSignalList());
        $this->assertSame(EdgeAnalyzer::emptyProfile(), (new EdgeFinding())->getProfile());

        $this->assertTrue($f->resolve(EdgeFinding::STATUS_IGNORED, "  our monitor \n"));
        $this->assertSame('our monitor', $f->note);
        $this->assertSame(Actor::id(), (int) $f->resolved_by);
        $this->assertNotNull($f->resolved_at);
        $this->assertFalse($f->getIsOpen());
        $this->assertTrue($f->resolve(EdgeFinding::STATUS_DISMISSED, ''));
        $this->assertSame('our monitor', $f->note, 'a blank note keeps the old one');
        $this->assertTrue($f->reopen());
        $this->assertNull($f->resolved_at);
        $this->assertSame(EdgeFinding::STATUS_NEW, $f->status);

        $f->status = 'bogus';
        $this->assertFalse($f->validate());

        // Audit values for links
        $block = new BlocklistEntry(['value' => $f->ip, 'severity' => 'high', 'direction' => 'inbound', 'reason' => 'test']);
        $this->assertTrue($block->save());
        $this->assertSame($block->reference, $f->formatAuditValue('blocklist_id', $block->id));
        $this->assertSame('#999999', $f->formatAuditValue('blocklist_id', 999999));
        $this->assertSame('#999999', $f->formatAuditValue('incident_id', 999999));
        $this->assertSame('New', $f->formatAuditValue('status', 'new'));
        $this->assertSame('Edge finding', EdgeFinding::entityLabel());
    }
}
