<?php

namespace tests\unit;

use app\components\Actor;
use app\modules\intel\components\Cloudflare;
use app\modules\intel\components\edge\CloudflareTraffic;
use app\modules\intel\components\edge\EdgePuller;
use app\modules\intel\components\Http;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\EdgeFinding;
use app\modules\intel\models\EdgePull;
use tests\DbTestCase;
use tests\support\FakeCloudflare;
use tests\support\OutputCapture;
use Yii;
use yii\console\ExitCode;
use yii\mutex\FileMutex;

/**
 * php yii edge/pull, edge/status and intel/expire-blocks against fake Cloudflare APIs.
 */
class EdgeConsoleTest extends DbTestCase
{
    private FakeCloudflare $cf;

    protected function setUp(): void
    {
        parent::setUp();
        Yii::$app->cache->flush();
        $this->cf = (new FakeCloudflare())->install();
        $this->cf->graphql = static function (array $request): array {
            $q = $request['query'];
            if (str_contains($q, 'EdgeSettings')) {
                $limits = ['enabled' => true, 'availableFields' => ['clientIP', 'clientRequestPath', 'edgeResponseStatus'], 'maxDuration' => 86400, 'maxPageSize' => 1000, 'notOlderThan' => 86400];
                return ['body' => json_encode(['data' => ['viewer' => ['zones' => [['settings' => [CloudflareTraffic::HTTP => $limits, CloudflareTraffic::FIREWALL => ['enabled' => false]]]]]]])];
            }
            $rows = str_contains($q, 'clientAsn')
                ? [['count' => 4, 'dimensions' => ['clientIP' => '192.0.2.90', 'clientAsn' => 64501, 'clientCountryName' => 'NL', 'userAgent' => 'sqlmap/1.8', 'clientRequestHTTPMethodName' => 'GET']]]
                : [];
            return ['body' => json_encode(['data' => ['viewer' => ['zones' => [['rows' => $rows]]]]])];
        };
    }

    protected function tearDown(): void
    {
        FakeCloudflare::uninstall();
        Yii::$app->cache->flush();
        Actor::override(null);
        parent::tearDown();
    }

    public function testPull(): void
    {
        [$code, $out] = OutputCapture::run('edge/pull', ['minutes' => 0]);
        $this->assertSame(ExitCode::USAGE, $code);
        $this->assertStringContainsString('--minutes must be between', $out);
        [$code, $out] = OutputCapture::run('edge/pull', ['user' => 'no-such-user']);
        $this->assertSame(ExitCode::DATAERR, $code);

        [$code, $out] = OutputCapture::run('edge/pull', ['dryRun' => 1, 'enrich' => 0]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('[dry run]', $out);
        $this->assertStringContainsString('created   192.0.2.90', $out);
        $this->assertFalse(EdgeFinding::find()->where(['ip' => '192.0.2.90'])->exists());

        $user = \app\models\User::findOne($this->userId());
        [$code, $out] = OutputCapture::run('edge/pull', ['minutes' => 30, 'user' => $user->username, 'enrich' => 0]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('1 new finding(s)', $out);
        $this->assertSame($user->id, (int) EdgeFinding::findOne(['ip' => '192.0.2.90'])->created_by);
        $this->assertSame($user->id, (int) EdgePull::latest()->run_by);
        $this->assertNull(Actor::id(), 'the actor is reset');

        // Warnings are printed; failures exit with UNAVAILABLE
        Actor::override($user->id, $user->username);
        $entry = new BlocklistEntry(['value' => '192.0.2.91', 'severity' => 'high', 'direction' => 'inbound', 'reason' => 't', 'cloudflare' => true, 'expires_at' => date('Y-m-d H:i:s', time() + 60)]);
        $this->assertTrue($entry->validate() && $entry->syncCloudflare() && $entry->save(false));
        Actor::override(null);
        BlocklistEntry::updateAll(['expires_at' => date('Y-m-d H:i:s', time() - 60)], ['id' => $entry->id]);
        [$code, $out] = OutputCapture::run('edge/pull', ['minutes' => 30, 'enrich' => 0]);
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('updated   192.0.2.90', $out);
        $this->assertStringContainsString('Expired blocks taken out of Cloudflare', $out);

        putenv('CLOUDFLARE_ZONE_ID');
        [$code, $out] = OutputCapture::run('edge/pull');
        $this->assertSame(ExitCode::UNAVAILABLE, $code);
        $this->assertStringContainsString('Pull failed: Cloudflare is not configured', $out);

        $mutex = new FileMutex();
        $mutex->acquire(EdgePuller::MUTEX);
        try {
            [$code, $out] = OutputCapture::run('edge/pull');
        } finally {
            $mutex->release(EdgePuller::MUTEX);
        }
        $this->assertSame(ExitCode::TEMPFAIL, $code);
        $this->assertStringContainsString('Another edge pull is running', $out);
    }

    public function testStatus(): void
    {
        [$code, $out] = OutputCapture::run('edge/status');
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString(CloudflareTraffic::HTTP . '     enabled', $out);
        $this->assertStringContainsString(CloudflareTraffic::FIREWALL . '   DISABLED', $out);
        $this->assertStringContainsString('Missing fields: userAgent', $out);
        $this->assertStringContainsString('Query strings (attack payloads): not on this plan', $out);
        $this->assertStringContainsString('open findings from score 30, blocks for 7 days', $out);

        (new EdgePull(['started_at' => '2026-10-28 10:00:00', 'status' => EdgePull::STATUS_ERROR, 'message' => 'Boom']))->save(false);
        (new EdgePull(['started_at' => '2026-10-28 10:15:00', 'status' => EdgePull::STATUS_OK, 'findings_new' => 2]))->save(false);
        putenv('CLOUDFLARE_ZONE_ID');
        [$code, $out] = OutputCapture::run('edge/status');
        $this->assertStringContainsString('Cloudflare: not configured', $out);
        $this->assertStringContainsString('Boom', $out);
        $this->assertStringContainsString('2 new, 0 updated', $out);

        // Zone not found
        putenv('CLOUDFLARE_ZONE_ID=zone1');
        Http::$fake = static fn() => ['body' => json_encode(['data' => ['viewer' => ['zones' => []]]])];
        [$code, $out] = OutputCapture::run('edge/status');
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('zone was not found', $out);

        // Settings unreadable: the fallback is shown with its warning
        Http::$fake = static fn() => ['status' => 500, 'body' => ''];
        [, $out] = OutputCapture::run('edge/status');
        $this->assertStringContainsString('Plan limits unknown', $out);
        $this->assertStringContainsString('All required fields available', $out);
    }

    public function testExpireBlocks(): void
    {
        [$code, $out] = OutputCapture::run('intel/expire-blocks');
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('No expired entries', $out);

        Actor::override($this->userId(), 'tester');
        $entry = new BlocklistEntry(['value' => '192.0.2.92', 'severity' => 'high', 'direction' => 'inbound', 'reason' => 't', 'cloudflare' => true, 'expires_at' => date('Y-m-d H:i:s', time() + 60)]);
        $this->assertTrue($entry->validate() && $entry->syncCloudflare() && $entry->save(false));
        Actor::override(null);
        BlocklistEntry::updateAll(['expires_at' => date('Y-m-d H:i:s', time() - 60)], ['id' => $entry->id]);

        $this->cf->failWith = ['status' => 403, 'body' => json_encode(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]])];
        [$code, $out] = OutputCapture::run('intel/expire-blocks');
        $this->assertSame(ExitCode::UNAVAILABLE, $code);
        $this->assertStringContainsString("{$entry->reference}: Cloudflare: Authentication error", $out);

        putenv('CLOUDFLARE_ZONE_ID');
        [$code, $out] = OutputCapture::run('intel/expire-blocks');
        $this->assertSame(ExitCode::UNAVAILABLE, $code);
        $this->assertStringContainsString('Cloudflare is not configured', $out);

        putenv('CLOUDFLARE_ZONE_ID=zone1');
        $this->cf->failWith = null;
        [$code, $out] = OutputCapture::run('intel/expire-blocks');
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString("{$entry->reference} taken out of Cloudflare", $out);
        $this->assertSame([], $this->cf->items());
    }
}
