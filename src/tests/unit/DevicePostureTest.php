<?php

namespace tests\unit;

use app\modules\inventory\components\DeviceCheck;
use app\modules\inventory\components\DevicePosture;
use PHPUnit\Framework\TestCase;

class DevicePostureTest extends TestCase
{
    private const TODAY = '2026-06-30';

    /** @return DeviceCheck[] encryption (all types), EDR (endpoints only), MDM (company devices only), patched within 30 days */
    private static function checks(int $maxAge = 30): array
    {
        return [
            1 => new DeviceCheck(1, 'CTL-0001', 'Disk encryption', DeviceCheck::MANUAL),
            2 => new DeviceCheck(2, 'CTL-0002', 'EDR', DeviceCheck::MANUAL, ['laptop', 'desktop', 'server']),
            3 => new DeviceCheck(3, 'CTL-0003', 'MDM', DeviceCheck::MANUAL, null, 'company'),
            4 => new DeviceCheck(4, 'CTL-0004', 'Patched', DeviceCheck::PATCH_AGE, null, 'all', $maxAge),
        ];
    }

    private const ALL_PASS = [1 => 'pass', 2 => 'pass', 3 => 'pass'];

    public function testEveryCheckPassingIsCompliant(): void
    {
        $r = DevicePosture::evaluate(self::checks(), 'laptop', 'company', self::ALL_PASS, '2026-06-20', self::TODAY);
        $this->assertSame(DevicePosture::STATE_COMPLIANT, $r['state']);
        $this->assertTrue($r['compliant']);
        $this->assertSame([], $r['failures']);
        $this->assertSame(10, $r['patchAgeDays']);
        $this->assertSame([1, 2, 3, 4], array_keys($r['items']));
    }

    public function testAFailingCheckMakesItNonCompliant(): void
    {
        foreach ([1, 2, 3] as $id) {
            $results = self::ALL_PASS;
            $results[$id] = 'fail';
            $r = DevicePosture::evaluate(self::checks(), 'laptop', 'company', $results, '2026-06-29', self::TODAY);
            $this->assertSame(DevicePosture::STATE_NON_COMPLIANT, $r['state'], "check $id");
            $this->assertSame([$id], array_keys($r['failures']));
        }
    }

    public function testMissingResultIsNotAssessedButFailureWins(): void
    {
        $r = DevicePosture::evaluate(self::checks(), 'laptop', 'company', [1 => 'pass'], '2026-06-29', self::TODAY);
        $this->assertSame(DevicePosture::STATE_NOT_ASSESSED, $r['state']);
        $this->assertFalse($r['compliant']);
        $this->assertSame([2, 3], array_keys($r['unknown']));

        $r = DevicePosture::evaluate(self::checks(), 'laptop', 'company', [1 => 'fail'], '2026-06-29', self::TODAY);
        $this->assertSame(DevicePosture::STATE_NON_COMPLIANT, $r['state'], 'a failure outranks checks with no result');
    }

    public function testChecksOnlyApplyToTheirDeviceTypesAndOwnership(): void
    {
        // A BYOD phone: EDR (endpoints) and MDM (company devices) do not apply
        $r = DevicePosture::evaluate(self::checks(), 'mobile', 'byod', [1 => 'pass'], '2026-06-29', self::TODAY);
        $this->assertSame([1, 4], array_keys($r['items']));
        $this->assertSame(DevicePosture::STATE_COMPLIANT, $r['state']);
        // Results recorded for checks that do not apply are ignored
        $r = DevicePosture::evaluate(self::checks(), 'mobile', 'byod', [1 => 'pass', 2 => 'fail'], '2026-06-29', self::TODAY);
        $this->assertSame(DevicePosture::STATE_COMPLIANT, $r['state']);
    }

    public function testPatchAgeBoundaryIsInclusiveAndConfigurable(): void
    {
        $this->assertTrue(DevicePosture::evaluate(self::checks(), 'laptop', 'company', self::ALL_PASS, '2026-05-31', self::TODAY)['compliant'], 'exactly 30 days is OK');
        $r = DevicePosture::evaluate(self::checks(), 'laptop', 'company', self::ALL_PASS, '2026-05-30', self::TODAY);
        $this->assertSame([4], array_keys($r['failures']));
        $this->assertStringContainsString('31 days ago', $r['failures'][4]);

        $this->assertFalse(DevicePosture::evaluate(self::checks(14), 'laptop', 'company', self::ALL_PASS, '2026-06-15', self::TODAY)['compliant']);
        $this->assertTrue(DevicePosture::evaluate(self::checks(15), 'laptop', 'company', self::ALL_PASS, '2026-06-15', self::TODAY)['compliant']);
    }

    public function testNoPatchDateIsNotAssessed(): void
    {
        $r = DevicePosture::evaluate(self::checks(), 'laptop', 'company', self::ALL_PASS, null, self::TODAY);
        $this->assertSame(DevicePosture::STATE_NOT_ASSESSED, $r['state']);
        $this->assertSame([4], array_keys($r['unknown']));
        $this->assertNull($r['patchAgeDays']);
    }

    public function testNoChecksMeansNothingIsRequired(): void
    {
        $r = DevicePosture::evaluate([], 'printer', 'company', [], null, self::TODAY);
        $this->assertSame(DevicePosture::STATE_COMPLIANT, $r['state']);
        $this->assertSame([], $r['items']);
    }

    public function testPatchCutoff(): void
    {
        $this->assertSame('2026-05-31', DevicePosture::patchCutoff(30, self::TODAY));
    }
}
