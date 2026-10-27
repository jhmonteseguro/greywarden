<?php

namespace tests\unit;

use app\components\Rbac;
use app\models\User;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\intel\components\Http;
use app\modules\intel\components\Network;
use app\modules\intel\models\BlocklistEntry;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use tests\DbTestCase;
use tests\support\OutputCapture;
use Yii;
use yii\console\ExitCode;
use yii\db\Query;

/**
 * Console commands (php yii ...), run in-process with their output captured.
 */
class ConsoleCommandsTest extends DbTestCase
{
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::$fake = static fn(array $r) => ['status' => 404, 'body' => '', 'error' => '', 'time' => 0];
        Network::$fakeDns = static fn(string $name, string $type) => [];
        Network::$fakeWhois = static fn(string $server, string $query) => null;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
            if (str_contains(dirname($f), 'phpunit-snap-')) {
                @rmdir(dirname($f));
            }
        }
        Http::$fake = null;
        Network::$fakeDns = null;
        Network::$fakeWhois = null;
        parent::tearDown();
    }

    private function tmpFile(string $content, string $suffix = '.txt'): string
    {
        $file = Yii::getAlias('@runtime') . '/phpunit-' . bin2hex(random_bytes(4)) . $suffix;
        file_put_contents($file, $content);
        return $this->tmpFiles[] = $file;
    }

    // ---- seed/demo --------------------------------------------------------------

    public function testSeedLoadsDemoDataOnceAndOnlyResetsWhenAsked(): void
    {
        [$code, $out] = OutputCapture::run('seed/demo');
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('Seeded', $out);
        $risks = (int) Risk::find()->count();
        $this->assertGreaterThan(10, $risks);
        $this->assertGreaterThan(10, (int) Control::find()->count());
        $this->assertGreaterThan(5, (int) Decision::find()->count());
        $this->assertGreaterThan(10, (int) Incident::find()->count());
        $this->assertGreaterThan(20, (int) Device::find()->count());
        $this->assertGreaterThan(0, (int) BlocklistEntry::find()->count());
        $this->assertSame(Rbac::ROLE_VIEWER, User::findByUsername('viewer')->getRoleName());
        $this->assertNotNull(Risk::findOne(['reference' => 'RSK-0001']));
        // Intel demo data stays inside the documentation ranges
        foreach (BlocklistEntry::find()->select('value')->column() as $value) {
            $this->assertMatchesRegularExpression('/^(192\.0\.2\.|198\.51\.100\.|203\.0\.113\.|AS645\d\d|AS6449\d|2001:db8)/i', $value);
        }

        // Each incident's timeline walks the lifecycle in order: sorted by time, every status
        // change starts from the status the previous one ended in, and the last is the incident's status
        foreach (Incident::find()->all() as $incident) {
            $changes = (new Query())->select('body')->from('incident_timeline')
                ->where(['incident_id' => $incident->id, 'entry_type' => 'status'])
                ->andWhere(['like', 'body', 'Status changed from'])->orderBy(['entry_at' => SORT_ASC, 'id' => SORT_ASC])->column();
            $status = Incident::statusOptions()['new'];
            foreach ($changes as $body) {
                $this->assertMatchesRegularExpression('/^Status changed from (.+) to (.+)\.$/', $body);
                preg_match('/^Status changed from (.+) to (.+)\.$/', $body, $m);
                $this->assertSame($status, $m[1], "{$incident->reference}: \"{$body}\" is out of order");
                $status = $m[2];
            }
            $this->assertSame(Incident::statusOptions()[$incident->status], $status, $incident->reference);
        }

        // A second run refuses to touch existing data
        [$code, $out] = OutputCapture::run('seed/demo');
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('already exists', $out);
        $this->assertSame($risks, (int) Risk::find()->count());

        // --reset wipes and reseeds. Its AUTO_INCREMENT reset is DDL, which would commit the test
        // transaction, so it is skipped here by presenting the connection as a non-MySQL driver.
        $extra = new Risk(['title' => 'Not demo data', 'status' => Risk::STATUS_IDENTIFIED, 'inherent_likelihood' => 1, 'inherent_impact' => 1,
            'category_id' => $this->lookupId('risk_category'), 'owner_id' => $this->userId(), 'date_identified' => '2026-01-01']);
        $extra->save(false);
        $db = Yii::$app->db;
        $driver = $db->driverName;
        $db->driverName = 'phpunit';
        try {
            [$code, $out] = OutputCapture::run('seed/demo', ['reset' => 1]);
        } finally {
            $db->driverName = $driver;
        }
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('Existing domain data removed', $out);
        $this->assertNull(Risk::findOne($extra->id));
        $this->assertSame($risks, (int) Risk::find()->count());
    }

    // ---- user/* -----------------------------------------------------------------

    public function testUserCreateAdminResetPasswordAndList(): void
    {
        [$code, $out] = OutputCapture::run('user/create-admin', ['cli.admin', 'cli.admin@example.com', 'Cli!Admin-Password1']);
        $this->assertSame(ExitCode::OK, $code, $out);
        $user = User::findByUsername('cli.admin');
        $this->assertSame(Rbac::ROLE_ADMIN, $user->getRoleName());
        $this->assertTrue((bool) $user->must_change_password, 'new admins must change the password at first sign-in');
        $this->assertTrue($user->validatePassword('Cli!Admin-Password1'));

        // Existing user, non-interactive: reported, not changed
        [$code, $out] = OutputCapture::run('user/create-admin', ['cli.admin', 'x@example.com', 'Other!Password-123']);
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('already exists', $out);
        $this->assertTrue(User::findByUsername('cli.admin')->validatePassword('Cli!Admin-Password1'));

        // Weak password refused
        [$code, $out] = OutputCapture::run('user/create-admin', ['cli.weak', 'weak@example.com', 'short']);
        $this->assertSame(ExitCode::DATAERR, $code);
        $this->assertNull(User::findByUsername('cli.weak'));

        // Reset password clears the lockout and forces a change
        $user->updateAttributes(['must_change_password' => 0]);
        Yii::$app->db->createCommand()->insert('login_attempt', ['username' => 'cli.admin', 'ip' => '192.0.2.1', 'success' => 0, 'attempted_at' => date('Y-m-d H:i:s')])->execute();
        $oldKey = $user->auth_key;
        [$code, $out] = OutputCapture::run('user/reset-password', ['cli.admin', 'Reset!Password-456']);
        $this->assertSame(ExitCode::OK, $code, $out);
        $user->refresh();
        $this->assertTrue($user->validatePassword('Reset!Password-456'));
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertNotSame($oldKey, $user->auth_key, 'existing sessions are invalidated');
        $this->assertFalse((new Query())->from('login_attempt')->where(['username' => 'cli.admin', 'success' => 0])->exists());

        $this->assertSame(ExitCode::DATAERR, OutputCapture::run('user/reset-password', ['nobody', 'Reset!Password-456'])[0]);
        $this->assertSame(ExitCode::DATAERR, OutputCapture::run('user/reset-password', ['cli.admin', 'weak'])[0]);

        [$code, $out] = OutputCapture::run('user/list');
        $this->assertSame(ExitCode::OK, $code);
        $this->assertMatchesRegularExpression('/cli\.admin\s+Admin\s+active/', $out);
    }

    // ---- device/import ----------------------------------------------------------

    public function testDeviceImportDryRunAndRealRun(): void
    {
        $csv = "Device Name,Name,Email,Ownership,Type,Model,First Sync,Last Sync,Status,Device ID,Serial Number (mandatory),IMEI / MEID,OS,User Agent,Host Name\n"
            . "Cli's Mac OS,Cli Example,cli@example.com,Company owned,Mac,Mac OS,\"August 24, 2026 at 4:41 AM GMT-7\",\"October 19, 2026 at 12:22 AM GMT-7\",Approved,cli-0001,,,macOS 14.1,,\n";
        $file = $this->tmpFile($csv, '.csv');

        [$code, $out] = OutputCapture::run('device/import', [$file, 'dryRun' => 1]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('Dry run (nothing saved): 1 created', $out);
        $this->assertFalse(Device::find()->where(['source_id' => 'cli-0001'])->exists());

        $actor = User::findOne($this->userId());
        [$code, $out] = OutputCapture::run('device/import', [$file, 'user' => $actor->username]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $device = Device::findOne(['source_id' => 'cli-0001']);
        $this->assertNotNull($device);
        $this->assertSame($actor->id, (int) $device->created_by);

        // Re-import of the same export changes nothing
        [$code, $out] = OutputCapture::run('device/import', [$file]);
        $this->assertStringContainsString('0 created, 0 updated, 1 unchanged', $out);
    }

    public function testDeviceImportErrors(): void
    {
        $this->assertSame(ExitCode::NOINPUT, OutputCapture::run('device/import', ['/nonexistent/devices.csv'])[0]);
        $this->assertSame(ExitCode::DATAERR, OutputCapture::run('device/import', [$this->tmpFile("not,a,google,export\n1,2,3,4\n")])[0]);

        $csv = "Device Name,Name,Email,Ownership,Type,Model,First Sync,Last Sync,Status,Device ID,Serial Number (mandatory),IMEI / MEID,OS,User Agent,Host Name\n"
            . "X,Y,y@example.com,Company owned,Mac,Mac OS,,,Approved,cli-0002,,,macOS 14.1,,\n";
        [$code, $out] = OutputCapture::run('device/import', [$this->tmpFile($csv), 'user' => 'no-such-user']);
        $this->assertSame(ExitCode::DATAERR, $code);
        $this->assertStringContainsString("User 'no-such-user' not found", $out);
    }

    // ---- intel/* ----------------------------------------------------------------

    public function testIntelStatusUpdateAndLookupStayOffline(): void
    {
        [$code, $out] = OutputCapture::run('intel/status');
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('never downloaded', $out);
        $this->assertStringContainsString('Stale:', $out);

        // Every (faked) download fails: reported per feed, exit code UNAVAILABLE
        [$code, $out] = OutputCapture::run('intel/update', ['force' => 1]);
        $this->assertSame(ExitCode::UNAVAILABLE, $code, $out);
        $this->assertStringContainsString('FAILED', $out);
        [$code, $out] = OutputCapture::run('intel/status');
        $this->assertStringContainsString('ERROR', $out);

        [$code, $out] = OutputCapture::run('intel/lookup', ['203.0.113.7']);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('203.0.113.7', $out);
        $this->assertStringContainsString('sources in', $out);
        [$code] = OutputCapture::run('intel/lookup', ['AS64500', 'refresh' => 1]);
        $this->assertSame(ExitCode::OK, $code);
        $this->assertSame(ExitCode::DATAERR, OutputCapture::run('intel/lookup', ['not-an-ip'])[0]);
    }

    public function testIntelUpdateStoresFeedsWhenDownloadsSucceed(): void
    {
        Http::$fake = static fn(array $r) => ['status' => 200, 'body' => "# comment\n203.0.113.5\n198.51.100.0/24\n", 'error' => '', 'time' => 0.01];
        [$code, $out] = OutputCapture::run('intel/update', ['force' => 1]);
        $this->assertStringContainsString('entries', $out);
        $this->assertGreaterThan(0, (int) (new Query())->from('intel_feed_entry')->count());
        $this->assertContains($code, [ExitCode::OK, ExitCode::UNAVAILABLE], 'JSON / CSV feeds cannot parse a plain list');
    }

    // ---- attack/* ---------------------------------------------------------------

    public function testAttackStatusImportAndUpdateFailures(): void
    {
        [$code, $out] = OutputCapture::run('attack/status');
        $this->assertSame(ExitCode::OK, $code);
        $this->assertMatchesRegularExpression('/v\d+(\.\d+)? \(released/', $out);
        $this->assertStringContainsString('techniques', $out);

        // A raw STIX bundle from disk
        $bundle = ['type' => 'bundle', 'objects' => [
            ['type' => 'x-mitre-collection', 'id' => 'x-mitre-collection--1', 'x_mitre_version' => '99.9', 'modified' => '2026-10-01T00:00:00.000Z'],
            ['type' => 'x-mitre-matrix', 'id' => 'x-mitre-matrix--1', 'tactic_refs' => ['x-mitre-tactic--ia'],
                'external_references' => [['source_name' => 'mitre-attack', 'external_id' => 'enterprise-attack']]],
            ['type' => 'x-mitre-tactic', 'id' => 'x-mitre-tactic--ia', 'name' => 'Initial Access', 'x_mitre_shortname' => 'initial-access',
                'external_references' => [['source_name' => 'mitre-attack', 'external_id' => 'TA9001']]],
            ['type' => 'attack-pattern', 'id' => 'attack-pattern--p', 'name' => 'Cli Technique',
                'kill_chain_phases' => [['kill_chain_name' => 'mitre-attack', 'phase_name' => 'initial-access']],
                'external_references' => [['source_name' => 'mitre-attack', 'external_id' => 'T9901']]],
        ]];
        [$code, $out] = OutputCapture::run('attack/import', [$this->tmpFile(json_encode($bundle), '.json')]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('v99.9', $out);
        $this->assertStringContainsString('flagged deprecated', $out);
        [, $out] = OutputCapture::run('attack/status');
        $this->assertStringContainsString('v99.9', $out);

        // The bundled snapshot (default) restores the real release
        [$code, $out] = OutputCapture::run('attack/import');
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('(was v99.9)', $out);

        $this->assertSame(ExitCode::DATAERR, OutputCapture::run('attack/import', [$this->tmpFile('{"type": "bundle", broken', '.json')])[0]);
        // attack/update only for its failure paths: a successful run rewrites the bundled snapshot in data/attack
        $this->assertSame(ExitCode::UNAVAILABLE, OutputCapture::run('attack/update', ['url' => '/nonexistent/enterprise-attack.json'])[0]);
        $this->assertSame(ExitCode::DATAERR, OutputCapture::run('attack/update', ['url' => $this->tmpFile('not json')])[0]);
    }

    public function testAttackSnapshotRoundTripAndBadFiles(): void
    {
        $catalogue = ['domain' => 'enterprise-attack', 'version' => '99.9', 'tactics' => [], 'techniques' => [], 'mitigations' => [], 'mitigates' => []];
        foreach (['.json', '.json.gz'] as $suffix) {
            $file = Yii::getAlias('@runtime') . '/phpunit-snap-' . bin2hex(random_bytes(4)) . '/attack' . $suffix;
            $this->tmpFiles[] = $file;
            $this->assertSame($file, \app\modules\attack\components\CatalogueImporter::writeSnapshot($catalogue, $file));
            $this->assertSame('99.9', \app\modules\attack\components\CatalogueImporter::readSnapshot($file)['version']);
        }
        $this->expectExceptionMessage('is not a Greywarden ATT&CK snapshot');
        \app\modules\attack\components\CatalogueImporter::readSnapshot($this->tmpFile('{"hello": 1}', '.json'));
    }

    public function testAttackStatusWithoutCatalogue(): void
    {
        Yii::$app->db->createCommand()->delete('attack_release')->execute();
        [$code, $out] = OutputCapture::run('attack/status');
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('No ATT&CK catalogue loaded', $out);
    }
}
