<?php

namespace tests\unit;

use app\models\AuditLog;
use app\modules\intel\components\Assessment;
use app\modules\intel\components\FeedCatalogue;
use app\modules\intel\components\Feeds;
use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\Investigator;
use app\modules\intel\components\IpAddress;
use app\modules\intel\components\Network;
use app\modules\intel\components\Report;
use app\modules\intel\components\SourceResult;
use app\modules\intel\components\sources\DnsblSource;
use app\modules\intel\components\sources\GreyNoiseSource;
use app\modules\intel\controllers\InvestigateController;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\IntelIndicator;
use tests\DbTestCase;
use Yii;

class IntelTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No test may reach the network
        Http::$fake = static fn(array $r) => ['status' => 404, 'body' => '{}'];
        Network::$fakeDns = static fn(string $name, string $type) => [];
        Network::$fakeWhois = static fn(string $server, string $query) => null;
        Yii::$app->cache->flush();
    }

    protected function tearDown(): void
    {
        Http::$fake = null;
        Network::$fakeDns = null;
        Network::$fakeWhois = null;
        parent::tearDown();
    }

    // ---- Addresses ----------------------------------------------------------------

    public function testIpNormalisationAndSpecialRanges(): void
    {
        $this->assertSame('2001:db8::1', IpAddress::normalize(' [2001:0DB8:0:0::1] '));
        $this->assertNull(IpAddress::normalize('10.0.0.999'));
        $this->assertSame('Private network (RFC 1918)', IpAddress::specialPurpose('172.20.1.1'));
        $this->assertSame('Carrier-grade NAT (RFC 6598)', IpAddress::specialPurpose('100.100.1.1'));
        $this->assertSame('Documentation (TEST-NET-3)', IpAddress::specialPurpose('203.0.113.9'));
        $this->assertSame('Unique local address', IpAddress::specialPurpose('fd00::1'));
        $this->assertNull(IpAddress::specialPurpose('8.8.8.8'));
        $this->assertNull(IpAddress::specialPurpose('2606:4700::1111'));
        $this->assertTrue(IpAddress::isPublic('1.1.1.1'));
    }

    public function testCidrMathsAndHexOrdering(): void
    {
        $r = IpAddress::parseCidr('198.51.100.77/24');
        $this->assertSame('198.51.100.0/24', $r['cidr']);
        $this->assertSame(IpAddress::toHex('198.51.100.0'), $r['start']);
        $this->assertSame(IpAddress::toHex('198.51.100.255'), $r['end']);
        $this->assertSame('2001:db8::/32', IpAddress::parseCidr('2001:db8:1::/32')['cidr']);
        $this->assertNull(IpAddress::parseCidr('10.0.0.0/33'));
        $this->assertTrue(IpAddress::inCidr('10.1.2.3', '10.0.0.0/8'));
        $this->assertFalse(IpAddress::inCidr('11.0.0.1', '10.0.0.0/8'));
        // String order of the hex form is numeric order
        $this->assertLessThan(0, strcmp(IpAddress::toHex('9.255.255.255'), IpAddress::toHex('10.0.0.0')));
        $this->assertSame('10.0.0.1', IpAddress::fromHex(IpAddress::toHex('10.0.0.1')));
        $this->assertSame('4.3.2.1', IpAddress::reversed('1.2.3.4'));
        $this->assertSame('1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', IpAddress::ptrName('2001:db8::1'));
    }

    public function testMergeRangesCombinesOverlapsOnly(): void
    {
        $h = static fn($ip) => IpAddress::toHex($ip);
        $merged = IpAddress::mergeRanges([
            [$h('10.0.0.0'), $h('10.0.0.255'), 'a'],
            [$h('10.0.0.128'), $h('10.0.1.10'), 'b'],
            [$h('10.0.1.11'), $h('10.0.1.11'), 'c'], // adjacent: kept separate
        ]);
        $this->assertCount(2, $merged);
        $this->assertSame([$h('10.0.0.0'), $h('10.0.1.10'), 'a; b'], $merged[0]);
    }

    public function testIndicatorParsingAndExtraction(): void
    {
        $this->assertSame('AS64496', Indicator::parse('as 64496')->value);
        $this->assertSame('Private use (RFC 6996)', Indicator::parse('AS64512')->specialPurpose());
        $this->assertNull(Indicator::parse('AS15169')->specialPurpose());
        $this->assertNull(Indicator::parse('not an ip'));
        $this->assertSame('203.0.113.9', InvestigateController::extract('hxxp://203.0.113[.]9:443/login')->value);
        $this->assertSame('2001:db8::7', InvestigateController::extract('GET http://[2001:db8::7]/x')->value);
        $this->assertSame('AS13335', InvestigateController::extract('origin AS13335 (Cloudflare)')->value);
        $this->assertSame('198.51.100.23', InvestigateController::extract('Oct 19 sshd[1]: Failed password from 198.51.100.23 port 22')->value);
    }

    public function testDnsPacketRoundTrip(): void
    {
        $query = Network::dnsQuery(0x1234, '4.3.2.1.zen.spamhaus.org', 1);
        $this->assertSame(0x1234, unpack('n', $query)[1]);
        // Response: header + echoed question + one A answer using a compressed name pointer (0xC00C)
        $answer = "\xC0\x0C" . pack('nnNn', 1, 1, 300, 4) . inet_pton('127.0.0.4');
        $response = pack('nnnnnn', 0x1234, 0x8180, 1, 1, 0, 0) . substr($query, 12) . $answer;
        $this->assertSame(['127.0.0.4'], Network::dnsParse($response, 1));
        // NXDOMAIN (rcode 3)
        $this->assertSame([], Network::dnsParse(pack('nnnnnn', 0x1234, 0x8183, 1, 0, 0, 0) . substr($query, 12), 1));
    }

    // ---- Feeds ------------------------------------------------------------------------

    public function testFeedParsersAndMatching(): void
    {
        $plain = Feeds::parse(FeedCatalogue::get('blocklist-de'), ["# comment\n198.51.100.23\n198.51.100.0/30 ; note\nbogus\n"]);
        $this->assertCount(2, $plain);
        $ipsum = Feeds::parse(FeedCatalogue::get('ipsum'), ["# IPsum\n198.51.100.23\t7\n"]);
        $this->assertSame('7', $ipsum[0][2]);
        $drop = Feeds::parse(FeedCatalogue::get('spamhaus-drop'), ['{"cidr":"192.0.2.0/24","sblid":"SBL1"}' . "\n" . '{"type":"metadata","timestamp":1}']);
        $this->assertSame([IpAddress::toHex('192.0.2.0'), IpAddress::toHex('192.0.2.255'), 'SBL1'], $drop[0]);
        $asn = Feeds::parse(FeedCatalogue::get('spamhaus-asndrop'), ['{"asn":64500,"asname":"BAD-AS","cc":"ZZ","domain":"bad.example"}']);
        $this->assertSame(['asn' => 64500, 'meta' => 'BAD-AS · ZZ · bad.example'], $asn[0]);
        // FireHOL level 1 lists bogons; they are dropped
        $this->assertCount(1, Feeds::parse(FeedCatalogue::get('firehol-l1'), ["10.0.0.0/8\n1.2.3.0/24\n"]));

        Feeds::store('blocklist-de', $plain);
        Feeds::store('ipsum', $ipsum);
        Feeds::store('spamhaus-drop', $drop);
        Feeds::store('spamhaus-asndrop', $asn);

        $hits = Feeds::matchIp('198.51.100.23');
        $this->assertArrayHasKey('blocklist-de', $hits);
        $this->assertSame('7', $hits['ipsum']['meta']);
        $this->assertArrayHasKey('spamhaus-drop', Feeds::matchIp('192.0.2.200'));
        $this->assertSame([], Feeds::matchIp('198.51.100.99'));
        $this->assertSame(['spamhaus-asndrop' => 'BAD-AS · ZZ · bad.example'], Feeds::matchAsn(64500));
        $r = IpAddress::parseCidr('198.51.100.0/24');
        $this->assertSame(['blocklist-de' => 2], Feeds::countInRanges([[$r['start'], $r['end']]], ['blocklist-de']));
    }

    // ---- Scoring ------------------------------------------------------------------------

    public function testScoreCombinesSignalsLikeProbabilities(): void
    {
        $this->assertSame(0, Assessment::score([]));
        $this->assertSame(50, Assessment::score([50]));
        $this->assertSame(75, Assessment::score([50, 50]));
        $this->assertSame(90, Assessment::score([90, 0]));
        // Strongest benign signal (-50) scales the result: 75 x (1 - 0.4)
        $this->assertSame(45, Assessment::score([50, 50, -50, -10]));
    }

    private function report(string $ip, array $results, array $facts = []): Report
    {
        return new Report(Indicator::ip($ip), $results, $facts, 0.1);
    }

    public function testAssessmentRatingsGroupsAndHeadline(): void
    {
        $feeds = SourceResult::hit('Listed');
        $feeds->signal(35, 'medium', 'Tor exit node', null, 'tor')->signal(90, 'critical', 'abuse.ch Feodo Tracker: active botnet C2');
        $shodan = SourceResult::info('ports');
        $shodan->signal(30, 'medium', 'Shodan: Tor node', null, 'tor');
        $a = $this->report('8.8.4.4', ['feeds' => $feeds, 'internetdb' => $shodan])->assessment();
        // Tor counted once (35), not 35 + 30
        $this->assertSame(Assessment::score([90, 35]), $a->score);
        $this->assertSame('malicious', $a->rating);
        $this->assertFalse(array_values(array_filter($a->signals, static fn($s) => $s['label'] === 'Shodan: Tor node'))[0]['counted']);
        $this->assertStringStartsWith('abuse.ch Feodo Tracker: active botnet C2', $a->headline);

        $clean = $this->report('8.8.4.4', ['feeds' => SourceResult::clean(), 'greynoise' => SourceResult::clean(), 'dnsbl' => SourceResult::clean()])->assessment();
        $this->assertSame('clean', $clean->rating);
        $this->assertSame(100, $clean->confidence);

        $unknown = $this->report('8.8.4.4', ['feeds' => SourceResult::error('x'), 'greynoise' => SourceResult::error('x')])->assessment();
        $this->assertSame('unknown', $unknown->rating);

        $special = $this->report('10.0.0.1', ['feeds' => SourceResult::clean()])->assessment();
        $this->assertSame('special', $special->rating);
        $this->assertSame(0, $special->confidence);
    }

    public function testNetworkTypeHeuristics(): void
    {
        $this->assertSame('hosting', Assessment::networkType(['as_name' => 'DIGITALOCEAN-ASN']));
        $this->assertSame('cloud', Assessment::networkType(['cloud_provider' => 'Amazon Web Services']));
        $this->assertSame('isp', Assessment::networkType(['as_network_type' => 'isp', 'as_name' => 'SK Broadband']));
        $this->assertSame('mobile', Assessment::networkType(['network_type' => 'mobile', 'is_hosting' => true]));
    }

    // ---- Sources (parsers with canned answers) -------------------------------------------

    public function testGreyNoiseAndDnsblParsing(): void
    {
        $gn = (new GreyNoiseSource())->collect(Indicator::ip('8.8.4.4'), ['community' => ['status' => 200, 'body' => json_encode(
            ['ip' => '8.8.4.4', 'noise' => true, 'riot' => false, 'classification' => 'malicious', 'name' => 'unknown', 'last_seen' => '2026-10-19'],
        )]], []);
        $this->assertSame(SourceResult::HIT, $gn->status);
        $this->assertSame(60, $gn->signals[0]['weight']);

        // Spamhaus answers XBL + PBL; the other lists are clean, except one refusing public resolvers
        Network::$fakeDns = static fn(string $name) => match (true) {
            str_ends_with($name, 'zen.spamhaus.org') => ['127.0.0.4', '127.0.0.11'],
            str_ends_with($name, 'bl.spamcop.net') => ['127.255.255.254'],
            default => [],
        };
        $r = (new DnsblSource())->collect(Indicator::ip('8.8.4.4'), [], []);
        $this->assertSame(SourceResult::HIT, $r->status);
        $this->assertSame('Spamhaus ZEN: XBL – exploited host (botnet, malware)', $r->signals[0]['label']);
        $this->assertSame(60, $r->signals[0]['weight']);
        $this->assertSame(1, $r->facts['dnsbl_listed']);
        $this->assertSame(count(DnsblSource::ZONES) - 1, $r->facts['dnsbl_total']);
    }

    public function testInvestigatorRunsOfflineAndSkipsExternalForSpecialRanges(): void
    {
        $calls = 0;
        Http::$fake = static function (array $r) use (&$calls) {
            $calls++;
            return ['status' => 404, 'body' => '{}'];
        };
        $report = Investigator::run(Indicator::ip('10.20.30.40'));
        $this->assertSame(0, $calls, 'Private addresses must never be sent to external services');
        $this->assertSame(SourceResult::SKIPPED, $report->result('rdap')->status);
        $this->assertSame('special', $report->assessment()->rating);

        $report = Investigator::run(Indicator::ip('8.8.4.4'));
        $this->assertGreaterThan(0, $calls);
        $this->assertSame(SourceResult::UNCONFIGURED, $report->result('abuseipdb')->status);
        // Second run is served from the cache
        $before = $calls;
        $again = Investigator::run(Indicator::ip('8.8.4.4'));
        $this->assertSame($before, $calls);
        $this->assertTrue($again->result('rdap')->cached);
    }

    // ---- Blocklist & indicators -------------------------------------------------------

    private function block(string $value, array $attrs = []): BlocklistEntry
    {
        $e = new BlocklistEntry(array_merge(['value' => $value, 'severity' => 'high', 'direction' => 'both', 'reason' => 'test'], $attrs));
        $e->save();
        return $e;
    }

    public function testBlocklistNormalisationAndValidation(): void
    {
        $this->userId();
        $cidr = $this->block('198.51.100.77/24');
        $this->assertSame([], $cidr->getErrors());
        $this->assertSame('cidr', $cidr->indicator_type);
        $this->assertSame('198.51.100.0/24', $cidr->value);
        $this->assertStringStartsWith('BLK-', $cidr->reference);

        $single = $this->block('192.0.2.7/32');
        $this->assertSame(['ip', '192.0.2.7'], [$single->indicator_type, $single->value]);
        $asn = $this->block('as64500');
        $this->assertSame(['asn', 'AS64500', 64500], [$asn->indicator_type, $asn->value, (int) $asn->asn]);

        $this->assertArrayHasKey('value', $this->block('198.51.100.0/24')->getErrors(), 'duplicate active block');
        $this->assertArrayHasKey('value', $this->block('10.0.0.0/7')->getErrors(), 'too broad');
        $this->assertArrayHasKey('value', $this->block('not-an-ip')->getErrors());
        $this->assertArrayHasKey('expires_at', $this->block('192.0.2.8', ['expires_at' => '2000-01-01 00:00:00'])->getErrors());

        // Unblocked entries no longer count as duplicates
        $cidr->archive();
        $this->assertSame([], $this->block('198.51.100.0/24')->getErrors());

        $audit = AuditLog::find()->where(['entity_type' => 'blocklist', 'entity_id' => $asn->id, 'action' => 'create'])->one();
        $this->assertArrayNotHasKey('range_start', $audit->getChangesArray());
        $this->assertArrayHasKey('asn', $audit->getChangesArray());
    }

    public function testBlocklistMatchingCoversNetworksAndOriginAs(): void
    {
        $this->userId();
        $net = $this->block('198.51.100.0/24');
        $asn = $this->block('AS64500');
        $this->block('203.0.113.9');
        $ids = static fn(array $list) => array_map(static fn($e) => $e->id, $list);
        $this->assertSame([$net->id], $ids(BlocklistEntry::findForIp('198.51.100.200')));
        $this->assertEqualsCanonicalizing([$net->id, $asn->id], $ids(BlocklistEntry::findForIp('198.51.100.200', 64500)));
        $this->assertSame([], BlocklistEntry::findForIp('198.51.101.1'));
        $this->assertSame([$asn->id], $ids(BlocklistEntry::findForAsn(64500)));

        $expiring = $this->block('192.0.2.50', ['expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->assertTrue($expiring->getIsInForce());
        BlocklistEntry::updateAll(['expires_at' => '2000-01-01 00:00:00'], ['id' => $expiring->id]);
        $expiring->refresh();
        $this->assertSame('expired', $expiring->getState());
    }

    public function testVerdictIsAudited(): void
    {
        $this->userId();
        $model = IntelIndicator::findOrCreate(Indicator::ip('192.0.2.1'));
        $model->save(false);
        $this->assertTrue($model->setVerdict('malicious'));
        $this->assertFalse($model->setVerdict('evil'));
        $log = AuditLog::find()->where(['entity_type' => 'indicator', 'entity_id' => $model->id])->one();
        $this->assertSame(['verdict' => [null, 'malicious']], $log->getChangesArray());
        $this->assertSame('Malicious', $log->getFormattedChanges()[0]['new']);
        $this->assertTrue($model->setVerdict(''));
        $this->assertNull($model->verdict);
    }
}
