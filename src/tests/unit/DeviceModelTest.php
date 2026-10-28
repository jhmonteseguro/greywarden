<?php

namespace tests\unit;

use app\models\AuditLog;
use app\modules\control\models\Control;
use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\components\OsLifecycle;
use app\modules\inventory\models\Device;
use tests\DbTestCase;

class DeviceModelTest extends DbTestCase
{
    private function makeDevice(array $attrs = []): Device
    {
        return new Device($attrs + [
            'hostname' => 'lt-test-001',
            'device_type' => 'laptop',
            'os' => 'Ubuntu', // supported until 2029, so the supported-OS check passes
            'os_version' => '24.04 LTS',
            'status' => 'active',
            'criticality' => 'medium',
            'data_classification' => 'internal',
        ]);
    }

    public function testIpAndMacValidationAndNormalisation(): void
    {
        $device = $this->makeDevice(['ip_addresses' => "10.0.0.5,  fe80::1\n192.168.1.20", 'mac_address' => '00-1a-2b-3c-4d-5e']);
        $this->assertTrue($device->validate(), json_encode($device->getErrors()));
        $this->assertSame('10.0.0.5, fe80::1, 192.168.1.20', $device->ip_addresses);
        $this->assertSame('00:1A:2B:3C:4D:5E', $device->mac_address);

        $bad = $this->makeDevice(['ip_addresses' => '10.0.0.256', 'mac_address' => '00:1A:2B:3C:4D']);
        $this->assertFalse($bad->validate());
        $this->assertArrayHasKey('ip_addresses', $bad->getErrors());
        $this->assertArrayHasKey('mac_address', $bad->getErrors());
    }

    public function testAssetTagIsGeneratedOrOverridable(): void
    {
        $auto = $this->makeDevice();
        $this->assertTrue($auto->save(), json_encode($auto->getErrors()));
        $this->assertMatchesRegularExpression('/^DEV-\d{4,}$/', $auto->asset_tag);

        $custom = $this->makeDevice(['asset_tag' => 'laptop-js-01', 'hostname' => 'lt-test-002']);
        $this->assertTrue($custom->save(), json_encode($custom->getErrors()));
        $this->assertSame('LAPTOP-JS-01', $custom->asset_tag);

        $dupe = $this->makeDevice(['asset_tag' => 'LAPTOP-JS-01', 'hostname' => 'lt-test-003']);
        $this->assertFalse($dupe->validate());
        $this->assertArrayHasKey('asset_tag', $dupe->getErrors());
    }

    public function testNameDefaultsToHostnameAndHostnameIsOptional(): void
    {
        $server = $this->makeDevice(['hostname' => 'srv-dc1-101']);
        $this->assertTrue($server->save(), json_encode($server->getErrors()));
        $this->assertSame('srv-dc1-101', $server->name);
        $this->assertSame('srv-dc1-101', $server->getTitleValue());
        $this->assertSame(Device::OWNERSHIP_COMPANY, $server->ownership);
        $this->assertFalse($server->isByod());

        $phone = $this->makeDevice(['hostname' => '', 'name' => "Jane's iPhone 15", 'device_type' => 'mobile', 'ownership' => Device::OWNERSHIP_BYOD]);
        $this->assertTrue($phone->save(), json_encode($phone->getErrors()));
        $this->assertNull($phone->hostname);
        $this->assertTrue($phone->isByod());
        $this->assertSame("Jane's iPhone 15", Device::findOne($phone->id)->getTitleValue());

        $neither = $this->makeDevice(['hostname' => ' ', 'name' => '']);
        $this->assertFalse($neither->validate());
        $this->assertArrayHasKey('name', $neither->getErrors());

        $badOwnership = $this->makeDevice(['ownership' => 'leased']);
        $this->assertFalse($badOwnership->validate());
        $this->assertArrayHasKey('ownership', $badOwnership->getErrors());
    }

    /** Every manual check in force set to $result */
    private static function allChecks(string $result): array
    {
        return array_fill_keys(array_keys(DeviceChecks::manual()), $result);
    }

    /** A device-check control in force */
    private function makeCheck(array $attrs): Control
    {
        $control = new Control($attrs + [
            'title' => 'Test check',
            'status' => Control::STATUS_IMPLEMENTED,
            'control_type' => 'preventive',
            'nature' => 'technical',
            'device_check' => Control::CHECK_MANUAL,
        ]);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        return $control;
    }

    public function testPostureFollowsTheDeviceCheckControls(): void
    {
        $this->assertNotEmpty(DeviceChecks::manual(), 'the posture migration creates device-check controls');
        $device = $this->makeDevice(['checks' => self::allChecks('pass'), 'last_patched_at' => date('Y-m-d', strtotime('-20 days'))]);
        $this->assertTrue($device->save(), json_encode($device->getErrors()));
        $this->assertTrue($device->isCompliant());

        // Tightening the patch-age control makes the same device fail
        $patch = Control::findOne(array_key_first(DeviceChecks::patchAge()));
        $patch->check_max_age_days = 14;
        $this->assertTrue($patch->save(), json_encode($patch->getErrors()));
        $this->assertSame(DevicePosture::STATE_NON_COMPLIANT, $device->getPosture()['state']);

        // A retired control is no longer checked
        $patch->status = Control::STATUS_RETIRED;
        $this->assertTrue($patch->save());
        $this->assertTrue($device->isCompliant());

        // A new check in force applies straight away: no result yet = not assessed
        $screenLock = $this->makeCheck(['title' => 'Screen lock', 'deviceTypeList' => ['laptop', 'mobile']]);
        $this->assertSame('laptop,mobile', $screenLock->device_types);
        $this->assertSame(DevicePosture::STATE_NOT_ASSESSED, $device->getPosture()['state']);
        $this->assertArrayHasKey($screenLock->id, $device->getPosture()['unknown']);
    }

    public function testCheckResultsAreSavedAndAudited(): void
    {
        $device = $this->makeDevice(['checks' => self::allChecks('pass')]);
        $this->assertTrue($device->save(), json_encode($device->getErrors()));
        $first = array_key_first(DeviceChecks::manual());
        $this->assertSame('pass', $device->getCheckResults()[$first]);

        $reloaded = Device::findOne($device->id);
        $reloaded->checks = [$first => 'fail'];
        $this->assertTrue($reloaded->save(), json_encode($reloaded->getErrors()));
        $this->assertSame('fail', $reloaded->getCheckResults()[$first]);
        $audit = AuditLog::find()->where(['entity_type' => 'device', 'entity_id' => $device->id, 'action' => 'update'])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($audit, 'a change to check results alone is audited');
        $this->assertArrayHasKey('checks', $audit->getChangesArray());

        // Empty clears the result; an unknown control or result is rejected
        $reloaded->checks = [$first => ''];
        $this->assertTrue($reloaded->save());
        $this->assertArrayNotHasKey($first, $reloaded->getCheckResults());
        $reloaded->checks = [999999 => 'pass'];
        $this->assertFalse($reloaded->validate());
        $reloaded->checks = [$first => 'maybe'];
        $this->assertFalse($reloaded->validate());
    }

    public function testWarrantyAndEolFlags(): void
    {
        $device = $this->makeDevice(['warranty_expiry' => '2026-07-15', 'eol_date' => '2026-06-01']);
        $this->assertTrue($device->isWarrantyExpiringSoon('2026-06-30'));
        $this->assertFalse($device->isWarrantyExpiringSoon('2026-04-01'), 'more than 60 days away');
        $this->assertFalse($device->isWarrantyExpiringSoon('2026-08-01'), 'already expired');
        $this->assertTrue($device->isWarrantyExpired('2026-08-01'));
        $this->assertTrue($device->isPastEol('2026-06-30'));
        $this->assertFalse($device->isPastEol('2026-05-01'));
    }

    public function testLastSeenTones(): void
    {
        $today = '2026-10-28';
        $device = $this->makeDevice();
        $this->assertNull($device->getLastSeenTone($today), 'not synced');
        $this->assertFalse($device->isMissing($today));
        $device->last_seen_at = '2026-10-01 09:00:00';
        $this->assertNull($device->getLastSeenTone($today), '27 days');
        $device->last_seen_at = '2026-09-01 09:00:00';
        $this->assertSame('warning', $device->getLastSeenTone($today), '57 days: stale');
        $this->assertFalse($device->isMissing($today));
        $device->last_seen_at = '2026-06-01 09:00:00';
        $this->assertSame('danger', $device->getLastSeenTone($today), '149 days: missing');
        $this->assertTrue($device->isMissing($today));

        \Yii::$app->params['device.staleCriticalDays'] = 10; // never below the stale threshold
        try {
            $this->assertSame(Device::staleDays(), Device::staleCriticalDays());
        } finally {
            unset(\Yii::$app->params['device.staleCriticalDays']);
        }
        $this->assertSame(90, Device::staleCriticalDays());
    }

    public function testMissingAndOsSupportQueriesMatchPhpLogic(): void
    {
        $today = '2026-10-28';
        $devices = [
            'win10' => $this->makeDevice(['hostname' => 'win10', 'os' => 'Windows 10 Enterprise', 'os_version' => '22H2', 'last_seen_at' => '2026-10-27 10:00:00']),
            'win11' => $this->makeDevice(['hostname' => 'win11', 'os' => 'Windows 11 Enterprise', 'os_version' => '23H2', 'last_seen_at' => '2026-09-01 10:00:00']),
            'srv' => $this->makeDevice(['hostname' => 'srv', 'os' => 'Windows Server 2022', 'last_seen_at' => '2026-05-01 10:00:00']),
            'linux' => $this->makeDevice(['hostname' => 'linux', 'os' => 'Linux', 'os_version' => null]),
            'blank' => $this->makeDevice(['hostname' => 'blank', 'os' => null, 'os_version' => null]),
        ];
        foreach ($devices as $d) {
            $this->assertTrue($d->save(), json_encode($d->getErrors()));
        }
        $ids = array_map(static fn(Device $d) => $d->id, $devices);
        $find = static fn() => Device::find()->where(['device.id' => $ids])->select('device.id');
        $names = static fn(array $found) => array_keys(array_intersect($ids, array_map('intval', $found)));

        $this->assertSame(['srv'], $names($find()->missing($today)->column()));
        $this->assertSame(['win11', 'srv'], $names($find()->stale($today)->column()));
        $this->assertSame(['win10'], $names($find()->osSupport(OsLifecycle::UNSUPPORTED, $today)->column()));
        $this->assertSame(['win10', 'win11'], $names($find()->osSupport([OsLifecycle::UNSUPPORTED, OsLifecycle::ENDING], $today)->column()));
        $this->assertSame(['srv'], $names($find()->osSupport(OsLifecycle::SUPPORTED, $today)->column()));
        $this->assertSame(['linux', 'blank'], $names($find()->osSupport(OsLifecycle::UNKNOWN, $today)->column()), 'no OS is unknown');
        $this->assertSame([], $find()->osSupport('no-such-state', $today)->column());
        foreach ($devices as $name => $d) {
            $this->assertSame($name === 'win10', $d->isOsUnsupported($today), $name);
            $this->assertSame($name === 'srv', $d->isMissing($today), $name);
        }
        $this->assertSame('2025-10-14', $devices['win10']->getOsSupport($today)['endDate']);
    }

    public function testSupportedOsCheckMakesUnsupportedDevicesNonCompliant(): void
    {
        $os = array_values(array_filter(DeviceChecks::all(), static fn($c) => $c->isOsSupport()));
        $this->assertCount(1, $os, 'the migration creates the supported-OS check');
        $check = $os[0];
        $this->assertSame('Supported OS', $check->shortLabel());
        $this->assertArrayNotHasKey($check->id, DeviceChecks::manual(), 'nothing to record on the device form');

        $recent = date('Y-m-d', strtotime('-3 days'));
        $pass = self::allChecks('pass');
        $devices = [
            'supported' => $this->makeDevice(['hostname' => 'ok', 'checks' => $pass, 'last_patched_at' => $recent]),
            'win10' => $this->makeDevice(['hostname' => 'w10', 'os' => 'Windows 10 Pro', 'os_version' => '22H2', 'checks' => $pass, 'last_patched_at' => $recent]),
            'unknown' => $this->makeDevice(['hostname' => 'w11', 'os' => 'Windows 11', 'os_version' => null, 'checks' => $pass, 'last_patched_at' => $recent]),
            'no-os' => $this->makeDevice(['hostname' => 'noos', 'os' => null, 'os_version' => null, 'checks' => $pass, 'last_patched_at' => $recent]),
            'switch' => $this->makeDevice(['hostname' => 'sw', 'device_type' => 'network', 'os' => 'IOS XE', 'os_version' => '17.9', 'last_patched_at' => $recent]),
        ];
        foreach ($devices as $d) {
            $this->assertTrue($d->save(), json_encode($d->getErrors()));
        }

        $win10 = $devices['win10']->getPosture();
        $this->assertSame(DevicePosture::STATE_NON_COMPLIANT, $win10['state']);
        $this->assertSame('Windows 10 Pro 22H2: security updates ended 14 Oct 2025', $win10['failures'][$check->id]);
        $this->assertSame(DevicePosture::STATE_COMPLIANT, $devices['supported']->getPosture()['state']);
        $this->assertSame(DevicePosture::RESULT_PASS, $devices['supported']->getPosture()['items'][$check->id]['result']);
        $this->assertSame('No end-of-support date known for Windows 11', $devices['unknown']->getPosture()['items'][$check->id]['detail']);
        $this->assertSame(DevicePosture::STATE_NOT_ASSESSED, $devices['unknown']->getPosture()['state']);
        $this->assertSame('No operating system recorded', $devices['no-os']->getPosture()['items'][$check->id]['detail']);
        $this->assertArrayNotHasKey($check->id, $devices['switch']->getPosture()['items'], 'network devices are out of scope');

        $ids = array_map(static fn(Device $d) => $d->id, $devices);
        $names = static fn(array $found) => array_keys(array_intersect($ids, array_map('intval', $found)));
        $result = static fn(string $r) => $names(Device::find()->where(['device.id' => $ids])->checkResult($check, $r)->select('device.id')->column());
        $this->assertSame(['supported'], $result(DevicePosture::RESULT_PASS));
        $this->assertSame(['win10'], $result(DevicePosture::RESULT_FAIL));
        $this->assertSame(['unknown', 'no-os'], $result(DevicePosture::RESULT_UNKNOWN));
        $this->assertContains($ids['win10'], array_map('intval', Device::find()->where(['device.id' => $ids])->nonCompliant()->select('device.id')->column()));
        $this->assertNotContains($ids['win10'], array_map('intval', Device::find()->where(['device.id' => $ids])->compliant()->select('device.id')->column()));
    }

    public function testPostureQueriesMatchPhpLogic(): void
    {
        $recent = date('Y-m-d', strtotime('-3 days'));
        $pass = self::allChecks('pass');
        $byodOnly = $this->makeCheck(['title' => 'BYOD agreement signed', 'device_ownership' => 'byod']);
        $devices = [
            'good' => $this->makeDevice(['hostname' => 'good', 'checks' => $pass, 'last_patched_at' => $recent]),
            'nopatch' => $this->makeDevice(['hostname' => 'nopatch', 'checks' => $pass]),
            'oldpatch' => $this->makeDevice(['hostname' => 'oldpatch', 'checks' => $pass, 'last_patched_at' => date('Y-m-d', strtotime('-200 days'))]),
            'fail' => $this->makeDevice(['hostname' => 'fail', 'checks' => [array_key_first($pass) => 'fail'] + $pass, 'last_patched_at' => $recent]),
            'blank' => $this->makeDevice(['hostname' => 'blank', 'last_patched_at' => $recent]),
            'byod-unsigned' => $this->makeDevice(['hostname' => 'byod1', 'ownership' => 'byod', 'checks' => $pass, 'last_patched_at' => $recent]),
            'byod-signed' => $this->makeDevice(['hostname' => 'byod2', 'ownership' => 'byod', 'checks' => [$byodOnly->id => 'pass'] + $pass, 'last_patched_at' => $recent]),
            'printer' => $this->makeDevice(['hostname' => 'prn', 'device_type' => 'printer', 'last_patched_at' => $recent]),
        ];
        foreach ($devices as $d) {
            $this->assertTrue($d->save(), json_encode($d->getErrors()));
        }
        $ids = array_map(static fn(Device $d) => $d->id, $devices);
        $sql = static fn(string $scope) => array_map('intval', Device::find()->where(['device.id' => $ids])->$scope()->select('device.id')->column());
        $expected = [
            'compliant' => ['good', 'byod-signed', 'printer'],
            'nonCompliant' => ['oldpatch', 'fail'],
            'notAssessed' => ['nopatch', 'blank', 'byod-unsigned'],
        ];
        foreach ($expected as $scope => $names) {
            $this->assertEqualsCanonicalizing(array_map(static fn($n) => $ids[$n], $names), $sql($scope), $scope);
        }
        $states = ['compliant' => DevicePosture::STATE_COMPLIANT, 'nonCompliant' => DevicePosture::STATE_NON_COMPLIANT, 'notAssessed' => DevicePosture::STATE_NOT_ASSESSED];
        foreach ($expected as $scope => $names) {
            foreach ($names as $n) {
                $this->assertSame($states[$scope], $devices[$n]->getPosture()['state'], $n);
            }
        }

        // Per-check results: the BYOD-only check applies to the two BYOD devices
        $check = DeviceChecks::find($byodOnly->id);
        $count = static fn(string $result) => array_map('intval', Device::find()->where(['device.id' => $ids])->checkResult($check, $result)->select('device.id')->column());
        $this->assertSame([$ids['byod-signed']], $count('pass'));
        $this->assertSame([$ids['byod-unsigned']], $count('unknown'));
        $this->assertSame([], $count('fail'));
    }
}
