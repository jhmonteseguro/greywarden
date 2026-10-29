<?php

namespace tests\unit;

use app\models\Setting;
use app\modules\intel\components\edge\CloudflareTraffic;
use app\modules\intel\components\edge\EdgeAnalyzer;
use app\modules\intel\components\edge\EdgeCatalogue;
use app\modules\intel\components\edge\EdgeSettings;
use tests\DbTestCase;

/**
 * Edge traffic analysis without the network: probe / agent / payload catalogue,
 * settings parsing, per-address profiles, merging across pulls and scoring.
 */
class EdgeAnalyzerTest extends DbTestCase
{
    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

    private static function ip(string $ip, int $count, string $agent = self::CHROME, string $method = 'GET', ?int $asn = 64501): array
    {
        return ['ip' => $ip, 'asn' => $asn, 'country' => 'NL', 'agent' => $agent, 'method' => $method, 'count' => $count];
    }

    private static function path(string $ip, string $path, int $status, int $count = 1, string $query = ''): array
    {
        return compact('ip', 'path', 'query', 'status', 'count');
    }

    private static function profile(array $traffic, int $minutes = 15): array
    {
        return EdgeAnalyzer::profiles($traffic + ['ips' => [], 'errors' => [], 'exposures' => [], 'payloads' => [], 'firewall' => []], $minutes);
    }

    private static function keys(array $scored): array
    {
        return array_column($scored['signals'], 'key');
    }

    // ---- Catalogue ----------------------------------------------------------------

    public function testProbeGroupsAndExposure(): void
    {
        $this->assertSame('secrets', EdgeCatalogue::probeGroup('/.env'));
        $this->assertSame('secrets', EdgeCatalogue::probeGroup('/app/.ENV.bak'));
        $this->assertSame('secrets', EdgeCatalogue::probeGroup('/.git/config'));
        $this->assertSame('wordpress', EdgeCatalogue::probeGroup('/wp-login.php'));
        $this->assertSame('dbadmin', EdgeCatalogue::probeGroup('/phpMyAdmin/index.php'));
        $this->assertSame('exploits', EdgeCatalogue::probeGroup('/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php'));
        $this->assertSame('shells', EdgeCatalogue::probeGroup('/shell.php'));
        $this->assertNull(EdgeCatalogue::probeGroup('/products/42'));
        $this->assertTrue(EdgeCatalogue::isExposureGroup('secrets'));
        $this->assertTrue(EdgeCatalogue::isExposureGroup('shells'));
        $this->assertFalse(EdgeCatalogue::isExposureGroup('wordpress'));
        $this->assertFalse(EdgeCatalogue::isExposureGroup(null));
        $this->assertSame('WordPress', EdgeCatalogue::groupLabel('wordpress'));
        $this->assertSame('unknown', EdgeCatalogue::groupLabel('unknown'));

        $likes = EdgeCatalogue::exposureLikes();
        $this->assertContains('%/.env%', $likes);
        $this->assertContains('%/.ENV%', $likes);
        $this->assertContains('%/.DS_Store%', $likes);
        $this->assertNotContains('%/wp-login.php%', $likes, 'only groups where success matters');
        $this->assertSame(array_values(array_unique($likes)), $likes);
    }

    public function testAgentKinds(): void
    {
        $this->assertSame(['kind' => 'scanner', 'name' => 'sqlmap'], EdgeCatalogue::agentKind('sqlmap/1.8.2#stable (https://sqlmap.org)'));
        $this->assertSame('scanner', EdgeCatalogue::agentKind('Mozilla/5.0 (compatible; Nuclei - Open-source project (github.com/projectdiscovery/nuclei))')['kind']);
        $this->assertSame(['kind' => 'research', 'name' => 'censys'], EdgeCatalogue::agentKind('Mozilla/5.0 (compatible; CensysInspect/1.1; +https://about.censys.io/)'));
        $this->assertSame(['kind' => 'library', 'name' => 'python-requests'], EdgeCatalogue::agentKind('python-requests/2.31.0'));
        $this->assertSame(['kind' => 'library', 'name' => 'curl'], EdgeCatalogue::agentKind('curl/8.5.0'));
        $this->assertSame(['kind' => 'library', 'name' => 'java'], EdgeCatalogue::agentKind('Java/17.0.2'));
        $this->assertSame('empty', EdgeCatalogue::agentKind('')['kind']);
        $this->assertSame('empty', EdgeCatalogue::agentKind(' - ')['kind']);
        $this->assertNull(EdgeCatalogue::agentKind(self::CHROME));
    }

    public function testPayloadFamilies(): void
    {
        $this->assertSame(['SQL injection'], EdgeCatalogue::payloadFamilies('/products?id=1%27%20UNION%20SELECT%20NULL--'));
        $this->assertSame(['SQL injection'], EdgeCatalogue::payloadFamilies('/p?id=1+union+select+1'));
        $this->assertSame(['Path traversal'], EdgeCatalogue::payloadFamilies('/download?file=..%252f..%252fetc%252fpasswd'));
        $this->assertSame(['Cross-site scripting'], EdgeCatalogue::payloadFamilies('/search?q=%3Cscript%3Ealert(1)%3C/script%3E'));
        $this->assertSame(['Code / command injection'], EdgeCatalogue::payloadFamilies('/?x=${jndi:ldap://203.0.113.5/a}'));
        $this->assertSame(['Server-side request forgery'], EdgeCatalogue::payloadFamilies('/fetch?url=http://169.254.169.254/latest/meta-data/'));
        $this->assertSame([], EdgeCatalogue::payloadFamilies('/products?page=2&sort=price'));
    }

    public function testRuleCondition(): void
    {
        $rule = EdgeCatalogue::ruleCondition('wordpress');
        $this->assertStringContainsString('(lower(http.request.uri.path) contains "/wp-login.php")', $rule);
        $this->assertStringContainsString(' or ', $rule);
        $this->assertStringNotContainsString('/wp-admin', EdgeCatalogue::ruleCondition('wordpress', ['/wp-admin']));
        $this->assertNull(EdgeCatalogue::ruleCondition('wordpress', ['/']), 'every path ignored');
        $this->assertNull(EdgeCatalogue::ruleCondition('unknown'));
        $this->assertStringNotContainsString('..', EdgeCatalogue::ruleCondition('secrets'), 'traversal paths never reach a rule');
    }

    // ---- Settings -------------------------------------------------------------------

    public function testSettingsDefaultsAndLists(): void
    {
        $this->assertSame(30, EdgeSettings::minScore());
        $this->assertSame(7, EdgeSettings::blockDays());
        $this->assertSame(120, EdgeSettings::volumeRpm());
        $this->assertSame([], EdgeSettings::ignoredPaths());

        Setting::put(['edge.min_score' => '250', 'edge.block_days' => '0', 'edge.volume_rpm' => '0', 'edge.ignore_paths' => "WP-Admin\n  # comment\n/api/ # ours\n\n"]);
        $this->assertSame(99, EdgeSettings::minScore());
        $this->assertSame(1, EdgeSettings::blockDays());
        $this->assertSame(1, EdgeSettings::volumeRpm());
        $this->assertSame(['/wp-admin', '/api/'], EdgeSettings::ignoredPaths());
        $this->assertTrue(EdgeSettings::isIgnoredPath('/WP-ADMIN/install.php', EdgeSettings::ignoredPaths()));
        $this->assertFalse(EdgeSettings::isIgnoredPath('/wp-login.php', EdgeSettings::ignoredPaths()));

        Setting::put(['edge.min_score' => '']);
        $this->assertSame(30, EdgeSettings::minScore(), 'blank = default');
    }

    public function testAllowlist(): void
    {
        $list = EdgeSettings::allowlist("203.0.113.10   # office\n198.51.100.0/24\nAS64500\n2001:db8::/32\nnot-an-ip\n");
        $this->assertSame(['203.0.113.10'], $list['ips']);
        $this->assertSame(['198.51.100.0/24', '2001:db8::/32'], $list['cidrs']);
        $this->assertSame([64500], $list['asns']);
        $this->assertSame(['not-an-ip'], $list['invalid']);
        $this->assertTrue(EdgeSettings::isAllowlisted('203.0.113.10', null, $list));
        $this->assertTrue(EdgeSettings::isAllowlisted('198.51.100.77', null, $list));
        $this->assertTrue(EdgeSettings::isAllowlisted('2001:db8::5', null, $list));
        $this->assertTrue(EdgeSettings::isAllowlisted('192.0.2.1', 64500, $list));
        $this->assertFalse(EdgeSettings::isAllowlisted('192.0.2.1', 64501, $list));
        Setting::put(['edge.allowlist' => '192.0.2.1']);
        $this->assertTrue(EdgeSettings::isAllowlisted('192.0.2.1', null), 'reads the saved setting');
    }

    // ---- Profiles ---------------------------------------------------------------------

    public function testProfilesCombineEveryQuery(): void
    {
        $profiles = self::profile([
            'ips' => [self::ip('192.0.2.10', 30), self::ip('192.0.2.10', 5, 'curl/8.0', 'HEAD'), self::ip('bogus', 3), self::ip('192.0.2.20', 2)],
            'errors' => [self::path('192.0.2.10', '/.git/config', 403), self::path('192.0.2.10', '/missing', 404, 2), self::path('192.0.2.10', '/missing', 410), self::path('bogus', '/x', 404)],
            'exposures' => [self::path('192.0.2.10', '/.env', 200, 2)],
            'payloads' => [self::path('192.0.2.10', '/p', 200, 4, '?id=1 union select 1')],
            'firewall' => [
                ['ip' => '192.0.2.10', 'action' => 'block', 'count' => 3], ['ip' => '192.0.2.10', 'action' => 'managedchallengeinteractivesolved', 'count' => 1],
                ['ip' => '192.0.2.10', 'action' => 'log', 'count' => 9], ['ip' => 'bogus', 'action' => 'block', 'count' => 1],
            ],
        ]);
        $this->assertSame(['192.0.2.10', '192.0.2.20'], array_keys($profiles));
        $p = $profiles['192.0.2.10'];
        $this->assertSame(35, $p['requests']);
        $this->assertSame(3, $p['stopped']);
        $this->assertSame(1, $p['passed']);
        $this->assertSame(64501, $p['asn']);
        $this->assertSame('NL', $p['country']);
        $this->assertSame(['GET' => 30, 'HEAD' => 5], $p['methods']);
        $this->assertSame([self::CHROME => 30, 'curl/8.0' => 5], $p['agents']);
        $this->assertSame(['count' => 3, 'statuses' => [404 => 2, 410 => 1], 'group' => null], $p['paths']['/missing']);
        $this->assertSame('secrets', $p['paths']['/.env']['group']);
        $this->assertSame([200 => 2], $p['paths']['/.env']['statuses']);
        $this->assertArrayNotHasKey('/p', $p['paths'], 'payload rows are not counted as paths');
        $this->assertSame(['SQL injection'], $p['payloads']['/p?id=1 union select 1']['families']);
        $this->assertSame(3, $p['peak_rpm'], '(35 - 3) / 15 minutes');
        // Probes are kept before ordinary 404s
        $this->assertSame(['/.env', '/.git/config', '/missing'], array_keys($p['paths']));
    }

    public function testProfilesHonourAllowlistIgnoredPathsAndMissingVolume(): void
    {
        $traffic = [
            'errors' => [self::path('192.0.2.30', '/wp-admin/x', 404, 5), self::path('192.0.2.30', '/wp-login.php', 404, 2), self::path('192.0.2.31', '/x', 404)],
        ];
        $profiles = EdgeAnalyzer::profiles($traffic + ['ips' => [self::ip('192.0.2.31', 1, self::CHROME, 'GET', 64500)]], 1, ['/wp-admin'], EdgeSettings::allowlist('AS64500'));
        $this->assertSame(['192.0.2.30'], array_keys($profiles), 'AS64500 is allowlisted');
        $this->assertSame(['/wp-login.php'], array_keys($profiles['192.0.2.30']['paths']));
        $this->assertSame(2, $profiles['192.0.2.30']['requests'], 'error rows count when the volume query missed the address');
    }

    public function testMergeAddsCountsAndKeepsListsShort(): void
    {
        $old = self::profile(['ips' => [self::ip('192.0.2.10', 10)], 'errors' => [self::path('192.0.2.10', '/a', 404)],
            'payloads' => [self::path('192.0.2.10', '/p', 403, 1, 'q=../etc/passwd')]], 1)['192.0.2.10'];
        $new = self::profile(['ips' => [self::ip('192.0.2.10', 50, 'curl/8')], 'errors' => [self::path('192.0.2.10', '/a', 403), self::path('192.0.2.10', '/b', 404)],
            'payloads' => [self::path('192.0.2.10', '/p', 403, 2, 'q=../etc/passwd')], 'firewall' => [['ip' => '192.0.2.10', 'action' => 'block', 'count' => 4]]], 10)['192.0.2.10'];
        $merged = EdgeAnalyzer::merge($old, $new);
        $this->assertSame(60, $merged['requests']);
        $this->assertSame(4, $merged['stopped']);
        $this->assertSame(10, $merged['peak_rpm'], 'the higher peak');
        $this->assertSame(['count' => 2, 'statuses' => [404 => 1, 403 => 1], 'group' => null], $merged['paths']['/a']);
        $this->assertSame(3, $merged['payloads']['/p?q=../etc/passwd']['count']);
        $this->assertSame(['curl/8' => 50, self::CHROME => 10], $merged['agents']);
        $this->assertSame($old['requests'], EdgeAnalyzer::merge([], $old)['requests'], 'an empty old profile');

        $big = EdgeAnalyzer::emptyProfile();
        for ($i = 0; $i < 400; $i++) {
            $big['paths']["/p$i"] = ['count' => 1, 'statuses' => [404 => 1], 'group' => null];
            $big['agents']["agent $i"] = 1;
            $big['payloads']["/x?$i"] = ['count' => 1, 'status' => 403, 'families' => ['SQL injection']];
        }
        $big['paths']['/.env'] = ['count' => 1, 'statuses' => [404 => 1], 'group' => 'secrets'];
        $trimmed = EdgeAnalyzer::merge(EdgeAnalyzer::emptyProfile(), $big);
        $this->assertCount(EdgeAnalyzer::MAX_PATHS, $trimmed['paths']);
        $this->assertArrayHasKey('/.env', $trimmed['paths'], 'probes survive trimming');
        $this->assertCount(EdgeAnalyzer::MAX_AGENTS, $trimmed['agents']);
        $this->assertCount(EdgeAnalyzer::MAX_PAYLOADS, $trimmed['payloads']);
    }

    public function testHandled(): void
    {
        $p = EdgeAnalyzer::emptyProfile();
        $this->assertFalse(EdgeAnalyzer::isHandled($p));
        $this->assertTrue(EdgeAnalyzer::isHandled(['requests' => 10, 'stopped' => 9] + $p));
        $this->assertFalse(EdgeAnalyzer::isHandled(['requests' => 10, 'stopped' => 8] + $p));
    }

    // ---- Scoring -------------------------------------------------------------------------

    public function testCleanTrafficScoresZero(): void
    {
        $scored = EdgeAnalyzer::score(self::profile(['ips' => [self::ip('192.0.2.1', 40)], 'errors' => [self::path('192.0.2.1', '/old-page', 404)]])['192.0.2.1']);
        $this->assertSame(0, $scored['score']);
        $this->assertSame([], $scored['signals']);
        $this->assertSame('', $scored['headline']);
        $this->assertFalse($scored['exposure']);
        $this->assertSame(1, $scored['error_paths']);
        $this->assertSame('low', EdgeAnalyzer::priority($scored['score'], false));
    }

    public function testExposureAndProbes(): void
    {
        $p = self::profile([
            'ips' => [self::ip('192.0.2.1', 10)],
            'errors' => [self::path('192.0.2.1', '/.git/config', 403), self::path('192.0.2.1', '/wp-login.php', 404)],
            'exposures' => [self::path('192.0.2.1', '/.env', 200), self::path('192.0.2.1', '/phpinfo.php', 301)],
        ])['192.0.2.1'];
        $scored = EdgeAnalyzer::score($p);
        $this->assertSame(['exposure', 'probe'], self::keys($scored));
        $this->assertTrue($scored['exposure']);
        $this->assertStringContainsString('/.env', $scored['signals'][0]['label']);
        $this->assertStringNotContainsString('phpinfo', $scored['signals'][0]['label'], 'a redirect is not an exposure');
        $this->assertSame(45, $scored['signals'][1]['weight'], '4 probe paths: 30 + 3 x 5');
        $this->assertStringContainsString('and 1 more', $scored['signals'][1]['label']);
        $this->assertSame('Sensitive file served · Probed 4 attack paths', $scored['headline']);
        $this->assertSame(95, $scored['score'], '1 - 0.10 x 0.55');
        $this->assertSame('high', EdgeAnalyzer::priority($scored['score'], true));

        $one = EdgeAnalyzer::score(self::profile(['errors' => [self::path('192.0.2.1', '/wp-login.php', 404)]])['192.0.2.1']);
        $this->assertSame(30, $one['score']);
        $this->assertSame('Probed 1 attack path', $one['headline']);

        $many = EdgeAnalyzer::emptyProfile();
        foreach (EdgeCatalogue::PROBE_GROUPS['exploits']['paths'] as $path) {
            $many['paths'][$path] = ['count' => 1, 'statuses' => [404 => 1], 'group' => 'exploits'];
        }
        $this->assertSame(60, EdgeAnalyzer::score($many)['signals'][0]['weight'], 'capped');
    }

    public function testPayloadsAgentsEnumerationMethodsAndVolume(): void
    {
        $errors = [];
        for ($i = 0; $i < 45; $i++) {
            $errors[] = self::path('192.0.2.2', "/dir$i", 404);
        }
        $p = self::profile([
            'ips' => [self::ip('192.0.2.2', 2000, 'sqlmap/1.8'), self::ip('192.0.2.2', 10, 'curl/8', 'PROPFIND')],
            'errors' => $errors,
            'payloads' => [self::path('192.0.2.2', '/p', 200, 3, 'id=1 union select 2')],
        ], 1)['192.0.2.2'];
        $scored = EdgeAnalyzer::score($p, ['volume_rpm' => 100]);
        $byKey = array_column($scored['signals'], null, 'key');
        $this->assertSame(['payload', 'agent', 'enumeration', 'volume', 'methods'], self::keys($scored));
        $this->assertSame(60, $byKey['payload']['weight'], 'answered with a success code');
        $this->assertStringContainsString('check the application logs', $byKey['payload']['detail']);
        $this->assertSame(55, $byKey['agent']['weight']);
        $this->assertSame('Scanner tool', $byKey['agent']['short']);
        $this->assertSame(40, $byKey['enumeration']['weight'], '45 paths');
        $this->assertSame(40, $byKey['volume']['weight'], '2010 a minute is over 4 x 100');
        $this->assertSame('Unusual HTTP methods: PROPFIND', $byKey['methods']['label']);
        $this->assertSame('high', EdgeAnalyzer::priority($scored['score'], false));

        // Rejected payloads weigh less; 15 and 100 error paths are the other enumeration bands; a lower rate is the lower volume band
        $p['payloads']['/p?id=1 union select 2']['status'] = 403;
        $p['peak_rpm'] = 150;
        $byKey = array_column(EdgeAnalyzer::score($p, ['volume_rpm' => 100])['signals'], null, 'key');
        $this->assertSame(45, $byKey['payload']['weight']);
        $this->assertStringContainsString('All were rejected', $byKey['payload']['detail']);
        $this->assertSame(25, $byKey['volume']['weight']);
        $p['paths'] = array_slice($p['paths'], 0, 15, true);
        $this->assertSame(25, array_column(EdgeAnalyzer::score($p)['signals'], null, 'key')['enumeration']['weight']);
        for ($i = 0; $i < 100; $i++) {
            $p['paths']["/more$i"] = ['count' => 1, 'statuses' => [404 => 1], 'group' => null];
        }
        $this->assertSame(55, array_column(EdgeAnalyzer::score($p)['signals'], null, 'key')['enumeration']['weight']);
    }

    public function testAgentKindsScoreDifferently(): void
    {
        foreach ([
            ['Mozilla/5.0 (compatible; CensysInspect/1.1)', 'Survey scanner', 15, true],
            ['python-requests/2.31', 'Scripted client', 15, false],
            ['', 'No user agent', 15, false],
        ] as [$agent, $short, $weight, $research]) {
            $scored = EdgeAnalyzer::score(self::profile(['ips' => [self::ip('192.0.2.3', 5, $agent)]])['192.0.2.3']);
            $this->assertSame($short, $scored['signals'][0]['short'], $agent);
            $this->assertSame($weight, $scored['signals'][0]['weight']);
            $this->assertSame($research, $scored['research']);
        }
        // The strongest agent wins
        $scored = EdgeAnalyzer::score(self::profile(['ips' => [self::ip('192.0.2.3', 5, 'curl/8'), self::ip('192.0.2.3', 1, 'Nikto/2.5')]])['192.0.2.3']);
        $this->assertSame(['agent'], self::keys($scored));
        $this->assertSame('Scanner user agent: nikto', $scored['signals'][0]['label']);
    }

    public function testRuleGapAndChallenge(): void
    {
        $p = self::profile([
            'ips' => [self::ip('192.0.2.4', 10)],
            'errors' => [self::path('192.0.2.4', '/.env', 404)],
            'firewall' => [['ip' => '192.0.2.4', 'action' => 'jschallengesolved', 'count' => 2]],
        ])['192.0.2.4'];
        $scored = EdgeAnalyzer::score($p, ['block' => ['reference' => 'BLK-0009', 'cloudflare' => true]]);
        $this->assertSame(['rule-gap', 'probe', 'challenge'], self::keys($scored));
        $this->assertSame('Blocked by BLK-0009 but 10 requests still reached the site', $scored['signals'][0]['label']);
        $this->assertSame('Passed 2 Cloudflare challenges', $scored['signals'][2]['label']);

        // Only a block that is in the Cloudflare rule counts, and only when requests got through
        $this->assertNotContains('rule-gap', self::keys(EdgeAnalyzer::score($p, ['block' => ['reference' => 'BLK-0009', 'cloudflare' => false]])));
        $p['stopped'] = 10;
        $this->assertNotContains('rule-gap', self::keys(EdgeAnalyzer::score($p, ['block' => ['reference' => 'BLK-0009', 'cloudflare' => true]])));
        // A solved challenge alone is not suspicious
        $quiet = self::profile(['ips' => [self::ip('192.0.2.5', 3)], 'firewall' => [['ip' => '192.0.2.5', 'action' => 'challengesolved', 'count' => 1]]])['192.0.2.5'];
        $this->assertSame([], EdgeAnalyzer::score($quiet)['signals']);
    }

    public function testPriority(): void
    {
        $this->assertSame('high', EdgeAnalyzer::priority(70, false));
        $this->assertSame('high', EdgeAnalyzer::priority(10, false, 'malicious'));
        $this->assertSame('medium', EdgeAnalyzer::priority(40, false));
        $this->assertSame('medium', EdgeAnalyzer::priority(10, false, 'suspicious'));
        $this->assertSame('low', EdgeAnalyzer::priority(39, false, 'clean'));
    }

    public function testFirewallActionLists(): void
    {
        foreach (CloudflareTraffic::STOPPING_ACTIONS as $action) {
            $this->assertSame(strtolower(str_replace('_', '', $action)), $action);
        }
        $this->assertEmpty(array_intersect(CloudflareTraffic::STOPPING_ACTIONS, CloudflareTraffic::PASSED_ACTIONS));
    }
}
