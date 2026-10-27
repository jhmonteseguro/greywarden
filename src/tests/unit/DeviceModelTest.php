<?php

namespace tests\unit;

use app\models\AuditLog;
use app\modules\control\models\Control;
use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use tests\DbTestCase;

class DeviceModelTest extends DbTestCase
{
    private function makeDevice(array $attrs = []): Device
    {
        return new Device($attrs + [
            'hostname' => 'lt-test-001',
            'device_type' => 'laptop',
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
