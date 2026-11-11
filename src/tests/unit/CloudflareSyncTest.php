<?php

namespace tests\unit;

use app\components\Actor;
use app\models\AuditLog;
use app\models\User;
use app\modules\admin\models\EdgeForm;
use app\modules\cloud\components\CloudflareZones;
use app\modules\cloud\components\CloudImporter;
use app\modules\cloud\models\CloudAsset;
use app\modules\intel\components\Cloudflare;
use app\modules\intel\models\BlocklistEntry;
use RuntimeException;
use tests\DbTestCase;
use tests\support\FakeCloudflare;
use tests\support\OutputCapture;
use Yii;
use yii\console\ExitCode;

/**
 * The Cloudflare zone sync reading each zone's configuration (settings, DNS
 * records, DNSSEC, the Greywarden Blocklist rule) against a fake API: the
 * properties, permissions it lacks, findings, the "Configuration changed"
 * History entry, the DNS-only setting and the cloud/sync-cloudflare output.
 */
class CloudflareSyncTest extends DbTestCase
{
    private FakeCloudflare $cf;

    protected function setUp(): void
    {
        parent::setUp();
        Yii::$app->cache->flush();
        $this->cf = (new FakeCloudflare())->install();
        CloudflareZones::$warnings = [];
    }

    protected function tearDown(): void
    {
        FakeCloudflare::uninstall();
        Actor::override(null);
        Yii::$app->cache->flush();
        parent::tearDown();
    }

    private static function denied(): array
    {
        return ['status' => 403, 'body' => json_encode(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]])];
    }

    /** @return array the one result of a sync */
    private function sync(): array
    {
        return (new CloudImporter(date('Y-m-d H:i:s')))->import(CloudflareZones::records(), 'Synced.')[0];
    }

    public function testConfiguration(): void
    {
        $records = CloudflareZones::records();
        $this->assertCount(1, $records);
        $this->assertSame([
            'paused' => false, 'ssl' => 'strict', 'min_tls' => '1.2', 'always_use_https' => true, 'hsts' => true, 'security_level' => 'medium',
            'dnssec' => 'active', 'blocklist_rule' => 'enabled', 'dns_records' => 4,
            'proxied' => ['example.com (A)', 'www.example.com (CNAME)'], 'dns_only' => ['mail.example.com (A)'],
        ], json_decode($records[0]['attributes']['properties'], true));
        $this->assertSame([], CloudflareZones::$warnings);
        $this->assertNull(CloudflareZones::records(false)[0]['attributes']['properties'], 'zones only');

        // DNS records over several pages; round-robin names are DNS only if any record is; no proxiable flag = by type
        $this->cf->dnsRecords = array_merge(
            array_map(static fn($i) => ['name' => "h$i.example.com", 'type' => 'A', 'proxied' => true], range(1, 150)),
            [['name' => 'rr.example.com', 'type' => 'A', 'proxied' => true], ['name' => 'rr.example.com', 'type' => 'A', 'proxied' => false],
                ['name' => 'Txt.example.com', 'type' => 'TXT', 'proxied' => false], ['name' => '', 'type' => 'A', 'proxied' => false]]);
        $before = count($this->cf->requests('GET', '/dns_records'));
        $p = CloudflareZones::configuration(['id' => FakeCloudflare::ZONE, 'name' => 'example.com']);
        $this->assertSame([154, 150, ['rr.example.com (A)']], [$p['dns_records'], count($p['proxied']), $p['dns_only']]);
        $this->assertCount($before + 2, $this->cf->requests('GET', '/dns_records'), 'two pages of 100');

        // Odd settings are left out rather than guessed
        $this->cf->settings = ['ssl' => ['x'], 'always_use_https' => 'off', 'security_header' => 'x', 'security_level' => 'under_attack'];
        $p = CloudflareZones::configuration(['id' => FakeCloudflare::ZONE, 'name' => 'example.com', 'paused' => true]);
        $this->assertSame([true, false, 'under attack'], [$p['paused'], $p['always_use_https'], $p['security_level']]);
        $this->assertArrayNotHasKey('ssl', $p);
        $this->assertArrayNotHasKey('hsts', $p);
    }

    public function testBlocklistRule(): void
    {
        // Another zone: the rule is only read in CLOUDFLARE_ZONE_ID
        $this->assertArrayNotHasKey('blocklist_rule', CloudflareZones::configuration(['id' => 'zone2', 'name' => 'other.example']) ?? []);
        $this->assertSame('enabled', Cloudflare::ruleState());
        $this->cf->rules[1]['enabled'] = false;
        $this->assertSame('disabled', CloudflareZones::configuration(['id' => FakeCloudflare::ZONE, 'name' => 'example.com'])['blocklist_rule']);

        // Missing: only a finding once something has been blocked through it
        $this->cf->rules = [];
        $this->assertSame('missing', Cloudflare::ruleState());
        $this->assertArrayNotHasKey('blocklist_rule', CloudflareZones::configuration(['id' => FakeCloudflare::ZONE, 'name' => 'example.com']));
        Actor::override($this->userId(), 'tester');
        $entry = new BlocklistEntry(['value' => '192.0.2.77', 'severity' => 'high', 'direction' => 'inbound', 'reason' => 't']);
        $this->assertTrue($entry->save());
        BlocklistEntry::updateAll(['cloudflare_added_at' => date('Y-m-d H:i:s')], ['id' => $entry->id]);
        $this->assertSame('missing', CloudflareZones::configuration(['id' => FakeCloudflare::ZONE, 'name' => 'example.com'])['blocklist_rule']);

        // A token without Zone WAF Read
        $this->cf->failWith = self::denied();
        $this->assertSame(['settings', 'dns', 'dnssec', 'rules'], CloudflareZones::configuration(['id' => FakeCloudflare::ZONE, 'name' => 'example.com'])['not_read']);
        putenv('CLOUDFLARE_ZONE_ID');
        $this->expectException(RuntimeException::class);
        Cloudflare::ruleState();
    }

    public function testNarrowToken(): void
    {
        $this->cf->failZoneWith = ['settings' => self::denied(), 'dns_records' => self::denied(), 'dnssec' => self::denied()];
        $p = CloudflareZones::configuration(['id' => FakeCloudflare::ZONE, 'name' => 'example.com']);
        $this->assertSame(['paused' => false, 'blocklist_rule' => 'enabled', 'not_read' => ['settings', 'dns', 'dnssec']], $p);
        $this->assertSame([], CloudflareZones::$warnings);

        foreach (['Cloudflare: Unauthorized to access requested resource (9109)', 'Cloudflare: HTTP 403', 'Cloudflare: Authentication error (10000)'] as $message) {
            $this->assertTrue(CloudflareZones::isPermissionError($message), $message);
        }
        $this->assertFalse(CloudflareZones::isPermissionError('Cloudflare: Internal (1000)'));
        $this->assertFalse(CloudflareZones::isPermissionError('timeout'));
    }

    public function testSyncFindingsAndHistory(): void
    {
        Actor::override($this->userId(), 'tester');
        $first = $this->sync();
        $zone = $first['asset'];
        $this->assertSame(['created', 'Cloudflare zone', 'zone'], [$first['action'], $zone->getKindLabel(), $zone->provider_type]);
        $this->assertSame(['high', 1], [$zone->finding_severity, $zone->finding_count]);
        $this->assertSame('mail.example.com (A) is DNS only: its traffic skips Cloudflare (WAF, blocklist, DDoS protection)', $zone->getFindings()[0]['title']);
        $this->assertContains(['Proxied records', 'example.com (A), www.example.com (CNAME)'], $zone->getConfigFacts());

        // Nothing changed: unchanged, even though the seen time moved, and no History entry for it
        $entries = AuditLog::find()->where(['entity_type' => 'cloud_asset', 'entity_id' => $zone->id])->count();
        $this->assertSame('unchanged', $this->sync()['action']);
        $later = date('Y-m-d H:i:s', time() + 3600);
        $this->assertSame('unchanged', (new CloudImporter($later))->import(CloudflareZones::records(), 'Synced.')[0]['action']);
        $this->assertSame($later, CloudAsset::findOne($zone->id)->last_seen_at, 'the seen time is kept');
        $this->assertSame($entries, AuditLog::find()->where(['entity_type' => 'cloud_asset', 'entity_id' => $zone->id])->count());

        // www switched to DNS only, SSL weakened, a record added
        $this->cf->dnsRecords[1]['proxied'] = false;
        $this->cf->dnsRecords[] = ['name' => 'new.example.com', 'type' => 'AAAA', 'proxied' => true, 'proxiable' => true];
        $this->cf->settings['ssl'] = 'flexible';
        $r = $this->sync();
        $this->assertSame('updated', $r['action']);
        $this->assertSame([
            'SSL/TLS encryption mode' => ['Full (strict)', 'Flexible'],
            'DNS records' => ['4', '5'],
            'new.example.com (AAAA)' => [null, 'Proxied'],
            'www.example.com (CNAME)' => ['Proxied', 'DNS only'],
        ], $r['config_changes']);
        $zone = CloudAsset::findOne($zone->id);
        $this->assertSame([3, 'high'], [$zone->finding_count, $zone->finding_severity]);

        $log = AuditLog::find()->where(['entity_type' => 'cloud_asset', 'entity_id' => $zone->id, 'action' => 'config_change'])->one();
        $this->assertSame('Configuration changed', $log->getActionLabel());
        $this->assertSame([
            ['attribute' => 'SSL/TLS encryption mode', 'field' => 'SSL/TLS encryption mode', 'old' => 'Full (strict)', 'new' => 'Flexible'],
            ['attribute' => 'DNS records', 'field' => 'DNS records', 'old' => '4', 'new' => '5'],
            ['attribute' => 'new.example.com (AAAA)', 'field' => 'new.example.com (AAAA)', 'old' => null, 'new' => 'Proxied'],
            ['attribute' => 'www.example.com (CNAME)', 'field' => 'www.example.com (CNAME)', 'old' => 'Proxied', 'new' => 'DNS only'],
        ], $log->getFormattedChanges(), 'record names are shown as they are, not as attribute labels');
        $update = AuditLog::find()->where(['entity_type' => 'cloud_asset', 'entity_id' => $zone->id, 'action' => 'update'])->orderBy(['id' => SORT_DESC])->one();
        $this->assertArrayNotHasKey('last_seen_at', $update->getChangesArray());
        $this->assertSame(['1', '3'], array_map('strval', $update->getChangesArray()['finding_count']));

        // Permissions taken away: findings say so, the History records no change
        $this->cf->failZoneWith = ['dns_records' => self::denied()];
        $r = $this->sync();
        $this->assertSame([], $r['config_changes']);
        $this->assertContains('Could not read the DNS records: give the API token Zone › DNS › Read', array_column(CloudAsset::findOne($zone->id)->getFindings(), 'title'));

        // A failing read keeps the configuration as it was
        $this->cf->failZoneWith = ['settings' => ['status' => 502, 'body' => 'Bad gateway']];
        $this->assertSame('unchanged', $this->sync()['action']);
        $this->assertCount(1, CloudflareZones::$warnings);
        $this->assertStringStartsWith('example.com: zone settings (SSL/TLS, HTTPS, HSTS) not read: Cloudflare: ', CloudflareZones::$warnings[0]);
    }

    public function testDnsOnlySetting(): void
    {
        $this->sync();
        $zone = CloudAsset::findOne(['source' => 'cloudflare']);
        $this->assertSame(1, (int) $zone->finding_count);

        Actor::override($this->userId(), 'tester');
        $form = EdgeForm::current();
        $form->dns_only = "not a host!\n*.bad*";
        $this->assertFalse($form->validate());
        $this->assertSame('Not a hostname: not a host!, *.bad*.', $form->getFirstError('dns_only'));

        // Saving the list refreshes the zones' findings straight away
        $form->dns_only = "MAIL.example.com   # MX target\n*.internal.example.com";
        $this->assertTrue($form->save());
        $this->assertSame(['mail.example.com', '*.internal.example.com'], \app\modules\intel\components\edge\EdgeSettings::dnsOnly());
        $zone = CloudAsset::findOne($zone->id);
        $this->assertSame([0, null], [(int) $zone->finding_count, $zone->finding_severity]);
        $this->assertSame(0, CloudflareZones::refreshFindings(), 'nothing left to refresh');
        $this->assertSame('unchanged', $this->sync()['action'], 'the next sync agrees');

        // Saving other edge settings leaves the zones alone
        $form = EdgeForm::current();
        $form->min_score = 40;
        $this->assertTrue($form->save());
    }

    public function testSyncCommand(): void
    {
        $user = User::findOne($this->userId())->username;
        [$code, $out] = OutputCapture::run('cloud/sync-cloudflare', ['user' => $user]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('[1 finding(s), worst high]', $out);

        $this->cf->dnsRecords[0]['proxied'] = false;
        $this->cf->failZoneWith = [];
        [$code, $out] = OutputCapture::run('cloud/sync-cloudflare', ['user' => $user]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('example.com (A): Proxied → DNS only', $out);

        $this->cf->failZoneWith = ['dnssec' => ['status' => 500, 'body' => json_encode(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Internal']]])]];
        [, $out] = OutputCapture::run('cloud/sync-cloudflare', ['user' => $user]);
        $this->assertStringContainsString('Warning: example.com: DNSSEC not read: Cloudflare: Internal (1000)', $out);
        $this->assertStringContainsString('0 updated, 1 unchanged', $out);
    }
}
