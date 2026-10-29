<?php

namespace tests\unit;

use app\components\Actor;
use app\models\AuditLog;
use app\models\Setting;
use app\modules\intel\components\Cloudflare;
use app\modules\intel\components\edge\CloudflareTraffic;
use app\modules\intel\components\edge\EdgePuller;
use app\modules\intel\components\Http;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\EdgeFinding;
use app\modules\intel\models\EdgePull;
use app\modules\intel\models\IntelIndicator;
use RuntimeException;
use tests\DbTestCase;
use tests\support\FakeCloudflare;
use Yii;
use yii\mutex\FileMutex;

/**
 * Edge pulls against a fake Cloudflare GraphQL Analytics API (and the rules and
 * lists APIs for the blocklist, tests\support\FakeCloudflare): windows, plan limits, findings opened / merged / reopened,
 * partial failures and expired blocks. No test reaches Cloudflare.
 */
class EdgePullerTest extends DbTestCase
{
    private const NOW = 1793260800; // 2026-10-28 06:00:00 UTC

    /** Plan limits returned by the settings node (null = settings query fails) */
    private ?array $limits;
    /** query name => rows, or a string error */
    private array $data = [];
    /** @var array[] decoded GraphQL requests */
    private array $queries = [];
    private FakeCloudflare $cf;

    protected function setUp(): void
    {
        parent::setUp();
        Yii::$app->cache->flush();
        $this->limits = ['enabled' => true, 'availableFields' => ['clientIP', 'clientRequestPath', 'clientRequestQuery', 'requestSource', 'edgeResponseStatus'],
            'maxDuration' => 86400, 'maxPageSize' => 1000, 'notOlderThan' => 3 * 86400];
        $this->cf = (new FakeCloudflare())->install();
        $this->cf->graphql = fn(array $request): array => $this->graphql($request);
    }

    protected function tearDown(): void
    {
        FakeCloudflare::uninstall();
        Yii::$app->cache->flush();
        Actor::override(null);
        parent::tearDown();
    }

    private function graphql(array $request): array
    {
        $q = $request['query'];
        $this->queries[] = $request;
        if (str_contains($q, 'EdgeSettings')) {
            if ($this->limits === null) {
                return ['body' => json_encode(['data' => null, 'errors' => [['message' => 'settings unavailable']]])];
            }
            if ($this->limits === []) {
                return ['body' => json_encode(['data' => ['viewer' => ['zones' => []]]])];
            }
            $fw = array_key_exists('fw-limits', $this->data) ? $this->data['fw-limits'] : $this->limits;
            return ['body' => json_encode(['data' => ['viewer' => ['zones' => [['settings' => [CloudflareTraffic::HTTP => $this->limits, CloudflareTraffic::FIREWALL => $fw]]]]]])];
        }
        $name = match (true) {
            str_contains($q, CloudflareTraffic::FIREWALL) => 'firewall',
            str_contains($q, 'clientAsn') => 'ips',
            str_contains($q, 'edgeResponseStatus_geq') => 'errors',
            str_contains($q, 'clientRequestQuery_like') => 'payloads',
            default => 'exposures',
        };
        $rows = $this->data[$name] ?? [];
        if (is_string($rows)) {
            return ['body' => json_encode(['data' => null, 'errors' => [['message' => $rows]]])];
        }
        return ['body' => json_encode(['data' => ['viewer' => ['zones' => [['rows' => $rows]]]], 'errors' => null])];
    }

    private static function ipRow(string $ip, int $count, string $agent = 'Mozilla/5.0 Chrome/129', string $method = 'GET', int $asn = 64501): array
    {
        return ['count' => $count, 'dimensions' => ['clientIP' => $ip, 'clientAsn' => $asn, 'clientCountryName' => 'NL', 'userAgent' => $agent, 'clientRequestHTTPMethodName' => $method]];
    }

    private static function pathRow(string $ip, string $path, int $status, int $count = 1, string $query = ''): array
    {
        return ['count' => $count, 'dimensions' => ['clientIP' => $ip, 'clientRequestPath' => $path, 'edgeResponseStatus' => $status, 'clientRequestQuery' => $query]];
    }

    private function puller(array $props = []): EdgePuller
    {
        $puller = new EdgePuller();
        $puller->now = self::NOW;
        $puller->enrich = false;
        foreach ($props as $k => $v) {
            $puller->$k = $v;
        }
        return $puller;
    }

    private function trafficQuery(string $name): ?string
    {
        foreach ($this->queries as $q) {
            if (str_contains($q['query'], 'EdgeTraffic') && match ($name) {
                'ips' => str_contains($q['query'], 'clientAsn'),
                'payloads' => str_contains($q['query'], 'clientRequestQuery_like'),
                'firewall' => str_contains($q['query'], CloudflareTraffic::FIREWALL),
                default => false,
            }) {
                return $q['query'];
            }
        }
        return null;
    }

    public function testNotConfigured(): void
    {
        putenv('CLOUDFLARE_ZONE_ID');
        $pull = $this->puller()->run();
        $this->assertSame(EdgePull::STATUS_ERROR, $pull->status);
        $this->assertStringContainsString('not configured', $pull->message);
        $this->assertNotNull($pull->id, 'failed pulls are logged');
        $this->assertSame([], $this->queries);
    }

    public function testFirstPullOpensFindings(): void
    {
        Setting::put(['edge.allowlist' => '203.0.113.99']);
        $this->data = [
            'ips' => [
                self::ipRow('192.0.2.10', 20), self::ipRow('192.0.2.11', 300, 'sqlmap/1.8'), self::ipRow('192.0.2.12', 50),
                self::ipRow('192.0.2.13', 10, 'Nikto/2.5'), self::ipRow('203.0.113.99', 5, 'sqlmap/1.8'), self::ipRow('192.0.2.14', 1, 'curl/8'),
            ],
            'errors' => [self::pathRow('192.0.2.10', '/.git/config', 403), self::pathRow('192.0.2.11', '/wp-login.php', 404, 3)],
            'exposures' => [self::pathRow('192.0.2.10', '/.env', 200, 2)],
            'payloads' => [self::pathRow('192.0.2.11', '/p', 200, 4, 'id=1 union select 1')],
            'firewall' => [['count' => 10, 'dimensions' => ['clientIP' => '192.0.2.13', 'action' => 'managed_challenge']]],
        ];
        $puller = $this->puller();
        $pull = $puller->run();
        $this->assertSame(EdgePull::STATUS_OK, $pull->status, (string) $pull->message);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW - 120 - 3600), $pull->window_start, 'first pull: an hour back');
        $this->assertSame(date('Y-m-d H:i:s', self::NOW - 120), $pull->window_end, 'two minutes of lag');
        $this->assertSame(5, $pull->ips, 'the allowlisted address is left out');
        $this->assertSame(2, $pull->findings_new);
        $this->assertSame(['192.0.2.10' => 'created', '192.0.2.11' => 'created'], $puller->changes,
            '.12 is clean, .13 was stopped by Cloudflare, .14 (curl alone) scores below 30');

        $exposed = EdgeFinding::findOne(['ip' => '192.0.2.10']);
        $this->assertMatchesRegularExpression('/^EDG-\d{4}$/', $exposed->reference);
        $this->assertSame('high', $exposed->priority);
        $this->assertSame(1, (int) $exposed->exposure);
        $this->assertSame(EdgeFinding::STATUS_NEW, $exposed->status);
        $this->assertSame(64501, (int) $exposed->asn);
        $this->assertSame(1, (int) $exposed->times_seen);
        $this->assertSame($pull->window_end, $exposed->last_seen_at);
        $this->assertSame('exposure', $exposed->getSignalList()[0]['key']);
        $this->assertSame([200 => 2], $exposed->getProfile()['paths']['/.env']['statuses']);
        $scanner = EdgeFinding::findOne(['ip' => '192.0.2.11']);
        $this->assertSame(['payload', 'agent', 'probe'], array_column($scanner->getSignalList(), 'key'));
        $this->assertTrue(AuditLog::find()->where(['entity_type' => 'edge-finding', 'entity_id' => $scanner->id, 'action' => 'create'])->exists());

        // The queries: visitor traffic in the window, payloads because the plan has query strings
        $ips = $this->trafficQuery('ips');
        $this->assertStringContainsString('requestSource: "eyeball"', $ips);
        $this->assertStringContainsString(sprintf('datetime_geq: "%s"', gmdate('Y-m-d\TH:i:s\Z', self::NOW - 120 - 3600)), $ips);
        $this->assertStringContainsString('limit: 1000', $ips);
        $this->assertStringContainsString('{clientRequestQuery_like: "%union%select%"}', $this->trafficQuery('payloads'));
        $this->assertSame('zone1', $this->queries[1]['variables']['zoneTag']);
    }

    public function testLaterPullsMergeReopenAndKeepIgnored(): void
    {
        $this->data = ['ips' => [self::ipRow('192.0.2.20', 5, 'sqlmap/1.8'), self::ipRow('192.0.2.21', 5, 'sqlmap/1.8'), self::ipRow('192.0.2.22', 5, 'sqlmap/1.8')]];
        $this->puller()->run();
        $dismissed = EdgeFinding::findOne(['ip' => '192.0.2.20']);
        $ignored = EdgeFinding::findOne(['ip' => '192.0.2.21']);
        $open = EdgeFinding::findOne(['ip' => '192.0.2.22']);
        $this->assertTrue($dismissed->resolve(EdgeFinding::STATUS_DISMISSED, 'noise'));
        $this->assertTrue($ignored->resolve(EdgeFinding::STATUS_IGNORED));
        $audits = AuditLog::find()->where(['entity_type' => 'edge-finding'])->count();

        $puller = $this->puller(['now' => self::NOW + 900]);
        $pull = $puller->run();
        $this->assertSame(date('Y-m-d H:i:s', self::NOW - 120), $pull->window_start, 'starts where the last pull ended');
        $this->assertSame(['192.0.2.20' => 'reopened', '192.0.2.21' => 'updated', '192.0.2.22' => 'updated'], $puller->changes);
        $this->assertSame(3, $pull->findings_updated);

        $dismissed->refresh();
        $this->assertSame(EdgeFinding::STATUS_NEW, $dismissed->status);
        $this->assertNull($dismissed->resolved_at);
        $this->assertStringContainsString('after being dismissed', $dismissed->note);
        $ignored->refresh();
        $this->assertSame(EdgeFinding::STATUS_IGNORED, $ignored->status, 'ignored addresses never reopen');
        $this->assertSame(10, (int) $ignored->requests, 'but are still counted');
        $this->assertSame(2, (int) $ignored->times_seen);
        $open->refresh();
        $this->assertSame(10, (int) $open->requests);
        // Only the reopen is audited, not the refreshed statistics
        $this->assertSame($audits + 1, (int) AuditLog::find()->where(['entity_type' => 'edge-finding'])->count());
    }

    public function testWindowLimits(): void
    {
        $limits = ['maxDuration' => 86400, 'notOlderThan' => 3 * 86400];
        [$start, $end, $note] = $this->puller(['minutes' => 30])->window($limits);
        $this->assertSame(1800, $end - $start);
        $this->assertNull($note);

        (new EdgePull(['started_at' => '2026-10-01 00:00:00', 'status' => EdgePull::STATUS_OK, 'window_end' => date('Y-m-d H:i:s', self::NOW - 10 * 86400)]))->save(false);
        [$start, , $note] = $this->puller()->window(['maxDuration' => 30 * 86400, 'notOlderThan' => 3 * 86400]);
        $this->assertSame(self::NOW - 3 * 86400 + 60, $start);
        $this->assertStringContainsString('keeps 3 days of analytics', $note);

        // A run reports the cut-short window (here the one-day query limit wins)
        $pull = $this->puller()->run();
        $this->assertSame(EdgePull::STATUS_PARTIAL, $pull->status);
        $this->assertStringContainsString('at most 1 day on this plan, so only the most recent 1 day was read', $pull->message);
        EdgePull::deleteAll(['id' => $pull->id]);

        [$start, $end, $note] = $this->puller()->window(['maxDuration' => 3600, 'notOlderThan' => 3 * 86400]);
        $this->assertSame(3600, $end - $start);
        $this->assertStringContainsString('at most 1 hour', $note);
        [, , $note] = $this->puller()->window(['maxDuration' => 1800, 'notOlderThan' => 3 * 86400]);
        $this->assertStringContainsString('at most 30 minutes', $note);

        // Pulled less than a minute ago: nothing to read
        $this->queries = [];
        (new EdgePull(['started_at' => '2026-10-28 00:00:00', 'status' => EdgePull::STATUS_OK, 'window_end' => date('Y-m-d H:i:s', self::NOW - 120)]))->save(false);
        $pull = $this->puller()->run();
        $this->assertSame(EdgePull::STATUS_OK, $pull->status);
        $this->assertStringContainsString('Nothing new', $pull->message);
        $this->assertSame([], array_filter($this->queries, static fn($q) => str_contains($q['query'], 'EdgeTraffic')));
    }

    public function testPlanLimitsShapeTheQueries(): void
    {
        // A plan without query strings or request source, and without firewall analytics
        $this->limits['availableFields'] = ['clientIP', 'clientRequestPath'];
        $this->data['fw-limits'] = null; // not in the settings node at all
        $this->data['ips'] = array_fill(0, 100, self::ipRow('192.0.2.30', 1));
        $this->limits['maxPageSize'] = 3;
        $pull = $this->puller()->run();
        $this->assertSame(EdgePull::STATUS_PARTIAL, $pull->status);
        $this->assertStringContainsString('Page limit reached (ips)', $pull->message);
        $this->assertStringNotContainsString('requestSource', $this->trafficQuery('ips'));
        $this->assertStringContainsString('limit: 100', $this->trafficQuery('ips'), 'at least 100 rows');
        $this->assertNull($this->trafficQuery('payloads'));
        $this->assertNull($this->trafficQuery('firewall'));
        $this->assertFalse(CloudflareTraffic::hasField(CloudflareTraffic::settings()['http'], 'userAgent'));
        $this->assertTrue(CloudflareTraffic::hasField(['availableFields' => ['dimensions.userAgent']], 'userAgent'));
        $this->assertTrue(CloudflareTraffic::hasField(['availableFields' => null], 'anything'));
    }

    public function testSettingsFallbackAndFailures(): void
    {
        $this->limits = null;
        $pull = $this->puller()->run();
        $this->assertSame(EdgePull::STATUS_PARTIAL, $pull->status);
        $this->assertStringContainsString('Plan limits unknown', $pull->message);
        $this->assertStringContainsString('limit: 5000', $this->trafficQuery('ips'), 'fallback page size');

        Yii::$app->cache->flush();
        $this->limits = [];
        $pull = $this->puller()->run();
        $this->assertSame(EdgePull::STATUS_ERROR, $pull->status);
        $this->assertStringContainsString('zone was not found', $pull->message);

        $this->limits = ['enabled' => false];
        $pull = $this->puller(['minutes' => 15])->run();
        $this->assertSame(EdgePull::STATUS_ERROR, $pull->status);
        $this->assertStringContainsString('not available for this zone', $pull->message);
    }

    public function testQueryFailures(): void
    {
        $this->data = ['payloads' => 'field not allowed', 'firewall' => 'quota', 'ips' => [self::ipRow('192.0.2.40', 2, 'sqlmap')]];
        $pull = $this->puller()->run();
        $this->assertSame(EdgePull::STATUS_PARTIAL, $pull->status);
        $this->assertStringContainsString('payloads skipped: Cloudflare analytics: field not allowed', $pull->message);
        $this->assertStringContainsString('firewall skipped', $pull->message);
        $this->assertTrue(EdgeFinding::find()->where(['ip' => '192.0.2.40'])->exists(), 'the required queries still count');

        Yii::$app->cache->flush();
        EdgePull::deleteAll();
        $this->data['errors'] = 'does not have access';
        $pull = $this->puller()->run();
        $this->assertSame(EdgePull::STATUS_ERROR, $pull->status);
        $this->assertSame('Cloudflare analytics: does not have access', $pull->message);

        // Transport failure
        Http::$fake = static fn() => ['status' => 0, 'error' => 'Could not resolve host', 'body' => ''];
        Yii::$app->cache->flush();
        $pull = $this->puller()->run();
        $this->assertSame(EdgePull::STATUS_ERROR, $pull->status);
        $this->assertStringContainsString('Could not resolve host', $pull->message);
    }

    public function testRuleGapWhenABlockedAddressGetsThrough(): void
    {
        Actor::override($this->userId(), 'tester');
        $entry = new BlocklistEntry(['value' => '192.0.2.50', 'severity' => 'high', 'direction' => 'inbound', 'reason' => 'test', 'cloudflare' => true]);
        $this->assertTrue($entry->validate() && $entry->syncCloudflare() && $entry->save(false));
        $this->data = ['ips' => [self::ipRow('192.0.2.50', 12)], 'errors' => [self::pathRow('192.0.2.50', '/.env', 404)]];
        $this->puller()->run();
        $finding = EdgeFinding::findOne(['ip' => '192.0.2.50']);
        $this->assertSame('rule-gap', $finding->getSignalList()[0]['key']);
        $this->assertStringContainsString($entry->reference, $finding->getSignalList()[0]['label']);
    }

    public function testDryRunSavesNothing(): void
    {
        $this->data = ['ips' => [self::ipRow('192.0.2.60', 5, 'sqlmap')]];
        $puller = $this->puller(['dryRun' => true]);
        $pull = $puller->run();
        $this->assertSame(['192.0.2.60' => 'created'], $puller->changes);
        $this->assertSame(1, $pull->findings_new);
        $this->assertNull($pull->id);
        $this->assertFalse(EdgeFinding::find()->where(['ip' => '192.0.2.60'])->exists());
        $this->assertSame(0, (int) EdgePull::find()->count());
    }

    public function testExpiredBlocksAreTakenOutOfCloudflare(): void
    {
        Actor::override($this->userId(), 'tester');
        $entry = new BlocklistEntry(['value' => '192.0.2.70', 'severity' => 'high', 'direction' => 'inbound', 'reason' => 'test', 'cloudflare' => true,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->assertTrue($entry->validate() && $entry->syncCloudflare() && $entry->save(false));
        $this->assertSame(['192.0.2.70'], $this->cf->items());
        BlocklistEntry::updateAll(['expires_at' => date('Y-m-d H:i:s', time() - 60)], ['id' => $entry->id]);

        // The Cloudflare API refuses: a warning, and the entry stays in Cloudflare
        $this->cf->failWith = ['status' => 403, 'body' => json_encode(['success' => false, 'errors' => [['message' => 'Authentication error']]])];
        $pull = $this->puller(['minutes' => 15])->run();
        $this->assertStringContainsString("{$entry->reference} could not be taken out of Cloudflare: Cloudflare: Authentication error", $pull->message);
        $entry->refresh();
        $this->assertNotNull($entry->cloudflare_added_at);
        $this->cf->failWith = null;

        $pull = $this->puller(['minutes' => 15])->run();
        $this->assertSame(EdgePull::STATUS_PARTIAL, $pull->status);
        $this->assertStringContainsString("Expired blocks taken out of Cloudflare: {$entry->reference}", $pull->message);
        $this->assertSame([], $this->cf->items());
        $entry->refresh();
        $this->assertNull($entry->cloudflare_added_at);
        $this->assertSame('expired', $entry->getState());
    }

    public function testEnrichmentRecordsTheIntelRating(): void
    {
        $this->data = ['ips' => [self::ipRow('192.0.2.80', 5, 'sqlmap')]];
        $this->puller(['enrich' => true])->run();
        $finding = EdgeFinding::findOne(['ip' => '192.0.2.80']);
        $this->assertSame('special', $finding->intel_rating, 'documentation addresses are only checked locally');
        $this->assertNotNull($finding->intel_checked_at);
        $this->assertSame(1, (int) IntelIndicator::findOne(['value' => '192.0.2.80'])->lookup_count);
    }

    public function testSaveFailuresRollBackAndEndThePull(): void
    {
        $this->data = ['ips' => [self::ipRow('192.0.2.85', 5, 'sqlmap'), self::ipRow('192.0.2.86', 5, 'sqlmap')]];
        $handler = static function (): void {
            throw new \yii\db\Exception('Disk full');
        };
        \yii\base\Event::on(EdgeFinding::class, EdgeFinding::EVENT_AFTER_INSERT, $handler);
        try {
            $pull = $this->puller()->run();
        } finally {
            \yii\base\Event::off(EdgeFinding::class, EdgeFinding::EVENT_AFTER_INSERT, $handler);
        }
        $this->assertSame(EdgePull::STATUS_ERROR, $pull->status);
        $this->assertSame('Disk full', $pull->message);
        $this->assertFalse(EdgeFinding::find()->where(['ip' => ['192.0.2.85', '192.0.2.86']])->exists(), 'rolled back');

        // A finding that does not validate
        $profile = \app\modules\intel\components\edge\EdgeAnalyzer::profiles(['ips' => [['ip' => '192.0.2.87', 'asn' => null, 'country' => null, 'agent' => 'sqlmap', 'method' => 'GET', 'count' => 1]]], 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Finding for 192.0.2.87 not saved');
        $this->puller()->apply($profile, new EdgePull(), '');
    }

    public function testEnrichSkipsInvalidAddresses(): void
    {
        $finding = new EdgeFinding(['ip' => 'not-an-ip']);
        EdgePuller::enrich($finding);
        $this->assertNull($finding->intel_checked_at);
    }

    public function testOnlyOnePullAtATime(): void
    {
        $mutex = new FileMutex();
        $this->assertTrue($mutex->acquire(EdgePuller::MUTEX));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Another edge pull is running');
            $this->puller()->run();
        } finally {
            $mutex->release(EdgePuller::MUTEX);
        }
    }
}
