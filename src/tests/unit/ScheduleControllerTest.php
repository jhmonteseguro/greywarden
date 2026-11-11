<?php

namespace tests\unit;

use app\commands\ScheduleController;
use app\components\Actor;
use app\models\AuditLog;
use app\modules\cloud\components\CloudflareZones;
use app\modules\cloud\models\CloudAsset;
use app\modules\intel\components\edge\CloudflareTraffic;
use app\modules\intel\components\edge\EdgePuller;
use app\modules\intel\components\edge\EdgeSettings;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\EdgeFinding;
use tests\DbTestCase;
use tests\support\FakeCloudflare;
use tests\support\OutputCapture;
use Yii;
use yii\console\ExitCode;
use yii\mutex\FileMutex;

/**
 * The scheduler container's loop (php yii schedule/run): which jobs are on,
 * when they are due, and what they log. Cloudflare is faked.
 */
class ScheduleControllerTest extends DbTestCase
{
    private FakeCloudflare $cf;

    protected function setUp(): void
    {
        parent::setUp();
        Yii::$app->cache->flush();
        // The zone sync has its own test (testCloudflareSync); off elsewhere so the other jobs run alone
        putenv('CLOUDFLARE_SYNC_INTERVAL=0');
        $this->cf = (new FakeCloudflare())->install();
        $this->cf->graphql = static function (array $request): array {
            if (str_contains($request['query'], 'EdgeSettings')) {
                $l = ['enabled' => true, 'availableFields' => null, 'maxDuration' => 86400, 'maxPageSize' => 1000, 'notOlderThan' => 86400];
                return ['body' => json_encode(['data' => ['viewer' => ['zones' => [['settings' => [CloudflareTraffic::HTTP => $l, CloudflareTraffic::FIREWALL => $l]]]]]])];
            }
            $rows = str_contains($request['query'], 'clientAsn')
                ? [['count' => 3, 'dimensions' => ['clientIP' => '192.0.2.95', 'clientAsn' => 64501, 'clientCountryName' => 'NL', 'userAgent' => 'nuclei', 'clientRequestHTTPMethodName' => 'GET']]] : [];
            return ['body' => json_encode(['data' => ['viewer' => ['zones' => [['rows' => $rows]]]]])];
        };
    }

    protected function tearDown(): void
    {
        FakeCloudflare::uninstall();
        putenv('EDGE_PULL_INTERVAL');
        putenv('CLOUDFLARE_SYNC_INTERVAL');
        Yii::$app->cache->flush();
        Actor::override(null);
        parent::tearDown();
    }

    private function controller(): ScheduleController
    {
        $c = new ScheduleController('schedule', Yii::$app);
        $c->once = true;
        return $c;
    }

    /** @return array{string[], string} jobs run and the output */
    private function tick(ScheduleController $c, int $now): array
    {
        OutputCapture::start();
        try {
            $ran = $c->tick($now);
        } finally {
            $out = OutputCapture::stop();
        }
        return [$ran, $out];
    }

    public function testInterval(): void
    {
        $this->assertSame(15, EdgeSettings::pullInterval());
        foreach (['5' => 5, '0' => 0, '-3' => 0, '5000' => 1440, 'x' => 0] as $value => $minutes) {
            putenv("EDGE_PULL_INTERVAL=$value");
            $this->assertSame($minutes, EdgeSettings::pullInterval(), (string) $value);
        }
    }

    public function testStatusAndRunOnce(): void
    {
        [$code, $out] = OutputCapture::run('schedule/status');
        $this->assertSame(ExitCode::OK, $code);
        $this->assertSame("Jobs: edge-pull every 15 min; expire-blocks off (every edge pull does it); threat-pull off (THREAT_PULL_INTERVAL=0); cloudflare-sync off (CLOUDFLARE_SYNC_INTERVAL=0).\n", $out);

        [$code, $out] = OutputCapture::run('schedule/run', ['once' => 1]);
        $this->assertSame(ExitCode::OK, $code);
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] Scheduler started\. Jobs: edge-pull every 15 min/', $out);
        $this->assertMatchesRegularExpression('/edge-pull .* → .*: \d+ requests from 1 addresses, 1 new, 0 updated\./', $out);
        $this->assertTrue(EdgeFinding::find()->where(['ip' => '192.0.2.95'])->exists());

        FakeCloudflare::uninstall();
        [, $out] = OutputCapture::run('schedule/status');
        $this->assertSame("Jobs: edge-pull off (Cloudflare is not configured); expire-blocks off (Cloudflare is not configured); threat-pull off (THREAT_PULL_INTERVAL=0); cloudflare-sync off (no CLOUDFLARE_API_TOKEN).\n", $out);
        [, $out] = OutputCapture::run('schedule/run', ['once' => 1]);
        $this->assertSame(1, substr_count($out, "\n"), 'only the start line: nothing ran');
    }

    public function testJobsRunWhenDue(): void
    {
        $c = $this->controller();
        $now = 1793260800;
        $this->assertSame(['edge-pull'], $this->tick($c, $now)[0]);
        $this->assertSame([], $this->tick($c, $now + 60)[0], 'not due yet');
        $this->assertSame(['edge-pull'], $this->tick($c, $now + 15 * 60 - 20)[0], 'a tick a little early still counts');

        putenv('EDGE_PULL_INTERVAL=5');
        $this->assertSame([], $this->tick($c, $now + 15 * 60 + 60)[0]);
        $this->assertSame(['edge-pull'], $this->tick($c, $now + 20 * 60)[0]);
    }

    public function testExpireBlocksWhenEdgePullsAreOff(): void
    {
        putenv('EDGE_PULL_INTERVAL=0');
        Actor::override($this->userId(), 'tester');
        $entry = new BlocklistEntry(['value' => '192.0.2.96', 'severity' => 'high', 'direction' => 'inbound', 'reason' => 't', 'cloudflare' => true,
            'expires_at' => date('Y-m-d H:i:s', time() + 60)]);
        $this->assertTrue($entry->validate() && $entry->syncCloudflare() && $entry->save(false));
        Actor::override(null);

        $c = $this->controller();
        [$ran, $out] = $this->tick($c, time());
        $this->assertSame(['expire-blocks'], $ran);
        $this->assertSame('', $out, 'nothing to report');

        BlocklistEntry::updateAll(['expires_at' => date('Y-m-d H:i:s', time() - 60)], ['id' => $entry->id]);
        [$ran, $out] = $this->tick($c, time() + 3600);
        $this->assertSame(['expire-blocks'], $ran);
        $this->assertStringContainsString("expire-blocks: taken out of Cloudflare: {$entry->reference}", $out);
        $this->assertSame([], $this->cf->items());

        // A failure is logged per entry
        BlocklistEntry::updateAll(['cloudflare_added_at' => date('Y-m-d H:i:s')], ['id' => $entry->id]);
        $this->cf->failWith = ['status' => 403, 'body' => json_encode(['success' => false, 'errors' => [['message' => 'Authentication error']]])];
        [, $out] = $this->tick($c, time() + 7200);
        $this->assertStringContainsString("expire-blocks: {$entry->reference}: Cloudflare: Authentication error", $out);
    }

    public function testCloudflareSync(): void
    {
        putenv('CLOUDFLARE_SYNC_INTERVAL');
        putenv('EDGE_PULL_INTERVAL=0');
        $this->assertSame(15, CloudflareZones::syncInterval());
        foreach (['5' => 5, '0' => 0, '-1' => 0, '9999' => 1440] as $value => $minutes) {
            putenv("CLOUDFLARE_SYNC_INTERVAL=$value");
            $this->assertSame($minutes, CloudflareZones::syncInterval(), (string) $value);
        }
        putenv('CLOUDFLARE_SYNC_INTERVAL');

        $c = $this->controller();
        $now = 1793260800;
        [$ran, $out] = $this->tick($c, $now);
        $this->assertSame(['expire-blocks', 'cloudflare-sync'], $ran);
        $this->assertStringContainsString('cloudflare-sync: 1 zone(s), 1 new, 0 updated, 0 failed.', $out);
        $zone = CloudAsset::findOne(['source' => 'cloudflare', 'source_id' => FakeCloudflare::ZONE]);
        $this->assertSame(['blocklist_rule' => 'enabled', 'dns_only' => ['mail.example.com (A)']],
            array_intersect_key($zone->getProperties(), ['blocklist_rule' => 1, 'dns_only' => 1]));

        // Nothing changed: quiet (only the seen time moves)
        [$ran, $out] = $this->tick($c, $now + 15 * 60);
        $this->assertSame(['cloudflare-sync'], $ran);
        $this->assertSame('', $out);

        // A record switched to DNS only and the blocklist rule disabled: logged, and in the zone's History
        $this->cf->dnsRecords[1]['proxied'] = false;
        $this->cf->rules[1]['enabled'] = false;
        [, $out] = $this->tick($c, $now + 30 * 60);
        $this->assertStringContainsString('cloudflare-sync: 1 zone(s), 0 new, 1 updated, 0 failed. Changed: example.com Greywarden Blocklist rule: Enabled → Disabled; '
            . 'example.com www.example.com (CNAME): Proxied → DNS only.', $out);
        $log = AuditLog::find()->where(['entity_type' => 'cloud_asset', 'entity_id' => $zone->id, 'action' => 'config_change'])->one();
        $this->assertSame([null, 'console'], [$log->user_id, $log->username], 'the scheduler, not a person');

        // A zone list failure is logged, not thrown
        $this->cf->failZoneWith = ['zones' => ['status' => 500, 'body' => json_encode(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Internal']]])]];
        [, $out] = $this->tick($c, $now + 45 * 60);
        $this->assertStringContainsString('cloudflare-sync skipped: Cloudflare: Internal (1000)', $out);

        // A configuration read that fails (not for permissions) keeps the zone as it was, with a warning
        $this->cf->failZoneWith = ['dnssec' => ['status' => 500, 'body' => json_encode(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Internal']]])]];
        [, $out] = $this->tick($c, $now + 60 * 60);
        $this->assertStringContainsString('example.com: DNSSEC not read: Cloudflare: Internal (1000)', $out);
        $this->assertSame(['mail.example.com (A)', 'www.example.com (CNAME)'], CloudAsset::findOne($zone->id)->getProperties()['dns_only']);
    }

    public function testFailuresAreLoggedAndTheLoopCarriesOn(): void
    {
        $c = $this->controller();
        // Another pull holds the lock
        $mutex = new FileMutex();
        $mutex->acquire(EdgePuller::MUTEX);
        try {
            [$ran, $out] = $this->tick($c, 1793260800);
        } finally {
            $mutex->release(EdgePuller::MUTEX);
        }
        $this->assertSame(['edge-pull'], $ran);
        $this->assertStringContainsString('edge-pull skipped: Another edge pull is running.', $out);

        // The pull fails
        $this->cf->graphql = static fn() => ['status' => 403, 'body' => json_encode(['data' => null, 'errors' => [['message' => 'not authorized']]])];
        [, $out] = $this->tick($c, 1793260800 + 3600);
        $this->assertStringContainsString('edge-pull failed: Cloudflare analytics: not authorized', $out);

        // A job that throws
        $c = new class ('schedule', Yii::$app) extends ScheduleController {
            public function jobs(): array
            {
                return ['broken' => ['interval' => 1, 'off' => null, 'run' => static function (): void {
                    throw new \LogicException('boom');
                }]];
            }
        };
        $c->once = true;
        [$ran, $out] = $this->tick($c, 1793260800);
        $this->assertSame(['broken'], $ran);
        $this->assertStringContainsString('broken failed: boom', $out);
    }
}
