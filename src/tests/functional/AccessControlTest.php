<?php

namespace tests\functional;

use PHPUnit\Framework\Attributes\DataProvider;
use tests\FunctionalTestCase;
use yii\db\Query;

/**
 * Every page × every role against the demo data (the in-process counterpart of
 * scripts/smoke-test.sh's page matrix): read pages are open to all roles, write
 * pages need records.manage (analyst+), admin pages need admin, guests are sent
 * to the sign-in page. Each page must render without a PHP error.
 */
class AccessControlTest extends FunctionalTestCase
{
    protected static bool $seed = true;

    private function id(string $table, string $column, string $value): int
    {
        $id = (new Query())->select('id')->from($table)->where([$column => $value])->scalar();
        $this->assertNotFalse($id, "$table $value missing from the demo data");
        return (int) $id;
    }

    private function readPages(): array
    {
        $r1 = $this->id('risk', 'reference', 'RSK-0001');
        $r5 = $this->id('risk', 'reference', 'RSK-0005');
        $d1 = $this->id('decision', 'reference', 'DEC-0001');
        $d7 = $this->id('decision', 'reference', 'DEC-0007');
        $i1 = $this->id('incident', 'reference', 'INC-0001');
        $i19 = $this->id('incident', 'reference', 'INC-0019');
        $v1 = $this->id('device', 'asset_tag', 'DEV-0001');
        $v38 = $this->id('device', 'asset_tag', 'DEV-0038');
        $c1 = $this->id('control', 'reference', 'CTL-0001');
        $c5 = $this->id('control', 'reference', 'CTL-0005');
        $b1 = $this->id('intel_blocklist', 'reference', 'BLK-0001');
        $req = $this->id('framework_requirement', 'external_id', 'A.8.7');
        $ce = $this->id('framework_requirement', 'external_id', 'CE-UA-4');
        $month = date('Y-m');

        return [
            '/', '/?heatmap=inherent', '/search?q=RSK', '/search?q=phishing', '/search?q=203.0.113.66', '/search?q=AS64511', '/search?q=bulletproof',
            '/search?q=', '/account/profile', '/account/password',
            '/risks', '/risks?filter=all', '/risks?filter=mine', '/risks?filter=review-overdue', '/risks?filter=treatment-overdue',
            '/risks?filter=archived', '/risks?filter=control-issues', '/risks?filter=all&sort=-residual_rating&status=in_treatment&q=a',
            '/risks?filter=open&residual_likelihood=2&residual_impact=4', '/risks?filter=all&technique=phishing',
            '/risks/heatmap', '/risks/heatmap?heatmap=inherent', '/risks/heatmap?heatmap=target', "/risks/$r1", "/risks/$r5",
            '/decisions', '/decisions?filter=proposed', '/decisions?filter=review-overdue', '/decisions?filter=mine',
            '/decisions?sort=-decision_date&status=approved', '/decisions?mitigation=M1032', "/decisions/$d1", "/decisions/$d7",
            '/incidents', '/incidents?filter=all', '/incidents?filter=mine', '/incidents?filter=regulator-overdue', '/incidents?filter=regulator-pending',
            '/incidents?filter=all&sort=-severity', "/incidents?filter=all&month=$month", '/incidents?filter=all&technique=T1566',
            "/incidents/$i1", "/incidents/$i19",
            '/devices', '/devices?filter=active', '/devices?filter=mine', '/devices?filter=non-compliant', '/devices?filter=not-assessed',
            '/devices?filter=warranty', '/devices?filter=eol', '/devices?filter=stale', '/devices?filter=byod', '/devices?filter=archived',
            "/devices?filter=active&control=$c5&result=fail", "/devices?filter=active&control=$c1&result=unknown",
            '/devices?filter=all&sort=-criticality&device_type=laptop', "/devices/$v1", "/devices/$v38",
            '/controls', '/controls?filter=mine', '/controls?filter=planned', '/controls?filter=implemented', '/controls?filter=test-due-soon',
            '/controls?filter=test-overdue', '/controls?filter=weak', '/controls?filter=not-tested', '/controls?filter=target-overdue',
            '/controls?filter=device-checks', '/controls?filter=retired', '/controls?filter=archived', '/controls?requirement=A.8.7',
            '/controls?mitigation=M1032', '/controls?sort=-next_test_due&effectiveness=partial', '/controls?sort=effectiveness&q=backup',
            "/controls/$c1", "/controls/$c5",
            '/frameworks', '/frameworks/iso27001', '/frameworks/cyber-essentials', '/frameworks/iso27001?state=gap',
            '/frameworks/cyber-essentials?state=met', "/frameworks/requirement/$req", "/frameworks/requirement/$ce",
            '/frameworks/soc2', '/frameworks/soc2?state=gap',
            '/attack', '/attack?layer=risks', '/attack?show=mapped', '/attack?layer=risks&show=mapped', '/attack/T1566', '/attack/T1566.001',
            '/attack/mitigations', '/attack/mitigations/M1032',
            '/intel', '/intel?sort=-last_score', '/intel/ip/203.0.113.66', '/intel/ip/198.51.100.23', '/intel/ip/10.20.30.40',
            '/intel/ip/fd00::5', '/intel/asn/AS64511', '/intel/blocklist', '/intel/blocklist?kind=asn', '/intel/blocklist?filter=expired',
            '/intel/blocklist?filter=unblocked', '/intel/blocklist?filter=all&sort=-value&severity=critical', '/intel/blocklist?filter=expiring&q=DDoS',
            "/intel/blocklist/$b1", '/intel/sources',
        ];
    }

    private function writePages(): array
    {
        $r1 = $this->id('risk', 'reference', 'RSK-0001');
        $c1 = $this->id('control', 'reference', 'CTL-0001');
        $d1 = $this->id('decision', 'reference', 'DEC-0001');
        $i1 = $this->id('incident', 'reference', 'INC-0001');
        $v1 = $this->id('device', 'asset_tag', 'DEV-0001');
        $b1 = $this->id('intel_blocklist', 'reference', 'BLK-0001');
        return [
            '/risks/create', "/risks/$r1/update", '/controls/create', "/controls/$c1/update", '/decisions/create', "/decisions/$d1/update",
            '/incidents/create', "/incidents/$i1/update", '/devices/create', "/devices/$v1/update",
            '/intel/blocklist/create', '/intel/blocklist/create?value=192.0.2.99', "/intel/blocklist/$b1/update",
        ];
    }

    private function adminPages(): array
    {
        $viewer = $this->userIdFor('viewer');
        return [
            '/admin/user', "/admin/user/$viewer", '/admin/user/create', "/admin/user/$viewer/update", "/admin/user/$viewer/reset-password",
            '/admin/user?role=analyst&q=fn', '/admin/audit', '/admin/audit?entity_type=risk&action=update', '/admin/audit?action=login_failed',
            '/admin/audit/1', '/admin/lookup', '/admin/lookup?type=department', '/admin/lookup?type=nope', '/admin/lookup/create',
            '/admin/lookup/create?type=location', '/admin/lookup/1/update', '/admin/settings',
        ];
    }

    /** @return string[] failures */
    private function sweep(array $pages, int $expected): array
    {
        $failures = [];
        foreach ($pages as $page) {
            $this->get($page);
            if ($this->status !== $expected) {
                $failures[] = "$page -> {$this->status} (expected $expected)" . ($this->exception ? ': ' . $this->exception->getMessage() : '');
            }
        }
        return $failures;
    }

    public static function roles(): array
    {
        return ['admin' => ['admin'], 'analyst' => ['analyst'], 'viewer' => ['viewer']];
    }

    #[DataProvider('roles')]
    public function testPageMatrix(string $role): void
    {
        $this->loginAs($role);
        $failures = array_merge(
            $this->sweep($this->readPages(), 200),
            $this->sweep($this->writePages(), $role === 'viewer' ? 403 : 200),
            $this->sweep($this->adminPages(), $role === 'admin' ? 200 : 403),
        );
        $this->assertSame([], $failures);
    }

    public function testGuestsAreSentToSignIn(): void
    {
        foreach (['/', '/risks', '/controls', '/intel/ip/203.0.113.66', '/admin/user', '/account/profile', '/attachments/1'] as $page) {
            $this->get($page);
            $this->assertRedirect('/login');
        }
        $this->get('/login');
        $this->assertOk();
        $this->assertSee('LoginForm[username]');
    }

    public function testViewerCannotPostWrites(): void
    {
        $this->loginAs('viewer');
        $i1 = $this->id('incident', 'reference', 'INC-0001');
        $r1 = $this->id('risk', 'reference', 'RSK-0001');
        $c1 = $this->id('control', 'reference', 'CTL-0001');
        $req = $this->id('framework_requirement', 'external_id', 'A.8.7');
        foreach ([
            '/risks/create' => ['Risk' => ['title' => 'nope']],
            "/risks/$r1/archive" => [],
            "/incidents/$i1/add-entry" => ['IncidentTimeline' => ['body' => 'nope']],
            "/controls/$c1/record-test" => ['ControlTest' => ['result' => 'effective']],
            "/frameworks/requirement/$req" => ['FrameworkRequirement' => ['applicable' => 0]],
            '/intel/note' => ['indicator' => '192.0.2.99', 'IntelNote' => ['body' => 'nope']],
            '/intel/verdict' => ['indicator' => '192.0.2.99', 'verdict' => 'benign'],
            '/intel/sources/update' => [],
            "/attachments/upload?entity=risk&id=$r1" => [],
        ] as $url => $data) {
            $this->post($url, $data);
            $this->assertStatus(403, $url);
        }
    }

    public function testOnlyAdminsDeleteRecords(): void
    {
        $this->loginAs('analyst');
        $r1 = $this->id('risk', 'reference', 'RSK-0001');
        $this->post("/risks/$r1/delete");
        $this->assertStatus(403);
        $this->assertTrue((new Query())->from('risk')->where(['id' => $r1])->exists());
    }

    public function testWriteActionsRequirePost(): void
    {
        $this->loginAs('admin');
        $r1 = $this->id('risk', 'reference', 'RSK-0001');
        foreach (["/risks/$r1/archive", "/risks/$r1/restore", "/risks/$r1/delete", '/logout', '/intel/note'] as $url) {
            $this->get($url);
            $this->assertStatus(405, $url);
        }
    }

    public function testMissingRecordsAre404(): void
    {
        $this->loginAs('viewer');
        foreach (['/risks/999999', '/controls/999999', '/decisions/999999', '/incidents/999999', '/devices/999999', '/intel/blocklist/999999',
                     '/frameworks/pci-dss', '/frameworks/requirement/999999', '/attack/T9999', '/attack/mitigations/M9999',
                     '/intel/ip/999.1.1.1', '/intel/ip/192.0.2.099', '/intel/asn/AS99999999999', '/intel/indicator/999999',
                     '/attachments/999999', '/no-such-page'] as $url) {
            $this->get($url);
            $this->assertStatus(404, $url);
        }
        $this->loginAs('admin');
        foreach (['/admin/user/999999', '/admin/audit/999999', '/admin/lookup/999999/update'] as $url) {
            $this->get($url);
            $this->assertStatus(404, $url);
        }
    }

    public function testViewerSeesNoWriteControls(): void
    {
        $this->loginAs('viewer');
        $this->get('/intel/ip/203.0.113.66');
        $this->assertOk();
        $this->assertNotSee('gw-block-modal');
        $this->get('/risks');
        $this->assertNotSee('/risks/create');

        $this->loginAs('analyst');
        $this->get('/intel/ip/203.0.113.66');
        $this->assertSee('gw-block-modal');
        $this->get('/risks');
        $this->assertSee('/risks/create');
    }
}
