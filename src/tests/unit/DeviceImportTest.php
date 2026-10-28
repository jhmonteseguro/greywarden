<?php

namespace tests\unit;

use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DeviceImporter;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\components\GoogleDeviceCsv;
use app\modules\inventory\models\Device;
use DateTimeZone;
use InvalidArgumentException;
use tests\DbTestCase;

class DeviceImportTest extends DbTestCase
{
    private const HEADER = 'Device Name,Name,Email,Ownership,Type,Model,First Sync,Last Sync,Status,Device ID,Serial Number (mandatory),IMEI / MEID,OS,User Agent,Host Name';

    private const ROWS = [
        "Ross's 20U90042UK,Ross Example,Ross.Example@example.com,User owned,Windows,20U90042UK,\"September 3, 2020 at 6:54 AM GMT-7\",\"October 19, 2026 at 3:48 AM GMT-7\",Approved,6cad07b8-70d0-4933-8dfd-d98199d380ef,,,Windows 10.0.22621.4317,,",
        "Finlay's Mac OS,Finlay  Example,finlay@example.com,User owned,Mac,Mac OS,\"August 24, 2026 at 4:41 AM GMT-7\",\"October 19, 2026 at 12:22 AM GMT-7\",Approved,e48b11b8-0101-4375-8a1c-445a428c9553,,,macOS 10.15.7,,",
        "\"Shiv's iMac17,1\",Shiv Example,shiv@example.com,Company owned,Mac,\"iMac17,1\",\"September 11, 2021 at 2:35 AM GMT-7\",\"April 11, 2022 at 10:27 AM GMT-7\",Approved,cb99a21a-1645-4149-abe5-ca6697cdf61f,DGKRF0A4GQ18,,MacOS 11.6.1,,Shiv-iMac-2016.local",
        "Louisa's iPad mini (6th generation),Louisa Example,louisa@example.com,User owned,iOS,iPad mini (6th generation),\"June 7, 2026 at 8:20 PM GMT-7\",\"September 27, 2026 at 6:50 AM GMT-7\",Approved,A5587C90-92AA-44A7-8D82-6BE86995A6E5,,,iOS 26.5.2,,",
        "Ross's SM-T560,Ross Example,ross.example@example.com,User owned,Android,SM-T560,\"February 13, 2020 at 10:55 AM GMT-8\",\"January 16, 2021 at 12:57 AM GMT-8\",Wiped,38afd49f3c564c23,,,Android 4.4.4,,",
    ];

    private static function csv(array $rows = self::ROWS): string
    {
        return "\xEF\xBB\xBF" . self::HEADER . "\n" . implode("\n", $rows) . "\n\n";
    }

    private static function parse(array $rows = self::ROWS): array
    {
        return GoogleDeviceCsv::parse(self::csv($rows), new DateTimeZone('UTC'));
    }

    public function testParsesAGoogleExport(): void
    {
        $records = self::parse();
        $this->assertCount(5, $records);
        [$win, $mac, $imac, $ipad, $tab] = array_column($records, 'attributes');

        $this->assertSame(2, $records[0]['line']);
        $this->assertSame("Ross's 20U90042UK", $win['name']);
        $this->assertSame('laptop', $win['device_type']);
        $this->assertSame('byod', $win['ownership']);
        $this->assertSame('Lenovo', $win['manufacturer']);
        $this->assertSame('Windows 11', $win['os']);
        $this->assertSame('22H2 (10.0.22621.4317)', $win['os_version']);
        $this->assertSame('ross.example@example.com', $win['assigned_email']);
        $this->assertSame('google', $win['source']);
        $this->assertSame('6cad07b8-70d0-4933-8dfd-d98199d380ef', $win['source_id']);
        $this->assertSame('2020-09-03 13:54:00', $win['first_seen_at']);
        $this->assertSame('2026-10-19 10:48:00', $win['last_seen_at']);
        $this->assertSame('active', $win['status']);

        // Generic model dropped; frozen browser macOS version not trusted; whitespace collapsed
        $this->assertNull($mac['model']);
        $this->assertSame('Apple', $mac['manufacturer']);
        $this->assertSame('macOS', $mac['os']);
        $this->assertNull($mac['os_version']);
        $this->assertSame('Finlay Example', $mac['assigned_to']);
        $this->assertStringContainsString('frozen browser user-agent', $records[1]['notes'][0]);

        $this->assertSame('desktop', $imac['device_type']);
        $this->assertSame('company', $imac['ownership']);
        $this->assertSame('iMac17,1', $imac['model']);
        $this->assertSame('DGKRF0A4GQ18', $imac['serial_number']);
        $this->assertSame('Shiv-iMac-2016.local', $imac['hostname']);
        $this->assertSame(['macOS', '11.6.1'], [$imac['os'], $imac['os_version']]);

        $this->assertSame('tablet', $ipad['device_type']);
        $this->assertSame(['iPadOS', '26.5.2'], [$ipad['os'], $ipad['os_version']]);
        $this->assertSame('2026-09-27 13:50:00', $ipad['last_seen_at']);

        $this->assertSame('tablet', $tab['device_type']);
        $this->assertSame('Samsung', $tab['manufacturer']);
        $this->assertSame('retired', $tab['status']);
        $this->assertSame('2021-01-16 08:57:00', $tab['last_seen_at']); // GMT-8
        $this->assertContains('Google Workspace status: Wiped.', $records[4]['notes']);
    }

    public function testOsParsing(): void
    {
        $this->assertSame(['Windows', null], GoogleDeviceCsv::parseOs('Windows 10.0'));
        $this->assertSame(['Windows 11', '25H2 (10.0.26200.9711)'], GoogleDeviceCsv::parseOs('Windows 10.0.26200.9711'));
        $this->assertSame(['Windows 10', '22H2 (10.0.19045.5011)'], GoogleDeviceCsv::parseOs('Windows 10.0.19045.5011'));
        $this->assertSame(['Windows 11', '10.0.27000.1'], GoogleDeviceCsv::parseOs('Windows 10.0.27000.1'));
        $this->assertSame(['iOS', '27.0.1'], GoogleDeviceCsv::parseOs('iOS 27.0.1', 'ios', 'iPhone 15'));
        $this->assertSame(['Android', '17'], GoogleDeviceCsv::parseOs('Android 17'));
        $this->assertSame(['Linux', null], GoogleDeviceCsv::parseOs('Linux'));
        $this->assertSame([null, null], GoogleDeviceCsv::parseOs(''));
        $this->assertSame('mobile', GoogleDeviceCsv::deviceType('android', 'Pixel 8'));
        $this->assertSame('laptop', GoogleDeviceCsv::deviceType('windows', 'Surface Laptop 4'));
        $this->assertSame('other', GoogleDeviceCsv::deviceType('chromecast', ''));
    }

    public function testRejectsOtherCsvFiles(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GoogleDeviceCsv::parse("hostname,ip\nsrv-1,10.0.0.1\n");
    }

    public function testImportCreatesThenUpdatesWithoutClobberingAnalystEdits(): void
    {
        $importer = new DeviceImporter();
        $first = $importer->import(self::parse(), 'Imported in a test.');
        $this->assertSame(array_fill(0, 5, DeviceImporter::CREATED), array_column($first, 'action'), json_encode(array_column($first, 'errors')));

        $device = Device::findOne(['source' => 'google', 'source_id' => '6cad07b8-70d0-4933-8dfd-d98199d380ef']);
        $this->assertMatchesRegularExpression('/^DEV-\d{4,}$/', $device->asset_tag);
        $this->assertTrue($device->isByod());
        $this->assertSame('Imported in a test.', $device->notes);
        // The export has no posture data: only the supported-OS check has a result (Windows 11 22H2 Pro ended in 2024)
        $posture = $device->getPosture();
        $this->assertSame(DevicePosture::STATE_NON_COMPLIANT, $posture['state']);
        $this->assertSame(['Windows 11 22H2 (10.0.22621.4317): security updates ended 8 Oct 2024'], array_values($posture['failures']));
        $this->assertNotEmpty($posture['unknown'], 'the manual checks are not assessed');
        $this->assertStringContainsString('frozen', Device::findOne(['source_id' => 'e48b11b8-0101-4375-8a1c-445a428c9553'])->notes);

        // Same file again: nothing changes (and no audit noise)
        $again = $importer->import(self::parse(), 'Imported in a test.');
        $this->assertSame(array_fill(0, 5, DeviceImporter::UNCHANGED), array_column($again, 'action'), json_encode(array_column($again, 'changed')));

        // Analyst edits survive; a newer export refreshes the sync fields only
        $device->name = 'Ross ThinkPad';
        $device->criticality = 'high';
        $check = array_key_first(DeviceChecks::manual());
        $device->checks = [$check => DevicePosture::RESULT_PASS];
        $device->model = 'ThinkPad T14 Gen 2';
        $device->os_version = '23H2';
        $this->assertTrue($device->save(), json_encode($device->getErrors()));
        $mac = Device::findOne(['source_id' => 'e48b11b8-0101-4375-8a1c-445a428c9553']);
        $mac->os_version = '26.1';
        $this->assertTrue($mac->save(), json_encode($mac->getErrors()));

        $newer = self::ROWS;
        $newer[0] = str_replace(['October 19, 2026 at 3:48 AM', 'Windows 10.0.22621.4317'], ['October 20, 2026 at 9:00 AM', 'Windows 10.0.22631.4460'], $newer[0]);
        $results = $importer->import(self::parse($newer), 'Imported in a test.');
        $this->assertSame(DeviceImporter::UPDATED, $results[0]['action']);
        $this->assertEqualsCanonicalizing(['os_version', 'last_seen_at'], $results[0]['changed']);
        $this->assertSame(DeviceImporter::UNCHANGED, $results[1]['action'], 'an unknown OS version must not clear the stored one');
        $this->assertSame('26.1', $mac->refresh() ? $mac->os_version : null);

        $device->refresh();
        $this->assertSame('Ross ThinkPad', $device->name);
        $this->assertSame('high', $device->criticality);
        $this->assertSame(DevicePosture::RESULT_PASS, $device->getCheckResults()[$check] ?? null, 'device check results survive a re-import');
        $this->assertSame('ThinkPad T14 Gen 2', $device->model);
        $this->assertSame('23H2 (10.0.22631.4460)', $device->os_version);
        $this->assertSame('2026-10-20 16:00:00', $device->last_seen_at);
    }

    public function testStaleDetectionMatchesSql(): void
    {
        (new DeviceImporter())->import(self::parse(), 'Imported in a test.');
        $today = '2026-10-19';
        $ids = array_map(static fn(Device $d) => $d->source_id, Device::find()->where(['source' => 'google'])->all());
        $php = array_values(array_map(static fn(Device $d) => $d->source_id, array_filter(
            Device::find()->where(['source' => 'google'])->all(), static fn(Device $d) => $d->isStale($today))));
        $sql = Device::find()->where(['source' => 'google'])->stale($today)->select('source_id')->column();
        sort($php);
        sort($sql);
        $this->assertSame($php, $sql);
        // the iMac (2022) and the wiped tablet (2021) are stale; the iPad (22 days) is not
        $this->assertCount(2, $sql);
        $this->assertCount(5, $ids);
        $ipad = Device::findOne(['source_id' => 'A5587C90-92AA-44A7-8D82-6BE86995A6E5']);
        $this->assertSame(22, $ipad->getLastSeenDays($today));
        $this->assertFalse($ipad->isStale($today));
        $this->assertNull((new Device())->getLastSeenDays($today));
    }

    public function testSourceIdIsUniquePerSource(): void
    {
        (new DeviceImporter())->import(self::parse(), 'Imported in a test.');
        $dupe = new Device([
            'name' => 'Dupe', 'device_type' => 'mobile', 'status' => 'active', 'criticality' => 'low', 'data_classification' => 'internal',
            'source' => 'google', 'source_id' => '38afd49f3c564c23', 'assigned_email' => 'not-an-email',
        ]);
        $this->assertFalse($dupe->validate());
        $this->assertArrayHasKey('source_id', $dupe->getErrors());
        $this->assertArrayHasKey('assigned_email', $dupe->getErrors());
    }
}
