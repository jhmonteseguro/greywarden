<?php

namespace tests\unit;

use app\modules\inventory\components\OsLifecycle;
use PHPUnit\Framework\TestCase;
use Yii;

class OsLifecycleTest extends TestCase
{
    private const TODAY = '2026-10-28';

    protected function tearDown(): void
    {
        unset(Yii::$app->params['device.osEndOfSupport'], Yii::$app->params['device.osWarningDays']);
    }

    private static function state(?string $os, ?string $version = null): string
    {
        return OsLifecycle::evaluate($os, $version, self::TODAY)['state'];
    }

    public static function endDates(): array
    {
        return [
            'Windows 10' => ['Windows 10 Enterprise', '22H2', '2025-10-14'],
            'Windows 7' => ['Windows 7', null, '2020-01-14'],
            'Windows 8.1 (not 8)' => ['Windows 8.1 Pro', null, '2023-01-10'],
            'Windows 10 LTSC 2021' => ['Windows 10 Enterprise LTSC 2021', null, '2027-01-12'],
            'Windows 10 LTSB, year first' => ['Windows 10', '2016 LTSB', '2026-10-13'],
            'Windows 11 LTSC 2024' => ['Windows 11 IoT Enterprise LTSC 2024', null, '2029-10-09'],
            'Windows 11 Pro 23H2' => ['Windows 11 Pro', '23H2', '2025-11-11'],
            'Windows 11 Enterprise 23H2' => ['Windows 11 Enterprise', '23H2', '2026-11-10'],
            'Windows 11 from the Google import' => ['Windows 11', '25H2 (10.0.26200.9711)', '2027-10-12'],
            'Windows Server 2012 R2' => ['Windows Server 2012 R2', '6.3', '2023-10-10'],
            'Windows Server 2022' => ['Microsoft Windows Server 2022', '21H2', '2031-10-14'],
            'macOS by number' => ['macOS', '14.6 Sonoma', '2026-09-15'],
            'macOS by name' => ['macOS', 'Ventura', '2025-09-15'],
            'Mac OS X 10.x' => ['Mac OS X', '10.13.6', '2022-10-24'],
            'macOS older than the catalogue' => ['macOS', '9.2', '2022-10-24'],
            'iOS 17' => ['iOS', '17.6', '2025-09-15'],
            'iPadOS 26' => ['iPadOS', '26.5.2', '2027-09-15'],
            'Android 14' => ['Android', '14', '2027-03-01'],
            'Ubuntu LTS' => ['Ubuntu Server', '22.04 LTS', '2027-06-01'],
            'Ubuntu in the OS name' => ['Ubuntu 20.04.6 LTS', null, '2025-05-29'],
            'Debian' => ['Debian', '11 (bullseye)', '2026-08-31'],
            'CentOS 7' => ['CentOS Linux', '7.9', '2024-06-30'],
            'CentOS Stream 9' => ['CentOS Stream 9', null, '2027-05-31'],
            'RHEL' => ['Red Hat Enterprise Linux', '8.10', '2029-05-31'],
            'Rocky' => ['Rocky Linux', '9.4', '2032-05-31'],
            'Amazon Linux 2' => ['Amazon Linux', '2', '2026-06-30'],
            'Amazon Linux 2023' => ['Amazon Linux', '2023', '2029-06-30'],
        ];
    }

    /** @dataProvider endDates */
    public function testEndOfSupportDates(string $os, ?string $version, string $end): void
    {
        $this->assertSame($end, OsLifecycle::endOfSupport($os, $version)[0] ?? null);
    }

    public function testUnknownWhenTheOsOrVersionDoesNotDecide(): void
    {
        $this->assertNull(OsLifecycle::endOfSupport(null, null));
        $this->assertNull(OsLifecycle::endOfSupport('', ''));
        $this->assertNull(OsLifecycle::endOfSupport('Windows 11', null), 'support depends on the feature update');
        $this->assertNull(OsLifecycle::endOfSupport('Windows 11', '27H2'), 'not in the catalogue yet');
        $this->assertNull(OsLifecycle::endOfSupport('Windows', null));
        $this->assertNull(OsLifecycle::endOfSupport('Windows 10 Enterprise LTSC 2030', null), 'unknown LTSC release');
        $this->assertNull(OsLifecycle::endOfSupport('Windows Server 2030', null));
        $this->assertNull(OsLifecycle::endOfSupport('macOS', null), 'frozen 10.15.7 is imported as a blank version');
        $this->assertNull(OsLifecycle::endOfSupport('macOS', '40.1'), 'newer than the catalogue');
        $this->assertNull(OsLifecycle::endOfSupport('iOS', null));
        $this->assertNull(OsLifecycle::endOfSupport('Android', null));
        $this->assertNull(OsLifecycle::endOfSupport('Ubuntu', '30.04'));
        $this->assertNull(OsLifecycle::endOfSupport('Debian', null));
        $this->assertNull(OsLifecycle::endOfSupport('CentOS Stream 10', null));
        $this->assertNull(OsLifecycle::endOfSupport('CentOS', '5'));
        $this->assertNull(OsLifecycle::endOfSupport('RHEL', null));
        $this->assertNull(OsLifecycle::endOfSupport('Amazon Linux', '2050'));
        $this->assertNull(OsLifecycle::endOfSupport('ChromeOS', '128'), 'per-device auto-update expiry');
        $this->assertNull(OsLifecycle::endOfSupport('FortiOS', '7.2.8'));
        $this->assertSame(OsLifecycle::UNKNOWN, self::state('Linux', '6.8'));
        $this->assertSame(['state' => OsLifecycle::UNKNOWN, 'endDate' => null, 'estimated' => false, 'days' => null], OsLifecycle::evaluate(null, null, self::TODAY));
    }

    public function testStatesAgainstToday(): void
    {
        $this->assertSame(OsLifecycle::UNSUPPORTED, self::state('Windows 10 Pro', '22H2'));
        $this->assertSame(OsLifecycle::ENDING, self::state('Windows 11 Enterprise', '23H2'), 'ends 2026-11-10, within 90 days');
        $this->assertSame(OsLifecycle::SUPPORTED, self::state('Windows Server 2022'));

        $r = OsLifecycle::evaluate('Windows 11 Enterprise', '23H2', self::TODAY);
        $this->assertSame(13, $r['days']);
        $this->assertSame(-14, OsLifecycle::evaluate('Windows 11 Enterprise', '23H2', '2026-11-24')['days']);
        $this->assertSame(OsLifecycle::UNSUPPORTED, OsLifecycle::evaluate('Windows 11 Enterprise', '23H2', '2026-11-11')['state']);
        $this->assertSame(OsLifecycle::ENDING, OsLifecycle::evaluate('Windows 11 Enterprise', '23H2', '2026-11-10')['state'], 'the end date is still supported');

        Yii::$app->params['device.osWarningDays'] = 7;
        $this->assertSame(OsLifecycle::SUPPORTED, self::state('Windows 11 Enterprise', '23H2'));
    }

    public function testEstimatedDatesAreMarked(): void
    {
        $this->assertTrue(OsLifecycle::evaluate('macOS', '15.1', self::TODAY)['estimated']);
        $this->assertTrue(OsLifecycle::evaluate('Android', '13', self::TODAY)['estimated']);
        $this->assertFalse(OsLifecycle::evaluate('macOS', '13.7', self::TODAY)['estimated']);
        $this->assertFalse(OsLifecycle::evaluate('Windows 10', null, self::TODAY)['estimated']);
    }

    public function testParamsOverrideTheCatalogue(): void
    {
        Yii::$app->params['device.osEndOfSupport'] = [
            '/^fortios 7\.0\b/' => '2024-09-30',
            '/^windows 10\b/' => '2028-10-10', // e.g. covered by ESU
            '/[invalid/' => '2000-01-01',
        ];
        $this->assertSame(['2024-09-30', false], OsLifecycle::endOfSupport('FortiOS', '7.0.15'));
        $this->assertSame(OsLifecycle::UNSUPPORTED, self::state('FortiOS', '7.0.15'));
        $this->assertSame(OsLifecycle::SUPPORTED, self::state('Windows 10 Enterprise', '22H2'));
        $this->assertNull(OsLifecycle::endOfSupport('FortiOS', '7.2.8'), 'an invalid pattern is skipped');
    }

    public function testStateLabels(): void
    {
        $this->assertSame('Unsupported', OsLifecycle::stateLabels()[OsLifecycle::UNSUPPORTED]);
        $this->assertSame(90, OsLifecycle::warningDays());
    }
}
