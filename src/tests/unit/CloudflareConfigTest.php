<?php

namespace tests\unit;

use app\modules\cloud\components\CloudConfig;
use app\modules\cloud\components\CloudflareConfig;
use PHPUnit\Framework\TestCase;

/**
 * Cloudflare zone configuration: labels, settings shown, the findings and the
 * changes between two syncs that go into the zone's History.
 */
class CloudflareConfigTest extends TestCase
{
    private const GOOD = [
        'paused' => false, 'ssl' => 'strict', 'min_tls' => '1.2', 'always_use_https' => true, 'hsts' => true, 'security_level' => 'medium',
        'dnssec' => 'active', 'blocklist_rule' => 'enabled', 'dns_records' => 4,
        'proxied' => ['example.com (A)', 'www.example.com (CNAME)'], 'dns_only' => [],
    ];

    public function testTypeLabel(): void
    {
        $this->assertSame('Cloudflare zone', CloudflareConfig::typeLabel('zone'));
        $this->assertSame('Cloudflare zone', CloudConfig::typeLabel('cloudflare', 'zone'));
        $this->assertSame('waf', CloudflareConfig::typeLabel('waf'));
        $this->assertNull(CloudflareConfig::typeLabel(null));
        $this->assertNull(CloudflareConfig::typeLabel(''));
    }

    public function testFacts(): void
    {
        $facts = CloudConfig::facts('cloudflare', self::GOOD + ['not_read' => ['dns', 'other'], 'extra_setting' => 'x', 'list' => [], 'blank' => null]);
        $this->assertSame([
            ['Paused', 'No'],
            ['SSL/TLS encryption mode', 'Full (strict)'],
            ['Minimum TLS version', '1.2'],
            ['Always Use HTTPS', 'On'],
            ['HSTS', 'On'],
            ['Security level', 'medium'],
            ['DNSSEC', 'Active'],
            ['Greywarden Blocklist rule', 'Enabled'],
            ['DNS records', '4'],
            ['Proxied records', 'example.com (A), www.example.com (CNAME)'],
            ['DNS-only records (could be proxied)', 'None'],
            ['Not read (token permissions)', 'DNS records; other'],
            ['Extra setting', 'x'],
            ['List', 'None'],
            ['Blank', ''],
        ], $facts);
        $this->assertSame([['Paused', 'Yes'], ['SSL/TLS encryption mode', 'weird'], ['Always Use HTTPS', 'Off']],
            CloudflareConfig::facts(['always_use_https' => false, 'ssl' => 'weird', 'paused' => true]), 'known settings in order; an unknown mode as Cloudflare sends it');
        $this->assertSame([], CloudflareConfig::facts([]));
    }

    public function testNoFindingsForAGoodZone(): void
    {
        $this->assertSame([], CloudflareConfig::findings('zone', self::GOOD));
        $this->assertSame([], CloudflareConfig::findings('zone', []), 'nothing read, nothing found');
        $this->assertSame([], CloudflareConfig::findings('waf', ['ssl' => 'off']), 'only zones');
    }

    public function testFindings(): void
    {
        $bad = ['paused' => true, 'ssl' => 'flexible', 'min_tls' => '1.0', 'always_use_https' => false, 'hsts' => false, 'dnssec' => 'disabled',
            'blocklist_rule' => 'disabled', 'dns_only' => ['legacy.example.com (A)', 'mail.example.com (A)', 'x.dev.example.com (AAAA)'],
            'not_read' => ['rules', 'unknown']];
        $findings = CloudflareConfig::findings('zone', $bad, ['mail.example.com', '*.dev.example.com']);
        $this->assertSame([
            ['high', 'The zone is paused: Cloudflare passes all traffic straight to the origin'],
            ['high', 'legacy.example.com (A) is DNS only: its traffic skips Cloudflare (WAF, blocklist, DDoS protection)'],
            ['high', 'The Greywarden Blocklist rule is disabled: blocked addresses get through'],
            ['high', 'SSL/TLS mode is Flexible: Cloudflare reaches the origin over plain HTTP'],
            ['medium', 'Minimum TLS version is 1.0 (1.2 or later recommended)'],
            ['medium', 'Always Use HTTPS is off: plain HTTP requests are not redirected'],
            ['medium', 'Could not read the custom WAF rules: give the API token Zone › Zone WAF › Read'],
            ['low', 'HSTS is off'],
            ['low', 'DNSSEC is not active (disabled)'],
        ], array_map(static fn($f) => [$f['severity'], $f['title']], $findings));

        $title = static fn(array $p) => array_column(CloudflareConfig::findings('zone', $p), 'title');
        $this->assertSame(['SSL/TLS is off: visitors cannot use HTTPS'], $title(['ssl' => 'off']));
        $this->assertSame(['SSL/TLS mode is Full, not Full (strict): the origin certificate is not checked'], $title(['ssl' => 'full']));
        $this->assertSame(['The Greywarden Blocklist rule is missing: blocked addresses get through'], $title(['blocklist_rule' => 'missing']));
        $this->assertSame([], $title(['min_tls' => '1.3', 'dnssec' => 'active', 'blocklist_rule' => 'enabled']));
        $this->assertSame(['Minimum TLS version is 1.1 (1.2 or later recommended)'], $title(['min_tls' => '1.1']));
        $this->assertSame(['Could not read the DNS records: give the API token Zone › DNS › Read'], $title(['not_read' => ['dns']]));
    }

    public function testFindingsUseTheDnsOnlySetting(): void
    {
        // CloudConfig passes Admin › Settings › Edge monitoring › DNS only on purpose (empty in a fresh install)
        $this->assertCount(1, CloudConfig::findings('cloudflare', 'zone', ['dns_only' => ['mail.example.com (A)']]));
        $this->assertSame([], CloudConfig::findings('other', 'zone', ['dns_only' => ['mail.example.com (A)']]));
    }

    public function testDnsOnlyList(): void
    {
        $allowed = ['Mail.Example.com.', '*.dev.example.com'];
        $this->assertTrue(CloudflareConfig::isAllowed('mail.example.com', $allowed));
        $this->assertTrue(CloudflareConfig::isAllowed('MAIL.example.com.', $allowed));
        $this->assertTrue(CloudflareConfig::isAllowed('a.dev.example.com', $allowed));
        $this->assertTrue(CloudflareConfig::isAllowed('a.b.dev.example.com', $allowed));
        $this->assertTrue(CloudflareConfig::isAllowed('*.dev.example.com', $allowed), 'a wildcard record');
        $this->assertFalse(CloudflareConfig::isAllowed('dev.example.com', $allowed), 'the wildcard covers subdomains only');
        $this->assertFalse(CloudflareConfig::isAllowed('xdev.example.com', $allowed));
        $this->assertFalse(CloudflareConfig::isAllowed('mail.example.com', []));
        $this->assertSame('www.example.com', CloudflareConfig::hostname('WWW.example.com (CNAME)'));
        $this->assertSame('example.com', CloudflareConfig::hostname('example.com'));
    }

    public function testChanges(): void
    {
        $this->assertSame([], CloudflareConfig::changes('zone', [], self::GOOD), 'the first sync is the baseline');
        $this->assertSame([], CloudflareConfig::changes('zone', self::GOOD, []));
        $this->assertSame([], CloudflareConfig::changes('zone', self::GOOD, self::GOOD));
        $this->assertSame([], CloudflareConfig::changes('waf', self::GOOD, ['ssl' => 'off'] + self::GOOD));

        $now = ['ssl' => 'flexible', 'blocklist_rule' => 'disabled', 'dns_records' => 5,
            'proxied' => ['example.com (A)', 'new.example.com (A)'], 'dns_only' => ['www.example.com (CNAME)', 'added.example.com (AAAA)']] + self::GOOD;
        $this->assertSame([
            'SSL/TLS encryption mode' => ['Full (strict)', 'Flexible'],
            'Greywarden Blocklist rule' => ['Enabled', 'Disabled'],
            'DNS records' => ['4', '5'],
            'added.example.com (AAAA)' => [null, 'DNS only'],
            'new.example.com (A)' => [null, 'Proxied'],
            'www.example.com (CNAME)' => ['Proxied', 'DNS only'],
        ], CloudflareConfig::changes('zone', self::GOOD, $now));
        $this->assertSame(['example.com (A)' => ['Proxied', null], 'www.example.com (CNAME)' => ['Proxied', 'DNS only']],
            CloudflareConfig::changes('zone', self::GOOD, ['proxied' => [], 'dns_only' => ['www.example.com (CNAME)']] + self::GOOD), 'a record removed');

        // A setting read on one side only says nothing: the token's permissions changed, not the zone
        $narrow = array_diff_key(self::GOOD, array_flip(['ssl', 'proxied', 'dns_only'])) + ['not_read' => ['settings', 'dns']];
        $this->assertSame([], CloudflareConfig::changes('zone', self::GOOD, $narrow));
        $this->assertSame([], CloudflareConfig::changes('zone', $narrow, self::GOOD));
        $this->assertSame(['www.example.com (CNAME)' => ['Proxied', 'DNS only']],
            CloudConfig::changes('cloudflare', 'zone', ['proxied' => ['www.example.com (CNAME)']], ['dns_only' => ['www.example.com (CNAME)']]));
        $this->assertSame([], CloudConfig::changes('aws', 's3:bucket', ['encrypted' => true], ['encrypted' => false]), 'Cloudflare only');
    }
}
