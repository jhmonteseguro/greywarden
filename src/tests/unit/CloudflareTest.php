<?php

namespace tests\unit;

use app\modules\intel\components\Cloudflare;
use app\modules\intel\models\BlocklistEntry;
use RuntimeException;
use tests\DbTestCase;
use tests\support\FakeCloudflare;
use tests\support\OutputCapture;
use yii\console\ExitCode;

/**
 * Pushing blocklist entries to Cloudflare: addresses and networks into the
 * "greywarden_blocklist" IP list, ASNs into the "Greywarden Blocklist" rule
 * (created, disabled, when missing), against a fake API (no test reaches Cloudflare).
 */
class CloudflareTest extends DbTestCase
{
    private FakeCloudflare $cf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cf = (new FakeCloudflare())->install();
        $this->userId();
    }

    protected function tearDown(): void
    {
        FakeCloudflare::uninstall();
        parent::tearDown();
    }

    private function block(string $value, array $attrs = []): BlocklistEntry
    {
        $e = new BlocklistEntry(array_merge(['value' => $value, 'severity' => 'high', 'direction' => 'both', 'reason' => 'test', 'cloudflare' => true], $attrs));
        $this->assertTrue($e->validate() && $e->syncCloudflare() && $e->save(false), implode(' ', $e->getErrorSummary(true)));
        return $e;
    }

    private function expression(): string
    {
        return $this->cf->rule()['expression'];
    }

    public function testClausesExpressionAndNames(): void
    {
        $this->assertSame('(ip.src eq 203.0.113.7)', Cloudflare::clause('ip', '203.0.113.7'));
        $this->assertSame('(ip.src in {198.51.100.0/24})', Cloudflare::clause('cidr', '198.51.100.0/24'));
        $this->assertSame('(ip.src.asnum eq 64496)', Cloudflare::clause('asn', 'AS64496'));
        $this->assertSame('(ip.src in $greywarden_blocklist)', Cloudflare::expression([]));
        $this->assertSame('(ip.src in $greywarden_blocklist) or (ip.src.asnum eq 123) or (ip.src.asnum eq 345)', Cloudflare::expression([345, 123, 345]));
        $this->assertSame('Greywarden Blocklist', Cloudflare::ruleName());
        putenv('CLOUDFLARE_LIST_NAME=our_blocks');
        $this->assertSame('our_blocks', Cloudflare::listName());
        putenv('CLOUDFLARE_LIST_NAME=Bad-Name');
        $this->assertSame('greywarden_blocklist', Cloudflare::listName(), 'invalid names fall back');
        $this->assertSame('2001:db8::1', Cloudflare::itemKey('2001:0DB8:0:0::1'));
        $this->assertSame('198.51.100.0/24', Cloudflare::itemKey('198.51.100.7/24'));
        $this->assertSame('192.0.2.5', Cloudflare::itemKey('192.0.2.5/32'));
        $this->assertSame('junk', Cloudflare::itemKey('JUNK'));
        $this->assertSame('x/y', Cloudflare::itemKey('X/Y'));
    }

    public function testWithoutOnlyTouchesTheExactCondition(): void
    {
        $c = '(ip.src eq 192.0.2.5)';
        $this->assertSame('(a) or (b)', Cloudflare::without("(a) or $c or (b)", $c));
        $this->assertSame('(a)', Cloudflare::without("$c or (a)", $c));
        $this->assertSame('(a) or (ip.src eq 192.0.2.55)', Cloudflare::without('(a) or (ip.src eq 192.0.2.55)', $c), 'similar address left alone');
        try {
            Cloudflare::without("not ($c)", $c);
            $this->fail('edited by hand');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('edited by hand', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        Cloudflare::without($c, $c);
    }

    public function testAddressesGoToTheListAndAsnsToTheRule(): void
    {
        $ip = $this->block('203.0.113.7');
        $this->assertSame(['203.0.113.7'], $this->cf->items());
        $this->assertSame('(ip.src in $greywarden_blocklist)', $this->expression(), 'the placeholder is replaced');
        $this->assertSame('list', substr($this->cf->lists['greywarden_blocklist']['id'], 0, 4), 'the list is created on first use');
        $this->assertSame('ip', $this->cf->lists['greywarden_blocklist']['kind']);
        $this->assertNotNull($ip->cloudflare_added_at);
        $this->assertSame('Added to the Cloudflare list “greywarden_blocklist” (rule “Greywarden Blocklist”).', $ip->cloudflareNotice);
        $this->assertStringStartsWith('Greywarden: test', array_values($this->cf->lists['greywarden_blocklist']['items'])[0]['comment']);
        $this->assertSame('/zones/zone1', substr($this->cf->requests('GET', '/zones/zone1')[1]['url'], strlen(Cloudflare::API)), 'account read from the zone');

        $asn = $this->block('as64496');
        $this->block('AS123');
        $this->assertSame('(ip.src in $greywarden_blocklist) or (ip.src.asnum eq 123) or (ip.src.asnum eq 64496)', $this->expression());
        $this->assertSame('Added to the Cloudflare rule “Greywarden Blocklist”.', $asn->cloudflareNotice);
        $this->assertSame(['203.0.113.7'], $this->cf->items(), 'ASNs never go to the list');

        // Full rule definition sent back, minus read-only fields, with the Block action; bearer token used
        $patch = $this->cf->requests('PATCH', '/rules/r-gw')[0];
        $this->assertEqualsCanonicalizing(['action', 'description', 'enabled', 'expression', 'ref'], array_keys(json_decode($patch['body'], true)));
        $this->assertSame('block', json_decode($patch['body'], true)['action']);
        $this->assertContains('Authorization: Bearer test-token', $patch['headers']);
        $this->assertSame('(ip.src eq 192.0.2.250)', $this->cf->rule('Allow office')['expression'], 'other rules untouched');

        // Widening the address swaps the list item
        $ip = BlocklistEntry::findOne($ip->id);
        $this->assertTrue($ip->cloudflare);
        $ip->value = '203.0.113.0/24';
        $this->assertTrue($ip->validate() && $ip->syncCloudflare() && $ip->save(false));
        $this->assertSame(['203.0.113.0/24'], $this->cf->items());
        $this->assertStringStartsWith('Updated in the Cloudflare list', $ip->cloudflareNotice);

        // Changing an address into an ASN moves it from the list to the rule
        $ip->value = 'AS64511';
        $this->assertTrue($ip->validate() && $ip->syncCloudflare() && $ip->save(false));
        $this->assertSame([], $this->cf->items());
        $this->assertStringContainsString('(ip.src.asnum eq 64511)', $this->expression());

        // Unticking removes it; nothing is sent when nothing changes
        $asn = BlocklistEntry::findOne($asn->id);
        $asn->cloudflare = false;
        $this->assertTrue($asn->validate() && $asn->syncCloudflare() && $asn->save(false));
        $this->assertNull($asn->cloudflare_added_at);
        $this->assertSame('Removed from the Cloudflare rule “Greywarden Blocklist”.', $asn->cloudflareNotice);
        $this->assertSame('(ip.src in $greywarden_blocklist) or (ip.src.asnum eq 123) or (ip.src.asnum eq 64511)', $this->expression());
        $count = count($this->cf->sent);
        $asn->reason = 'still not in Cloudflare';
        $this->assertTrue($asn->syncCloudflare());
        $this->assertCount($count, $this->cf->sent);
    }

    public function testUnblockAndExpiry(): void
    {
        $ip = $this->block('192.0.2.30');
        $asn = $this->block('AS64500', ['expires_at' => date('Y-m-d H:i:s', time() + 60)]);
        $old = $this->block('192.0.2.31', ['expires_at' => date('Y-m-d H:i:s', time() + 60)]);
        $this->assertSame(['192.0.2.30', '192.0.2.31'], $this->cf->items());

        $ip->cloudflare = false;
        $this->assertTrue($ip->syncCloudflare() && $ip->archive());
        $this->assertSame(['192.0.2.31'], $this->cf->items());
        $this->assertSame('Removed from the Cloudflare list “greywarden_blocklist” (rule “Greywarden Blocklist”).', $ip->cloudflareNotice);

        BlocklistEntry::updateAll(['expires_at' => date('Y-m-d H:i:s', time() - 60)], ['id' => [$asn->id, $old->id]]);
        $this->assertSame([], BlocklistEntry::cloudflareAsns(), 'expired ASNs are not in force');
        $result = BlocklistEntry::removeExpiredFromCloudflare();
        $this->assertSame([$asn->reference, $old->reference], $result['removed']);
        $this->assertSame([], $this->cf->items());
        $this->assertSame('(ip.src in $greywarden_blocklist)', $this->expression());
        $this->assertNull(BlocklistEntry::findOne($old->id)->cloudflare_added_at);
        $this->assertSame(['removed' => [], 'failed' => []], BlocklistEntry::removeExpiredFromCloudflare(), 'nothing left');
    }

    public function testMissingRuleIsCreatedDisabled(): void
    {
        $this->cf = (new FakeCloudflare(null, false))->install();
        $e = $this->block('203.0.113.20');
        $rule = $this->cf->rule();
        $this->assertNotNull($rule);
        $this->assertFalse($rule['enabled']);
        $this->assertSame('block', $rule['action']);
        $this->assertSame('(ip.src in $greywarden_blocklist)', $rule['expression']);
        $this->assertSame(['Allow office', 'Greywarden Blocklist'], array_column($this->cf->rules, 'description'), 'added at the end');
        $this->assertStringContainsString('Greywarden created the custom rule "Greywarden Blocklist" in Cloudflare, disabled', $e->cloudflareNotice);
        $this->assertStringContainsString('move it to the top', $e->cloudflareNotice);

        // Its enabled state is the user's from then on
        $asn = $this->block('AS64501');
        $this->assertFalse($this->cf->rule()['enabled']);
        $this->assertStringContainsString('The rule is disabled in Cloudflare, so it is not blocking yet.', $asn->cloudflareNotice);

        // A zone without any custom rules gets the ruleset too
        $this->cf = (new FakeCloudflare([]))->install();
        $this->cf->rules = null;
        $this->block('203.0.113.21');
        $this->assertSame(['Greywarden Blocklist'], array_column($this->cf->rules, 'description'));
        $this->assertCount(1, $this->cf->requests('PUT', '/entrypoint'));
    }

    public function testListDetails(): void
    {
        // Existing items are not added twice, and items are read page by page
        $this->cf->lists['greywarden_blocklist'] = ['id' => 'L1', 'name' => 'greywarden_blocklist', 'kind' => 'ip', 'items' => [
            'a' => ['id' => 'a', 'ip' => '2001:db8::5'], 'b' => ['id' => 'b', 'ip' => '192.0.2.40'], 'c' => ['id' => 'c', 'ip' => '192.0.2.41'],
        ]];
        $this->cf->pageSize = 2;
        $this->block('2001:DB8:0::5');
        $this->assertCount(0, $this->cf->requests('POST', '/items'));
        $this->assertCount(2, $this->cf->requests('GET', '/items'));

        // Account from .env; a custom list name
        putenv('CLOUDFLARE_ACCOUNT_ID=' . FakeCloudflare::ACCOUNT);
        putenv('CLOUDFLARE_LIST_NAME=other_list');
        $this->cf->sent = [];
        $this->block('192.0.2.42');
        $this->assertSame([], array_filter($this->cf->sent, static fn($r) => str_ends_with($r['url'], '/zones/zone1')), 'not read from the zone');
        $this->assertSame(['192.0.2.42'], $this->cf->items('other_list'));
        $this->assertStringContainsString('$other_list', $this->expression());

        // A list of the wrong kind
        $this->cf->lists['other_list']['kind'] = 'hostname';
        $e = new BlocklistEntry(['value' => '192.0.2.43', 'severity' => 'high', 'direction' => 'both', 'reason' => 't', 'cloudflare' => true]);
        $this->assertTrue($e->validate());
        $this->assertFalse($e->syncCloudflare());
        $this->assertStringContainsString('is not an IP list', $e->getFirstError('cloudflare'));
    }

    public function testFailuresAreReportedOnTheCheckbox(): void
    {
        $e = new BlocklistEntry(['value' => '203.0.113.10', 'severity' => 'high', 'direction' => 'both', 'reason' => 'test', 'cloudflare' => true]);
        $this->assertTrue($e->validate());
        $fail = function (?string $field, mixed $value, string $expected) use ($e): void {
            $e->clearErrors();
            if ($field !== null) {
                $this->cf->$field = $value;
            }
            $this->assertFalse($e->syncCloudflare(), $expected);
            $this->assertStringContainsString($expected, $e->getFirstError('cloudflare'));
            $this->assertNull($e->cloudflare_added_at);
            if ($field !== null) {
                $this->cf->$field = $field === 'opStatus' ? 'completed' : null;
            }
        };

        $fail('failWith', ['status' => 403, 'body' => json_encode(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]])], 'Cloudflare: Authentication error (10000)');
        $fail('failItemsWith', ['status' => 429, 'body' => json_encode(['success' => false, 'errors' => [['code' => 10013, 'message' => 'a bulk operation is already running']]])], 'a bulk operation is already running');
        $fail('opStatus', 'failed', 'the list update failed: invalid item');
        Cloudflare::$pollDelay = 1;
        $fail('opStatus', 'running', 'still applying the list update');
        Cloudflare::$pollDelay = 0;

        // Two rules with the name
        $this->cf->rules[] = ['id' => 'r-dup', 'description' => 'Greywarden Blocklist', 'action' => 'block', 'expression' => '(x)'];
        $fail(null, null, 'There are 2 custom rules named "Greywarden Blocklist"');
        array_pop($this->cf->rules);

        // The account cannot be read from the zone
        $this->cf->failWith = null;
        \Yii::$app->cache->delete(['cf-account', 'zone1']);
        $fake = \app\modules\intel\components\Http::$fake;
        \app\modules\intel\components\Http::$fake = static fn(array $r) => preg_match('#/zones/zone1$#', $r['url']) ? ['status' => 403, 'body' => '{}'] : $fake($r);
        $fail(null, null, 'set CLOUDFLARE_ACCOUNT_ID in .env');
        \app\modules\intel\components\Http::$fake = $fake;

        putenv('CLOUDFLARE_ZONE_ID');
        $fail(null, null, 'not configured');
    }

    public function testWritesWithoutAnOperationIdAreNotPolled(): void
    {
        $fake = \app\modules\intel\components\Http::$fake;
        \app\modules\intel\components\Http::$fake = static function (array $r) use ($fake): array {
            $response = $fake($r);
            return str_ends_with($r['url'], '/items') && $r['method'] === 'POST' ? ['body' => json_encode(['success' => true, 'result' => []])] : $response;
        };
        $this->block('192.0.2.44');
        $this->assertSame([], $this->cf->requests('GET', '/bulk_operations/'));
    }

    public function testUntickedOrOutboundEntriesStayOutOfCloudflare(): void
    {
        $this->block('203.0.113.8', ['cloudflare' => false]);
        $this->assertSame([], $this->cf->sent);

        $out = new BlocklistEntry(['value' => '203.0.113.9', 'severity' => 'high', 'direction' => 'outbound', 'reason' => 'test', 'cloudflare' => '1']);
        $this->assertFalse($out->validate());
        $this->assertStringContainsString('inbound', $out->getFirstError('cloudflare'));
    }

    // ---- intel/cloudflare-migrate ------------------------------------------------------------

    /** Entries as "Add to Cloudflare" left them in the old rule */
    private function legacy(string $value, ?string $expires = null): BlocklistEntry
    {
        $e = $this->block($value, ['cloudflare' => false, 'expires_at' => $expires]);
        BlocklistEntry::updateAll(['cloudflare_added_at' => '2026-10-01 10:00:00'], ['id' => $e->id]);
        return $e;
    }

    public function testMigrateMovesEntriesAndCleansTheOldRule(): void
    {
        $ip = $this->legacy('198.51.100.7');
        $net = $this->legacy('198.51.100.128/25');
        $asn = $this->legacy('AS64502');
        $gone = $this->legacy('198.51.100.9', date('Y-m-d H:i:s', time() + 60));
        BlocklistEntry::updateAll(['expires_at' => date('Y-m-d H:i:s', time() - 60)], ['id' => $gone->id]);
        $this->cf = (new FakeCloudflare(null, false))->install();
        $this->cf->rules[] = ['id' => 'r-old', 'description' => 'Block Bad Actors', 'action' => 'block', 'enabled' => true,
            'expression' => '(http.user_agent contains "BadBot") or (ip.src eq 198.51.100.7) or (ip.src in {198.51.100.128/25}) or (ip.src.asnum eq 64502) or (ip.src eq 198.51.100.9)'];

        // Dry run: checks, changes nothing
        [$code, $out] = OutputCapture::run('intel/cloudflare-migrate', ['dryRun' => 1]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('2 address(es) / network(s) → list "greywarden_blocklist", 1 ASN(s) → rule "Greywarden Blocklist", 1 expired entry dropped; old rule "Block Bad Actors"', $out);
        $this->assertStringContainsString('missing; it will be created, disabled', $out);
        $this->assertStringContainsString('List "greywarden_blocklist": missing', $out);
        $this->assertSame([], $this->cf->requests('POST') + $this->cf->requests('PATCH'));

        [$code, $out] = OutputCapture::run('intel/cloudflare-migrate');
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertSame(['198.51.100.7', '198.51.100.128/25'], $this->cf->items());
        $this->assertSame('(ip.src in $greywarden_blocklist) or (ip.src.asnum eq 64502)', $this->expression());
        $this->assertStringContainsString('Greywarden created the custom rule', $out);
        $this->assertSame('(http.user_agent contains "BadBot")', $this->cf->rule('Block Bad Actors')['expression'], 'hand-written parts are kept');
        $this->assertStringContainsString('4 condition(s) taken out of "Block Bad Actors"', $out);
        $this->assertNull(BlocklistEntry::findOne($gone->id)->cloudflare_added_at);
        $this->assertNotNull(BlocklistEntry::findOne($asn->id)->cloudflare_added_at);

        // Run again: the old rule no longer holds anything of Greywarden's; the dry run now finds both
        [, $out] = OutputCapture::run('intel/cloudflare-migrate', ['dryRun' => 1]);
        $this->assertStringContainsString('Rule "Greywarden Blocklist": found (disabled, action block)', $out);
        $this->assertStringContainsString('List "greywarden_blocklist": found, 2 item(s)', $out);
        [, $out] = OutputCapture::run('intel/cloudflare-migrate');
        $this->assertStringContainsString('0 condition(s) taken out', $out);
    }

    public function testMigrateEdgeCases(): void
    {
        $this->legacy('198.51.100.7');
        $this->cf->rules[] = ['id' => 'r-old', 'description' => 'Old', 'action' => 'block', 'expression' => '(ip.src eq 198.51.100.7)'];
        [$code, $out] = OutputCapture::run('intel/cloudflare-migrate', ['oldRule' => 'Old']);
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('Every condition in "Old" was Greywarden\'s: delete that rule', $out);
        $this->assertSame('(ip.src eq 198.51.100.7)', $this->cf->rule('Old')['expression'], 'left for the user to delete');
        $this->assertStringContainsString('List and rule updated.', $out);

        $this->cf->rules[2]['expression'] = '(a) or not (ip.src eq 198.51.100.7)';
        [, $out] = OutputCapture::run('intel/cloudflare-migrate', ['oldRule' => 'Old']);
        $this->assertStringContainsString('Remove by hand from "Old": (ip.src eq 198.51.100.7)', $out);

        putenv('CLOUDFLARE_RULE_NAME=Nowhere');
        [, $out] = OutputCapture::run('intel/cloudflare-migrate');
        $this->assertStringContainsString('No rule named "Nowhere": nothing to clean up', $out);

        [$code, $out] = OutputCapture::run('intel/cloudflare-migrate', ['oldRule' => 'Greywarden Blocklist']);
        $this->assertSame(ExitCode::UNAVAILABLE, $code);
        $this->assertStringContainsString('is the rule Greywarden now manages', $out);

        foreach ($this->cf->rules as &$r) {
            if ($r['description'] === 'Greywarden Blocklist') {
                $r['enabled'] = false;
            }
        }
        unset($r);
        [, $out] = OutputCapture::run('intel/cloudflare-migrate', ['oldRule' => 'Nowhere']);
        $this->assertStringContainsString('The rule is disabled in Cloudflare: enable it to block.', $out);

        putenv('CLOUDFLARE_ZONE_ID');
        [$code, $out] = OutputCapture::run('intel/cloudflare-migrate', ['dryRun' => 1]);
        $this->assertSame(ExitCode::UNAVAILABLE, $code);
        $this->assertStringContainsString('not configured', $out);
    }
}
