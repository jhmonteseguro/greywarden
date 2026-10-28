<?php

namespace tests\unit;

use app\modules\intel\components\FeedCatalogue;
use app\modules\intel\components\Feeds;
use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\IntelHtml;
use app\modules\intel\components\IpAddress;
use app\modules\intel\components\Network;
use app\modules\intel\components\Pivots;
use app\modules\intel\components\SourceRegistry;
use app\modules\intel\components\SourceResult;
use app\modules\intel\components\sources\AbuseChSource;
use app\modules\intel\components\sources\AbuseIpDbSource;
use app\modules\intel\components\sources\CymruSource;
use app\modules\intel\components\sources\FeedSource;
use app\modules\intel\components\sources\GreyNoiseSource;
use app\modules\intel\components\sources\InternetDbSource;
use app\modules\intel\components\sources\IpApiSource;
use app\modules\intel\components\sources\IpInfoSource;
use app\modules\intel\components\sources\OtxSource;
use app\modules\intel\components\sources\PeeringDbSource;
use app\modules\intel\components\sources\RdapSource;
use app\modules\intel\components\sources\ReverseDnsSource;
use app\modules\intel\components\sources\RipeStatSource;
use app\modules\intel\components\sources\Source;
use app\modules\intel\components\sources\VirusTotalSource;
use app\modules\intel\components\sources\WhoisSource;
use tests\DbTestCase;
use Yii;

/**
 * Each intelligence source turns a provider's answer into rows, facts and
 * scored signals. These tests feed canned API responses (shaped like the real
 * ones) straight into collect(), and fake DNS / WHOIS, so nothing leaves the
 * process. Indicators are documentation addresses and ASNs, except where local
 * feed matching needs a public address (still DB-only, no lookup).
 */
class IntelSourcesTest extends DbTestCase
{
    private Indicator $ip;
    private array $dns = [];
    private array $whois = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ip = Indicator::ip('203.0.113.7');
        Http::$fake = static fn(array $r) => ['status' => 404, 'body' => ''];
        Network::$fakeDns = fn(string $name, string $type) => $this->dns["$type $name"] ?? [];
        Network::$fakeWhois = fn(string $server, string $query) => $this->whois["$server $query"] ?? null;
        Yii::$app->cache->flush();
    }

    protected function tearDown(): void
    {
        Http::$fake = null;
        Network::$fakeDns = null;
        Network::$fakeWhois = null;
        SourceRegistry::set(null);
        putenv('INTEL_DISABLE');
        putenv('INTEL_ENABLE_IPAPI');
        putenv('VIRUSTOTAL_API_KEY');
        parent::tearDown();
    }

    private static function json(mixed $data, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode($data), 'error' => null, 'time' => 0.1];
    }

    private static function labels(SourceResult $r): array
    {
        return array_column($r->signals, 'label');
    }

    // ---- Reputation ---------------------------------------------------------------

    public function testAbuseIpDb(): void
    {
        $s = new AbuseIpDbSource();
        $this->assertTrue($s->supports($this->ip));
        $this->assertFalse($s->supports(Indicator::asn(64500)));
        $this->assertStringContainsString('203.0.113.7', $s->reportUrl($this->ip));
        $this->assertStringContainsString('ipAddress=203.0.113.7', $s->requests($this->ip, [])['check']['url']);
        $this->assertFalse($s->isConfigured(), 'needs ABUSEIPDB_API_KEY');

        $r = $s->collect($this->ip, ['check' => self::json(['data' => ['abuseConfidenceScore' => 90, 'totalReports' => 40, 'numDistinctUsers' => 12,
            'usageType' => 'Data Center/Web Hosting/Transit', 'isTor' => true, 'countryCode' => 'NL', 'lastReportedAt' => '2026-10-01T10:00:00+00:00']])], []);
        $this->assertSame(SourceResult::HIT, $r->status);
        $this->assertSame('hosting', $r->facts['network_type']);
        $this->assertTrue($r->facts['is_tor']);
        $this->assertSame('critical', $r->signals[0]['tone']);

        $this->assertSame(-40, $s->collect($this->ip, ['check' => self::json(['data' => ['abuseConfidenceScore' => 0, 'totalReports' => 0, 'isWhitelisted' => true]])], [])->signals[0]['weight']);
        $old = $s->collect($this->ip, ['check' => self::json(['data' => ['abuseConfidenceScore' => 0, 'totalReports' => 2]])], []);
        $this->assertSame(['AbuseIPDB: 2 old reports'], self::labels($old));
        $this->assertSame('medium', $s->collect($this->ip, ['check' => self::json(['data' => ['abuseConfidenceScore' => 20, 'totalReports' => 3]])], [])->signals[0]['tone']);
        $this->assertSame(SourceResult::CLEAN, $s->collect($this->ip, ['check' => self::json(['data' => ['abuseConfidenceScore' => 0, 'totalReports' => 0]])], [])->status);

        $err = $s->collect($this->ip, ['check' => self::json(['errors' => [['detail' => 'Authentication failed']]], 401)], []);
        $this->assertSame([SourceResult::ERROR, 'Authentication failed'], [$err->status, $err->summary]);
        $this->assertStringContainsString('Rate limited', $s->collect($this->ip, ['check' => ['status' => 429, 'body' => '']], [])->summary);
    }

    public function testAbuseCh(): void
    {
        putenv('ABUSECH_AUTH_KEY=k');
        $s = new AbuseChSource();
        $req = $s->requests($this->ip, []);
        $this->assertSame(['threatfox', 'urlhaus'], array_keys($req));
        $this->assertContains('Auth-Key: k', $req['threatfox']['headers']);
        putenv('ABUSECH_AUTH_KEY');
        $this->assertStringContainsString('threatfox', $s->reportUrl($this->ip));

        $r = $s->collect($this->ip, [
            'threatfox' => self::json(['query_status' => 'ok', 'data' => [['ioc' => '203.0.113.7:443', 'threat_type' => 'botnet_cc', 'malware_printable' => 'Cobalt Strike', 'confidence_level' => 100, 'last_seen' => '2026-10-01 00:00']]]),
            'urlhaus' => self::json(['query_status' => 'ok', 'urls' => [['url' => 'http://203.0.113.7/x.exe', 'url_status' => 'online', 'threat' => 'malware_download', 'date_added' => '2026-09-01']]]),
        ], []);
        $this->assertSame(SourceResult::HIT, $r->status);
        $this->assertSame('1 ThreatFox IOC · 1 URLhaus URL', $r->summary);
        $this->assertSame(['Cobalt Strike'], $r->facts['malware_families']);
        $this->assertSame([80, 70], array_column($r->signals, 'weight'));
        $this->assertCount(1, $r->lists['URLhaus URLs']['rows']);

        $past = $s->collect($this->ip, ['threatfox' => self::json(['query_status' => 'no_result']),
            'urlhaus' => self::json(['query_status' => 'ok', 'urls' => [['url' => 'x', 'url_status' => 'offline'], ['url' => 'y', 'url_status' => 'offline']]])], []);
        $this->assertSame(['URLhaus: past malware distribution'], self::labels($past));

        $clean = $s->collect($this->ip, ['threatfox' => self::json(['query_status' => 'no_result']), 'urlhaus' => ['status' => 500, 'body' => '']], []);
        $this->assertSame(SourceResult::CLEAN, $clean->status);
        $this->assertSame('Error: HTTP 500', $clean->rows['URLhaus']);
        $this->assertSame(SourceResult::ERROR, $s->collect($this->ip, [], [])->status);
    }

    public function testVirusTotal(): void
    {
        putenv('VIRUSTOTAL_API_KEY=k');
        $s = new VirusTotalSource();
        $this->assertTrue($s->isConfigured());
        $this->assertSame(['x-apikey: k'], $s->requests($this->ip, [])['ip']['headers']);
        $this->assertSame(12 * 3600, $s->ttl());
        $this->assertStringContainsString('virustotal', $s->reportUrl($this->ip));

        $attrs = ['last_analysis_stats' => ['malicious' => 5, 'suspicious' => 1, 'harmless' => 60, 'undetected' => 20], 'reputation' => -10,
            'as_owner' => 'Example', 'last_analysis_date' => 1790000000,
            'last_analysis_results' => ['EngineA' => ['category' => 'malicious', 'result' => 'malware'], 'EngineB' => ['category' => 'harmless']]];
        $r = $s->collect($this->ip, ['ip' => self::json(['data' => ['attributes' => $attrs]])], []);
        $this->assertSame('6/86 vendors flag it', $r->summary);
        $this->assertSame(75, $r->signals[0]['weight']);
        $this->assertSame([['EngineA', 'malicious', 'malware']], $r->lists['Vendors flagging the address']['rows']);
        $this->assertStringNotContainsString('last_analysis_results', $r->raw);

        $sus = $s->collect($this->ip, ['ip' => self::json(['data' => ['attributes' => ['last_analysis_stats' => ['suspicious' => 2, 'harmless' => 10]]]])], []);
        $this->assertSame(['VirusTotal: 2 vendors flag it suspicious'], self::labels($sus));
        $this->assertSame(SourceResult::CLEAN, $s->collect($this->ip, ['ip' => self::json(['data' => ['attributes' => ['last_analysis_stats' => ['harmless' => 10]]]])], [])->status);
        $this->assertSame('Wrong key', $s->collect($this->ip, ['ip' => self::json(['error' => ['message' => 'Wrong key']], 401)], [])->summary);
    }

    public function testOtx(): void
    {
        $s = new OtxSource();
        $this->assertStringContainsString('/IPv6/', $s->requests(Indicator::ip('2001:db8::1'), [])['general']['url']);
        $this->assertStringContainsString('otx.alienvault.com', $s->reportUrl($this->ip));

        $pulses = array_fill(0, 3, ['name' => 'Campaign', 'created' => '2026-09-01T00:00', 'tags' => ['Phishing', 'phishing', 'c2']]);
        $r = $s->collect($this->ip, ['general' => self::json(['pulse_info' => ['count' => 3, 'pulses' => $pulses,
            'related' => ['alienvault' => ['malware_families' => ['Emotet'], 'adversary' => ['TA505']], 'other' => []]], 'false_positive' => [1]])], []);
        $this->assertSame('3 pulses', $r->summary);
        $this->assertSame(25, $r->signals[0]['weight']);
        $this->assertSame(['phishing', 'c2'], $r->rows['Common tags']);
        $this->assertSame(['Emotet'], $r->facts['malware_families']);

        $tagged = $s->collect($this->ip, ['general' => self::json(['pulse_info' => ['count' => 1, 'pulses' => [['name' => 'x', 'tags' => ['scanner']]]]])], []);
        $this->assertSame('Tags: scanner', $tagged->signals[0]['detail']);

        $listed = $s->collect($this->ip, ['general' => self::json(['pulse_info' => ['count' => 2], 'validation' => [['source' => 'whitelist', 'message' => 'Known good']]])], []);
        $this->assertSame([SourceResult::CLEAN, -40], [$listed->status, $listed->signals[0]['weight']]);
        $this->assertSame(SourceResult::CLEAN, $s->collect($this->ip, ['general' => self::json(['pulse_info' => ['count' => 0]])], [])->status);
        $this->assertSame(SourceResult::ERROR, $s->collect($this->ip, ['general' => ['status' => 0, 'body' => '', 'error' => 'timeout']], [])->status);
    }

    public function testGreyNoiseClassifications(): void
    {
        $s = new GreyNoiseSource();
        $this->assertFalse($s->supports(Indicator::ip('2001:db8::1')), 'community API is IPv4 only');
        $this->assertSame([], $s->requests($this->ip, [])['community']['headers']);
        $this->assertStringContainsString('greynoise', $s->reportUrl($this->ip));

        $collect = fn(array $data, int $status = 200) => $s->collect($this->ip, ['community' => self::json($data, $status)], []);
        $this->assertSame([SourceResult::CLEAN, -50], [($r = $collect(['riot' => true, 'name' => 'Example CDN']))->status, $r->signals[0]['weight']]);
        $this->assertSame(SourceResult::HIT, $collect(['classification' => 'malicious', 'noise' => true, 'name' => 'unknown'])->status);
        $this->assertSame('Benign scanner · Research', ($r = $collect(['classification' => 'benign', 'noise' => true, 'name' => 'Research']))->summary);
        $this->assertSame('Research', $r->facts['scanner']);
        $this->assertSame('Mass scanner (unclassified)', $collect(['classification' => 'unknown', 'noise' => true])->summary);
        $this->assertSame('No notable activity', $collect(['classification' => 'unknown'])->summary);
        $this->assertSame(SourceResult::CLEAN, $collect(['message' => 'IP not observed'], 404)->status);
        $this->assertSame(SourceResult::ERROR, $s->collect($this->ip, ['community' => ['status' => 500, 'body' => '']], [])->status);
    }

    public function testInternetDbExposure(): void
    {
        $s = new InternetDbSource();
        $this->assertStringContainsString('shodan.io/host/203.0.113.7', $s->reportUrl($this->ip));
        $r = $s->collect($this->ip, ['host' => self::json(['ports' => [443, 22, 3389], 'vulns' => ['CVE-2024-1', 'CVE-2025-2'],
            'tags' => ['c2', 'tor', 'vpn', 'proxy', 'cloud', 'self-signed'], 'hostnames' => ['h.example'], 'cpes' => ['cpe:/a:openbsd:openssh']])], []);
        $this->assertSame(SourceResult::HIT, $r->status);
        $this->assertSame([22, 443, 3389], $r->facts['ports']);
        $this->assertSame(['CVE-2025-2', 'CVE-2024-1'], $r->facts['vulns']);
        $this->assertTrue($r->facts['is_tor'] && $r->facts['is_vpn'] && $r->facts['is_proxy'] && $r->facts['is_hosting']);
        $this->assertContains('Exposes 2 known vulnerabilities', self::labels($r));

        $info = $s->collect($this->ip, ['host' => self::json(['ports' => [80], 'tags' => ['scanner']])], []);
        $this->assertSame([SourceResult::INFO, '1 open port · 0 CVEs'], [$info->status, $info->summary]);
        $this->assertSame(SourceResult::CLEAN, $s->collect($this->ip, ['host' => ['status' => 404, 'body' => '{"detail":"No information available"}']], [])->status);
        $this->assertSame(SourceResult::ERROR, $s->collect($this->ip, ['host' => ['status' => 503, 'body' => '']], [])->status);
    }

    // ---- Registry / network -------------------------------------------------------

    public function testIpInfoAndIpApi(): void
    {
        putenv('IPINFO_TOKEN=t0k');
        $info = new IpInfoSource();
        $this->assertStringEndsWith('/json?token=t0k', $info->requests($this->ip, [])['ip']['url']);
        putenv('IPINFO_TOKEN');
        $this->assertStringContainsString('ipinfo.io', $info->reportUrl($this->ip));
        $r = $info->collect($this->ip, ['ip' => self::json(['city' => 'Amsterdam', 'region' => 'North Holland', 'country' => 'NL', 'loc' => '52.37,4.89',
            'org' => 'AS64500 Example Hosting', 'timezone' => 'Europe/Amsterdam', 'anycast' => true, 'readme' => 'x'])], []);
        $this->assertSame([64500, 'Example Hosting', 52.37, 4.89, true], [$r->facts['asn'], $r->facts['org'], $r->facts['lat'], $r->facts['lon'], $r->facts['anycast']]);
        $this->assertSame('Amsterdam, North Holland, NL · Example Hosting', $r->summary);
        $this->assertSame('Bogon only', $info->collect($this->ip, ['ip' => self::json(['error' => ['title' => 'Bogon only']])], [])->summary);
        $this->assertSame(SourceResult::ERROR, $info->collect($this->ip, ['ip' => ['status' => 403, 'body' => '']], [])->status);

        $api = new IpApiSource();
        $this->assertFalse($api->isEnabled(), 'ip-api is opt-in (HTTP only)');
        putenv('INTEL_ENABLE_IPAPI=1');
        $this->assertTrue($api->isEnabled());
        $this->assertStringStartsWith('http://ip-api.com/json/203.0.113.7', $api->requests($this->ip, [])['ip']['url']);
        $r = $api->collect($this->ip, ['ip' => self::json(['status' => 'success', 'city' => 'Leeds', 'countryCode' => 'GB', 'isp' => 'Example', 'proxy' => true, 'hosting' => true])], []);
        $this->assertSame([SourceResult::HIT, 'hosting', true], [$r->status, $r->facts['network_type'], $r->facts['is_proxy']]);
        $this->assertSame('mobile', $api->collect($this->ip, ['ip' => self::json(['status' => 'success', 'mobile' => true])], [])->facts['network_type']);
        $this->assertSame('reserved range', $api->collect($this->ip, ['ip' => self::json(['status' => 'fail', 'message' => 'reserved range'])], [])->summary);
    }

    public function testPeeringDb(): void
    {
        $s = new PeeringDbSource();
        $asn = Indicator::asn(64500);
        $this->assertSame([2, 1], [$s->phase($this->ip), $s->phase($asn)]);
        $this->assertSame([], $s->requests($this->ip, []), 'an IP needs the origin AS first');
        $this->assertStringEndsWith('asn=64501', $s->requests($this->ip, ['asn' => 64501])['net']['url']);
        $this->assertSame('https://www.peeringdb.com/asn/64500', $s->reportUrl($asn));
        $this->assertNull($s->reportUrl($this->ip));
        $this->assertSame(SourceResult::SKIPPED, $s->collect($this->ip, [], [])->status);

        $r = $s->collect($asn, ['net' => self::json(['data' => [['name' => 'Example Net', 'info_types' => ['Content', 'NSP'], 'info_prefixes4' => 1200,
            'info_prefixes6' => 30, 'website' => 'https://example.net', 'updated' => '2026-01-02T00:00:00Z']]])], []);
        $this->assertSame(['content', 'Example Net'], [$r->facts['as_network_type'], $r->facts['as_name']]);
        $this->assertSame('1,200 IPv4 / 30 IPv6', $r->rows['Max prefixes']);
        $this->assertStringContainsString('no PeeringDB profile', $s->collect($asn, ['net' => self::json(['data' => []])], [])->summary);
        $this->assertSame(SourceResult::ERROR, $s->collect($asn, ['net' => ['status' => 500, 'body' => '']], [])->status);
    }

    public function testRdapForAddressesAndAsns(): void
    {
        $s = new RdapSource();
        $this->assertSame('https://rdap.org/ip/203.0.113.7', $s->requests($this->ip, [])['rdap']['url']);
        $this->assertSame('https://rdap.org/autnum/64500', $s->requests(Indicator::asn(64500), [])['rdap']['url']);

        $vcard = fn(string $fn, array $extra = []) => ['vcard', array_merge([['version', [], 'text', '4.0'], ['fn', [], 'text', $fn]], $extra)];
        $data = [
            'name' => 'EXAMPLE-NET', 'handle' => 'NET-203-0-113-0-1', 'startAddress' => '203.0.113.0', 'endAddress' => '203.0.113.255', 'type' => 'ASSIGNED',
            'parentHandle' => 'NET-203-0-0-0-0', 'country' => 'AU', 'status' => ['active'],
            'cidr0_cidrs' => [['v4prefix' => '203.0.113.0', 'length' => 24]],
            'events' => [['eventAction' => 'registration', 'eventDate' => '2010-05-01T00:00:00Z'], ['eventAction' => 'last changed', 'eventDate' => '2026-01-01T00:00:00Z']],
            'remarks' => [['description' => ['Documentation range']], ['description' => ['Terms of use apply']]],
            'links' => [['rel' => 'self', 'href' => 'https://rdap.apnic.net/ip/203.0.113.0/24']],
            'entities' => [
                ['roles' => ['registrant'], 'handle' => 'ORG-1', 'vcardArray' => $vcard('Example Org', [['adr', ['label' => "1 Street\nTown"], 'text', ''], ['org', [], 'text', ['Example', 'Org']]]),
                    'entities' => [['roles' => ['abuse'], 'handle' => 'AB-1', 'vcardArray' => $vcard('Abuse Desk', [['email', [], 'text', 'ABUSE@example.net'], ['tel', [], 'uri', 'tel:+61-2-0000']])]]],
                ['roles' => ['technical'], 'vcardArray' => $vcard('Tech', [['adr', [], 'text', ['', '', '1 Road', 'City', '', '', 'AU']]])],
            ],
        ];
        $r = $s->collect($this->ip, ['rdap' => self::json($data)], []);
        $this->assertSame('EXAMPLE-NET · Example Org', $r->summary);
        $this->assertSame('203.0.113.0/24', $r->facts['network']);
        $this->assertSame('abuse@example.net', $r->facts['abuse_email']);
        $this->assertSame('+61-2-0000', $r->rows['Abuse phone']);
        $this->assertSame('1 Street, Town', $r->rows['Holder address']);
        $this->assertSame(['Documentation range'], $r->rows['Remarks']);
        $this->assertSame('rdap.apnic.net', $r->rows['Source']);
        $this->assertSame('2010-05-01', $r->facts['registered_at']);
        $this->assertCount(3, $r->lists['Contacts']['rows']);
        $this->assertSame('AU', RdapSource::vcard($data['entities'][1]['vcardArray'])['country']);

        $as = $s->collect(Indicator::asn(64500), ['rdap' => self::json(['name' => 'EXAMPLE-AS', 'handle' => 'AS64500', 'startAutnum' => 64500, 'endAutnum' => 64511, 'port43' => 'whois.example.net'])], []);
        $this->assertSame(['EXAMPLE-AS', 'AS64500 – AS64511', 'whois.example.net'], [$as->facts['as_name'], $as->rows['Range'], $as->rows['Source']]);
        $this->assertSame('No registration record', $s->collect($this->ip, ['rdap' => ['status' => 404, 'body' => '']], [])->summary);
        $this->assertSame(SourceResult::ERROR, $s->collect($this->ip, ['rdap' => ['status' => 500, 'body' => '{"x":1}']], [])->status);
    }

    public function testRipeStatForAddressesAndAsns(): void
    {
        $s = new RipeStatSource();
        $this->assertSame(['prefix', 'geo', 'abuse'], array_keys($s->requests($this->ip, [])));
        $this->assertSame(['overview', 'prefixes', 'neighbours'], array_keys($s->requests(Indicator::asn(64500), [])));
        $this->assertStringContainsString('stat.ripe.net/app/', $s->reportUrl($this->ip));

        $r = $s->collect($this->ip, [
            'prefix' => self::json(['data' => ['announced' => true, 'resource' => '203.0.113.0/24', 'asns' => [['asn' => 64500, 'holder' => 'EXAMPLE']],
                'block' => ['resource' => '203.0.0.0/8', 'desc' => 'APNIC']]]),
            'geo' => self::json(['data' => ['located_resources' => [['locations' => [['city' => 'Sydney', 'country' => 'AU', 'latitude' => -33.8, 'longitude' => 151.2]]]]]]),
            'abuse' => self::json(['data' => ['abuse_contacts' => ['abuse@example.net'], 'authoritative_rir' => 'apnic']]),
        ], []);
        $this->assertSame('203.0.113.0/24 via AS64500', $r->summary);
        $this->assertSame([64500, 'EXAMPLE', 'AU', 'Sydney', 'abuse@example.net'], [$r->facts['asn'], $r->facts['as_name'], $r->facts['country'], $r->facts['city'], $r->facts['abuse_email']]);
        $this->assertSame('-33.800, 151.200', $r->rows['Coordinates']);

        $unrouted = $s->collect($this->ip, ['prefix' => self::json(['data' => ['announced' => false]]), 'geo' => ['status' => 500, 'body' => '']], []);
        $this->assertSame(['Address is not announced in global BGP'], self::labels($unrouted));

        $as = $s->collect(Indicator::asn(64500), [
            'overview' => self::json(['data' => ['holder' => 'EXAMPLE', 'announced' => true, 'block' => ['resource' => '64496-64511', 'desc' => 'Documentation']]]),
            'prefixes' => self::json(['data' => ['prefixes' => [['prefix' => '198.51.100.0/24'], ['prefix' => '198.51.100.0/25'], ['prefix' => '2001:db8::/32'], ['prefix' => 'bogus']]]]),
            'neighbours' => self::json(['data' => ['neighbour_counts' => ['left' => 2, 'right' => 5, 'uncertain' => 1], 'neighbours' => [1, 2, 3]]]),
        ], []);
        $this->assertSame([2, 1, 256.0], [$as->facts['prefix_count_v4'], $as->facts['prefix_count_v6'], $as->facts['address_space_v4']], 'overlapping prefixes are not double counted');
        $this->assertSame('2 upstream · 5 downstream · 1 uncertain', $as->rows['Neighbours']);
        $this->assertStringNotContainsString('"neighbours": [', $as->raw);
        $quiet = $s->collect(Indicator::asn(64500), ['overview' => self::json(['data' => ['holder' => 'X', 'announced' => false]])], []);
        $this->assertSame(['AS is not currently announcing any prefixes'], self::labels($quiet));
        $this->assertSame(SourceResult::ERROR, $s->collect($this->ip, ['prefix' => ['status' => 0, 'body' => '', 'error' => 'timeout']], [])->status);
    }

    public function testCymruOriginLookups(): void
    {
        $s = new CymruSource();
        $this->dns['TXT 7.113.0.203.origin.asn.cymru.com'] = ['64500 64501 | 203.0.113.0/24 | AU | apnic | 2010-05-01'];
        $this->dns['TXT AS64500.asn.cymru.com'] = ['64500 | AU | apnic | 2010-05-01 | EXAMPLE-AS, AU'];
        $r = $s->collect($this->ip, [], []);
        $this->assertSame('AS64500 · EXAMPLE-AS, AU', $r->summary);
        $this->assertSame(['AS64500, AS64501', '203.0.113.0/24'], [$r->rows['Origin AS'], $r->facts['bgp_prefix']]);
        $this->assertSame(['Announced by multiple origin ASes (MOAS)'], self::labels($r));

        $as = $s->collect(Indicator::asn(64500), [], []);
        $this->assertSame(['AU', '2010-05-01'], [$as->facts['country'], $as->facts['registered_at']]);
        $this->assertSame('No AS record', $s->collect(Indicator::asn(64511), [], [])->summary);

        $v6 = Indicator::ip('2001:db8::1');
        $this->assertSame([false], array_values($s->collect($v6, [], [])->facts), 'not announced');
        $this->dns['TXT ' . IpAddress::reversed('2001:db8::1') . '.origin6.asn.cymru.com'] = ['garbage'];
        $this->assertSame(SourceResult::ERROR, $s->collect($v6, [], [])->status);
        $this->dns['TXT ' . IpAddress::reversed('2001:db8::1') . '.origin6.asn.cymru.com'] = ['64510 | 2001:db8::/32 | ZZ | ripencc | '];
        $this->assertSame('AS64510', $s->collect($v6, [], [])->summary);
    }

    public function testReverseDnsAndForwardConfirmation(): void
    {
        $s = new ReverseDnsSource();
        $this->assertSame('No PTR record', $s->collect($this->ip, [], [])->summary);

        $ptr = IpAddress::ptrName('203.0.113.7');
        $this->dns["PTR $ptr"] = ['Tor-Exit-1.example.net'];
        $this->dns['A tor-exit-1.example.net'] = ['203.0.113.7'];
        $r = $s->collect($this->ip, [], []);
        $this->assertSame('tor-exit-1.example.net (confirmed)', $r->summary);
        $this->assertTrue($r->facts['fcrdns']);
        $this->assertSame([25, 'tor'], [$r->signals[0]['weight'], $r->signals[0]['group']]);

        $this->dns["PTR $ptr"] = ['dyn-203-0-113-7.pool.example.net', 'other.example.net'];
        $r = $s->collect($this->ip, [], []);
        $this->assertFalse($r->facts['fcrdns']);
        $this->assertSame('residential', $r->facts['network_type_hint']);
        $this->assertSame(['dyn-203-0-113-7.pool.example.net', 'other.example.net'], $r->rows['PTR']);
    }

    public function testWhoisFollowsReferrals(): void
    {
        $s = new WhoisSource();
        $this->assertSame(SourceResult::ERROR, $s->collect($this->ip, [], [])->status, 'IANA unreachable');

        $this->whois['whois.iana.org 203.0.113.7'] = "% IANA WHOIS server\nrefer:        whois.arin.net\n";
        $this->whois['whois.arin.net n + 203.0.113.7'] = "NetRange: 203.0.113.0 - 203.0.113.255\nReferralServer: whois://whois.example.net\n";
        $this->whois['whois.example.net 203.0.113.7'] = "% comment\n# comment\nnetname:  EXAMPLE-NET\ndescr:    Example Org\ninetnum:  203.0.113.0 - 203.0.113.255\ncountry: AU\nabuse-mailbox: abuse@example.net\n";
        $r = $s->collect($this->ip, [], []);
        $this->assertSame('EXAMPLE-NET · whois.example.net', $r->summary);
        $this->assertSame('whois.iana.org → whois.arin.net → whois.example.net', $r->rows['Server']);
        $this->assertSame('abuse@example.net', $r->facts['abuse_email']);

        // ARIN ASN queries use the "a +" form; an unreachable referral falls back to the IANA record
        $this->whois['whois.iana.org AS64500'] = "whois: whois.arin.net\n";
        $this->whois['whois.arin.net a + 64500'] = "ASName: EXAMPLE-AS\nOrgName: Example\n";
        $this->assertSame('EXAMPLE-AS', $s->collect(Indicator::asn(64500), [], [])->rows['Name']);
        $this->whois['whois.iana.org 198.51.100.1'] = "refer: whois.unreachable.example\n";
        $w = Network::whois('198.51.100.1');
        $this->assertSame(['whois.iana.org', "Could not reach whois.unreachable.example:43; showing the IANA record"], [$w['server'], $w['error']]);
        $this->whois['whois.iana.org 192.0.2.1'] = "status: RESERVED\n";
        $this->assertSame([null, 'whois.iana.org'], [Network::whois('192.0.2.1')['error'], Network::whois('192.0.2.1')['server']]);
    }

    public function testDnsResponseParsing(): void
    {
        $header = static fn(int $flags, int $an) => pack('nnnnnn', 0x1234, $flags, 1, $an, 0, 0);
        $question = "\x03www\x07example\x03net\x00" . pack('nn', 1, 1);
        $rr = static fn(int $type, string $rdata) => "\xC0\x0C" . pack('nnNn', $type, 1, 60, strlen($rdata)) . $rdata;

        $a = $header(0x8180, 2) . $question . $rr(1, inet_pton('203.0.113.7')) . $rr(5, "\x03cdn\xC0\x10");
        $this->assertSame(['203.0.113.7'], Network::dnsParse($a, 1));
        $aaaa = $header(0x8180, 1) . $question . $rr(28, inet_pton('2001:db8::7'));
        $this->assertSame(['2001:db8::7'], Network::dnsParse($aaaa, 28));
        $txt = $header(0x8180, 1) . $question . $rr(16, "\x05hello\x06 world");
        $this->assertSame(['hello world'], Network::dnsParse($txt, 16));
        $ptr = $header(0x8180, 1) . $question . $rr(12, "\x04host\xC0\x10");
        $this->assertSame(['host.example.net'], Network::dnsParse($ptr, 12));
        $this->assertSame([], Network::dnsParse($header(0x8183, 0) . $question, 1), 'NXDOMAIN');

        $query = Network::dnsQuery(7, 'example.net.', 16);
        $this->assertSame(pack('n', 7), substr($query, 0, 2));
        $this->assertStringEndsWith("\x07example\x03net\x00" . pack('nn', 16, 1), $query);
    }

    // ---- Local feeds --------------------------------------------------------------

    public function testFeedSourceMatchesLocalFeeds(): void
    {
        $s = new FeedSource();
        $this->assertTrue($s->isLocal());
        $this->assertSame(SourceResult::SKIPPED, $s->collect($this->ip, [], [])->status, 'documentation space is never matched');

        // Local matching only (no lookup): a public address and a public ASN
        $public = Indicator::ip('5.6.7.8');
        $this->assertSame(SourceResult::ERROR, $s->collect($public, [], [])->status, 'feeds not downloaded yet');

        Feeds::store('feodo', [Feeds::range('5.6.7.8', 'Dridex · offline')]);
        Feeds::store('ipsum', [Feeds::range('5.6.7.8', '6')]);
        Feeds::store('tor-exit', [Feeds::range('5.6.7.8')]);
        Feeds::store('spamhaus-drop', array_map(static fn($i) => Feeds::range("5.6.7.$i"), range(20, 40)));
        Feeds::store('spamhaus-asndrop', [['asn' => 3300, 'meta' => 'BAD-AS · RU']]);
        Feeds::store('aws', [Feeds::range('5.6.0.0/16', 'EC2 · eu-west-2')]);
        Feeds::store('cloudflare', [Feeds::range('5.6.7.0/24')]);

        $r = $s->collect($public, [], ['asn' => 3300]);
        $this->assertSame(SourceResult::HIT, $r->status);
        $labels = self::labels($r);
        $this->assertContains('abuse.ch Feodo Tracker: former botnet C2', $labels);
        $this->assertContains('Tor exit node', $labels);
        $this->assertContains('Origin network AS3300 is on Spamhaus ASN-DROP', $labels);
        $this->assertNotEmpty(preg_grep('/: on 6 blocklists$/', $labels));
        $this->assertNotEmpty(preg_grep('/^Bad neighbourhood: \d+ other listings/', $labels));
        $this->assertTrue($r->facts['is_tor'] && $r->facts['is_hosting']);
        $this->assertSame('cdn', $r->facts['network_type']);
        $this->assertSame(4, $r->facts['feed_hits']);

        $clean = $s->collect(Indicator::ip('9.9.9.9'), [], []);
        $this->assertSame(SourceResult::CLEAN, $clean->status);

        $as = $s->collect(Indicator::asn(3300), [], ['prefixes' => ['5.6.7.0/24', '2a00::/32'], 'address_space_v4' => 256]);
        $this->assertSame('On Spamhaus ASN-DROP', $as->summary);
        $this->assertTrue($as->facts['asn_drop']);
        $this->assertNotEmpty(preg_grep('/Spamhaus DROP netblocks inside this AS/', self::labels($as)));
        $this->assertNotEmpty(preg_grep('/^Abuse density/', self::labels($as)));
        $this->assertSame(['5.6.7.0/24' => 24], Feeds::countPerPrefix(['5.6.7.0/24', '9.9.9.0/24', 'bogus']));

        $quiet = $s->collect(Indicator::asn(3301), [], []);
        $this->assertSame([SourceResult::CLEAN, '0 listings · 0.00 per /24'], [$quiet->status, $quiet->summary]);
    }

    public function testFeedUpdateParsesEveryFormat(): void
    {
        $bodies = [
            'plain' => "# list\n5.6.7.8\n5.6.8.0/24\n; note\n",
            'ipsum' => "# IPsum\n5.6.7.8\t5\n5.6.7.9\t1\n",
            'feodo' => json_encode([['ip_address' => '5.6.7.8', 'port' => 443, 'status' => 'online', 'malware' => 'QakBot']]),
            'spamhaus-drop' => '{"cidr":"5.6.0.0/16","sblid":"SBL1"}' . "\n" . '{"type":"metadata"}',
            'spamhaus-asndrop' => '{"asn":3300,"asname":"BAD","cc":"RU","domain":"bad.example"}',
            'aws' => json_encode(['prefixes' => [['ip_prefix' => '5.6.0.0/16', 'service' => 'EC2', 'region' => 'eu-west-2']], 'ipv6_prefixes' => [['ipv6_prefix' => '2a05::/32', 'service' => 'EC2', 'region' => 'eu-west-2']]]),
            'gcp' => json_encode(['prefixes' => [['ipv4Prefix' => '5.7.0.0/16', 'service' => 'Google Cloud', 'scope' => 'europe-west2'], ['ipv6Prefix' => '2a06::/32']]]),
            'csv-cidr' => "5.8.0.0/16,GB,London\n# x\n2a07::/32,GB,\n",
        ];
        $byUrl = [];
        foreach (FeedCatalogue::all() as $key => $def) {
            foreach ($def['urls'] as $url) {
                $byUrl[$url] = $bodies[$def['format']];
            }
        }
        Http::$fake = static fn(array $r) => ['status' => 200, 'body' => $byUrl[$r['url']] ?? '', 'time' => 0.05];
        $results = Feeds::update(null, true);
        $failed = array_filter($results, static fn($r) => !$r['ok']);
        $this->assertSame([], array_map(static fn($r) => $r['error'], $failed));
        $this->assertTrue(Feeds::isLoaded());
        $this->assertSame([], Feeds::staleKeys(false));
        $this->assertArrayHasKey('feodo', Feeds::matchIp('5.6.7.8'));
        $this->assertSame([], Feeds::update(), 'nothing is stale right after an update');

        // A feed that parses to nothing is reported, not stored
        Http::$fake = static fn(array $r) => ['status' => 200, 'body' => "# empty\n"];
        $r = Feeds::update(['firehol-l1'], true);
        $this->assertFalse($r['firehol-l1']['ok'] ?? true);
        $this->assertStringContainsString('0 entries', $r['firehol-l1']['error'] ?? '0 entries');
        $this->assertSame([], Feeds::update(['no-such-feed'], true));
    }

    // ---- Registry and helpers -----------------------------------------------------

    public function testSourceRegistryHonoursDisabledSources(): void
    {
        $all = SourceRegistry::all();
        $this->assertGreaterThanOrEqual(15, count($all));
        $this->assertInstanceOf(RdapSource::class, SourceRegistry::get('rdap'));
        $this->assertNull(SourceRegistry::get('nope'));
        $this->assertArrayHasKey(Source::CAT_REPUTATION, SourceRegistry::categoryLabels());

        putenv('INTEL_DISABLE=rdap,otx');
        $this->assertFalse(SourceRegistry::get('rdap')->isEnabled());
        $this->assertTrue(SourceRegistry::get('whois')->isEnabled());
        foreach ($all as $source) {
            $this->assertNotSame('', $source->label());
            $this->assertNotSame('', $source->description());
            $this->assertNotSame('', $source->homepage());
            $source->terms();
            $source->ttl();
            $this->assertIsBool($source->isReputation());
        }
    }

    public function testHtmlHelpersAndPivots(): void
    {
        $this->assertSame(2, substr_count(IntelHtml::value(['a', 'b']), 'gw-intel-chip"'));
        $this->assertStringContainsString('/intel/asn/AS64500', IntelHtml::value('AS64500 · Example'));
        $this->assertStringContainsString('/intel/ip/2001:db8::1', urldecode(IntelHtml::value('2001:DB8::1')));
        $this->assertStringContainsString('&lt;b&gt;', IntelHtml::value('<b>'));
        $this->assertStringContainsString('href="https://example.org"', IntelHtml::value('https://example.org'));
        $this->assertStringContainsString('mailto:abuse@example.net', IntelHtml::value('abuse@example.net'));
        $this->assertStringContainsString('source-status', IntelHtml::status(SourceResult::hit('x')));
        $this->assertSame('250 ms', IntelHtml::duration(0.25));
        $this->assertSame('1.5 s', IntelHtml::duration(1.5));
        $this->assertSame(['never', 'just now', '5 min ago', '2 h ago', '3 d ago'],
            array_map([IntelHtml::class, 'age'], [null, time() - 5, time() - 300, time() - 7200, time() - 3 * 86400]));
        $short = SourceResult::clean();
        $tall = SourceResult::hit('x')->row('Tags', range(1, 13))->table('T', ['a'], array_fill(0, 20, ['b']))->rawJson(['k' => 1]);
        $this->assertSame(165, IntelHtml::weight($short));
        $this->assertSame(165 + 75 + 75 + 340 + 35, IntelHtml::weight($tall));
        // Tallest first to the shortest column, each column back in the original order
        $this->assertSame([['b' => 1], ['a' => 0, 'c' => 2]], IntelHtml::stacks(['a' => $short, 'b' => $tall, 'c' => $short]));
        $this->assertSame([['a' => 0], []], IntelHtml::stacks(['a' => $short]));
        $this->assertSame('', IntelHtml::flag(null));
        $this->assertSame('', IntelHtml::flag('GBR'));
        $this->assertStringContainsString('>GB<', IntelHtml::flag('gb'));

        $ip = Pivots::for($this->ip);
        $asn = Pivots::for(Indicator::asn(64500));
        $this->assertGreaterThan(10, count($ip, COUNT_RECURSIVE));
        $this->assertNotEmpty($asn);
        foreach (array_merge(...array_values(array_map(static fn($g) => is_array($g) ? array_values($g) : [], $ip))) as $link) {
            $this->assertStringStartsWith('https://', is_array($link) ? ($link['url'] ?? reset($link)) : $link);
        }
    }
}
