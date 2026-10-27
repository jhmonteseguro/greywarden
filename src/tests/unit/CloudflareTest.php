<?php

namespace tests\unit;

use app\modules\intel\components\Cloudflare;
use app\modules\intel\components\Http;
use app\modules\intel\models\BlocklistEntry;
use RuntimeException;
use tests\DbTestCase;

/**
 * Pushing blocklist entries to the Cloudflare "Block Bad Actors" rule, against a
 * fake Rulesets API (no test reaches Cloudflare).
 */
class CloudflareTest extends DbTestCase
{
    /** The fake zone's custom rules */
    private array $rules;
    /** @var array[] requests sent */
    private array $sent = [];
    private ?array $failWith = null;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('CLOUDFLARE_API_TOKEN=test-token');
        putenv('CLOUDFLARE_ZONE_ID=zone1');
        putenv('CLOUDFLARE_RULE_NAME');
        $this->rules = [
            ['id' => 'r-other', 'description' => 'Challenge bots', 'action' => 'managed_challenge', 'expression' => '(cf.client.bot)', 'enabled' => true],
            ['id' => 'r-block', 'description' => 'Block Bad Actors', 'action' => 'block', 'expression' => '(ip.src eq 192.0.2.1)', 'enabled' => true, 'version' => '3', 'last_updated' => 'x', 'ref' => 'abc'],
        ];
        Http::$fake = function (array $r): array {
            $this->sent[] = $r;
            if ($this->failWith) {
                return $this->failWith;
            }
            if ($r['method'] === 'GET' && str_ends_with($r['url'], '/zones/zone1/rulesets/phases/http_request_firewall_custom/entrypoint')) {
                return ['body' => json_encode(['success' => true, 'result' => ['id' => 'rs1', 'rules' => $this->rules]])];
            }
            if ($r['method'] === 'PATCH' && preg_match('#/zones/zone1/rulesets/rs1/rules/([\w-]+)$#', $r['url'], $m)) {
                foreach ($this->rules as &$rule) {
                    if ($rule['id'] === $m[1]) {
                        $rule = ['id' => $rule['id']] + json_decode($r['body'], true);
                    }
                }
                return ['body' => json_encode(['success' => true, 'result' => ['id' => 'rs1', 'rules' => $this->rules]])];
            }
            return ['status' => 404, 'body' => json_encode(['success' => false, 'errors' => [['code' => 7003, 'message' => 'Could not route']]])];
        };
    }

    protected function tearDown(): void
    {
        Http::$fake = null;
        putenv('CLOUDFLARE_API_TOKEN');
        putenv('CLOUDFLARE_ZONE_ID');
        parent::tearDown();
    }

    private function expression(): string
    {
        return $this->rules[1]['expression'];
    }

    private function block(string $value, array $attrs = []): BlocklistEntry
    {
        $e = new BlocklistEntry(array_merge(['value' => $value, 'severity' => 'high', 'direction' => 'both', 'reason' => 'test', 'cloudflare' => true], $attrs));
        $this->assertTrue($e->validate() && $e->syncCloudflare() && $e->save(false), implode(' ', $e->getErrorSummary(true)));
        return $e;
    }

    public function testClauses(): void
    {
        $this->assertSame('(ip.src eq 203.0.113.7)', Cloudflare::clause('ip', '203.0.113.7'));
        $this->assertSame('(ip.src eq 2001:db8::1)', Cloudflare::clause('ip', '2001:db8::1'));
        $this->assertSame('(ip.src in {198.51.100.0/24})', Cloudflare::clause('cidr', '198.51.100.0/24'));
        $this->assertSame('(ip.src.asnum eq 64496)', Cloudflare::clause('asn', 'AS64496'));
    }

    public function testRemovingOnlyTouchesTheExactCondition(): void
    {
        $c = '(ip.src eq 192.0.2.5)';
        $this->assertSame('(a) or (b)', Cloudflare::without("(a) or $c or (b)", $c));
        $this->assertSame('(a)', Cloudflare::without("$c or (a)", $c));
        $this->assertSame('(a) or (ip.src eq 192.0.2.55)', Cloudflare::without('(a) or (ip.src eq 192.0.2.55)', $c), 'similar address left alone');
        $this->expectException(RuntimeException::class);
        Cloudflare::without($c, $c);
    }

    public function testBlockAddsAnOrConditionAndUnblockRemovesIt(): void
    {
        $this->userId();
        $ip = $this->block('203.0.113.7');
        $asn = $this->block('as64496');
        $this->assertSame('(ip.src eq 192.0.2.1) or (ip.src eq 203.0.113.7) or (ip.src.asnum eq 64496)', $this->expression());
        $this->assertNotNull($ip->cloudflare_added_at);
        $this->assertStringContainsString('Added to the Cloudflare rule “Block Bad Actors”', $ip->cloudflareNotice);

        // Full rule definition sent back, minus read-only fields; bearer token used
        $patch = array_values(array_filter($this->sent, static fn($r) => $r['method'] === 'PATCH'))[0];
        $this->assertEqualsCanonicalizing(['action', 'description', 'enabled', 'expression', 'ref'], array_keys(json_decode($patch['body'], true)));
        $this->assertContains('Authorization: Bearer test-token', $patch['headers']);
        $this->assertSame('(cf.client.bot)', $this->rules[0]['expression'], 'other rules untouched');

        // Widening the address replaces its condition in one update
        $ip = BlocklistEntry::findOne($ip->id);
        $this->assertTrue($ip->cloudflare);
        $ip->value = '203.0.113.0/24';
        $this->assertTrue($ip->validate() && $ip->syncCloudflare() && $ip->save(false));
        $this->assertSame('(ip.src eq 192.0.2.1) or (ip.src.asnum eq 64496) or (ip.src in {203.0.113.0/24})', $this->expression());

        // Unticking removes it; nothing is sent when nothing changes
        $asn = BlocklistEntry::findOne($asn->id);
        $asn->cloudflare = false;
        $this->assertTrue($asn->validate() && $asn->syncCloudflare() && $asn->save(false));
        $this->assertNull($asn->cloudflare_added_at);
        $this->assertSame('(ip.src eq 192.0.2.1) or (ip.src in {203.0.113.0/24})', $this->expression());
        $count = count($this->sent);
        $asn->reason = 'still not in Cloudflare';
        $this->assertTrue($asn->syncCloudflare());
        $this->assertCount($count, $this->sent);
    }

    public function testAlreadyPresentConditionIsNotDuplicated(): void
    {
        $this->userId();
        $this->block('192.0.2.1');
        $this->assertSame('(ip.src eq 192.0.2.1)', $this->expression());
        $this->assertCount(0, array_filter($this->sent, static fn($r) => $r['method'] === 'PATCH'));
    }

    public function testUntickedOrOutboundEntriesStayOutOfCloudflare(): void
    {
        $this->userId();
        $this->block('203.0.113.8', ['cloudflare' => false]);
        $this->assertSame([], $this->sent);

        $out = new BlocklistEntry(['value' => '203.0.113.9', 'severity' => 'high', 'direction' => 'outbound', 'reason' => 'test', 'cloudflare' => '1']);
        $this->assertFalse($out->validate());
        $this->assertStringContainsString('inbound', $out->getFirstError('cloudflare'));
    }

    public function testFailuresAreReportedOnTheCheckbox(): void
    {
        $this->userId();
        putenv('CLOUDFLARE_RULE_NAME=Missing rule');
        $e = new BlocklistEntry(['value' => '203.0.113.10', 'severity' => 'high', 'direction' => 'both', 'reason' => 'test', 'cloudflare' => true]);
        $this->assertTrue($e->validate());
        $this->assertFalse($e->syncCloudflare());
        $this->assertStringContainsString('No custom rule named "Missing rule"', $e->getFirstError('cloudflare'));
        putenv('CLOUDFLARE_RULE_NAME');

        $e->clearErrors();
        $this->failWith = ['status' => 403, 'body' => json_encode(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]])];
        $this->assertFalse($e->syncCloudflare());
        $this->assertSame('Cloudflare: Authentication error (10000)', $e->getFirstError('cloudflare'));
        $this->assertNull($e->cloudflare_added_at);

        putenv('CLOUDFLARE_ZONE_ID');
        $this->failWith = null;
        $e->clearErrors();
        $this->assertFalse($e->syncCloudflare());
        $this->assertStringContainsString('not configured', $e->getFirstError('cloudflare'));
    }
}
