<?php

namespace tests\unit;

use app\commands\CloudController;
use app\models\User;
use app\modules\cloud\components\AwsConfig;
use app\modules\cloud\components\AwsExport;
use app\modules\cloud\components\AzureExport;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudExport;
use app\modules\cloud\components\CloudflareZones;
use app\modules\cloud\components\CloudImporter;
use app\modules\cloud\components\CloudTypeMap;
use app\modules\cloud\components\GcpExport;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\intel\components\Cloudflare;
use app\modules\intel\components\Http;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use tests\DbTestCase;
use tests\support\OutputCapture;
use Yii;
use yii\base\Event;
use yii\console\ExitCode;
use yii\db\BaseActiveRecord;

/**
 * Cloud Inventory imports: the AWS / Azure / Google Cloud export parsers, the
 * provider type map, the importer, the Cloudflare zone sync (against a fake
 * API) and the cloud/import and cloud/sync-cloudflare commands.
 */
class CloudImportTest extends DbTestCase
{
    private const NOW = '2026-10-28 12:00:00';

    private array $tmpFiles = [];
    /** @var array[] fake Cloudflare zone pages */
    private array $zonePages = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::$fake = function (array $r): array {
            if (preg_match('#/zones\?page=(\d+)&per_page=50$#', $r['url'], $m) && isset($this->zonePages[(int) $m[1] - 1])) {
                return ['body' => json_encode(['success' => true, 'result' => $this->zonePages[(int) $m[1] - 1],
                    'result_info' => ['page' => (int) $m[1], 'per_page' => 50, 'total_pages' => count($this->zonePages)]])];
            }
            return ['status' => 403, 'body' => json_encode(['success' => false, 'errors' => [['code' => 9109, 'message' => 'Unauthorized to access requested resource']]])];
        };
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        Http::$fake = null;
        putenv('CLOUDFLARE_API_TOKEN');
        putenv('CLOUDFLARE_ZONE_ID');
        parent::tearDown();
    }

    private static function fixture(string $name): string
    {
        return Yii::getAlias('@app/tests/_data/cloud/' . $name);
    }

    private static function parse(string $provider, string $fixture): array
    {
        return CloudExport::for($provider)->parse(file_get_contents(self::fixture($fixture)));
    }

    private function tmpFile(string $content, string $suffix = '.json'): string
    {
        $file = Yii::getAlias('@runtime') . '/phpunit-' . bin2hex(random_bytes(4)) . $suffix;
        file_put_contents($file, $content);
        return $this->tmpFiles[] = $file;
    }

    private function makeAccount(array $attrs = []): CloudAccount
    {
        $account = new CloudAccount($attrs + ['name' => 'Production (AWS)', 'provider' => 'aws', 'account_identifier' => '111122223333',
            'environment' => 'staging', 'status' => CloudAccount::STATUS_ACTIVE, 'criticality' => 'high', 'data_classification' => 'confidential']);
        $this->assertTrue($account->save(), json_encode($account->getErrors()));
        return $account;
    }

    /** One AWS record as the parsers build them */
    private static function record(array $attributes = [], array $extra = []): array
    {
        return $extra + [
            'line' => 2, 'provider' => 'aws', 'provider_type' => 'ec2:instance', 'account' => '111122223333', 'account_aliases' => [],
            'account_name' => null, 'account_properties' => null, 'create' => [], 'state' => null, 'error' => null,
            'attributes' => $attributes + ['name' => 'portal-web-01', 'resource_id' => 'arn:aws:ec2:eu-west-2:111122223333:instance/i-01',
                'source' => 'aws', 'source_id' => 'arn:aws:ec2:eu-west-2:111122223333:instance/i-01', 'region' => 'eu-west-2'],
        ];
    }

    private function import(array $records, ?CloudAccount $account = null, string $note = 'Imported in a test.'): array
    {
        $importer = new CloudImporter(self::NOW, $account);
        return [$importer->import($records, $note), $importer];
    }

    // ---- Parsers ----------------------------------------------------------------

    public function testAwsResourceExplorerJson(): void
    {
        $records = self::parse('aws', 'aws-resource-explorer.json');
        $this->assertCount(5, $records);
        [$instance, $bucket, $alb, $role, $eni] = $records;

        $this->assertSame(1, $instance['line']);
        $this->assertSame('aws', $instance['provider']);
        $this->assertSame('ec2:instance', $instance['provider_type']);
        $this->assertSame('111122223333', $instance['account']);
        $this->assertSame('portal-web-01', $instance['attributes']['name'], 'Name tag');
        $this->assertSame('arn:aws:ec2:eu-west-2:111122223333:instance/i-0a1b2c3d4e5f60001', $instance['attributes']['source_id']);
        $this->assertSame($instance['attributes']['source_id'], $instance['attributes']['resource_id']);
        $this->assertSame('eu-west-2', $instance['attributes']['region']);
        $this->assertSame(CloudExport::time('2026-10-27T06:00:00Z'), $instance['attributes']['last_seen_at']);
        $this->assertNull($instance['error']);

        $this->assertSame('example-customer-exports', $bucket['attributes']['name']);
        $this->assertSame('111122223333', $bucket['account'], 'S3 ARNs have no account: OwningAccountId');
        $this->assertSame('customer-portal', $alb['attributes']['name']);
        $this->assertSame('ci-deploy', $role['attributes']['name']);
        $this->assertSame('global', $role['attributes']['region']);
        $this->assertSame('ec2:network-interface', $eni['provider_type']);
    }

    public function testAwsConfigJsonAndCsv(): void
    {
        [$instance, $eip, $sg, $logs] = self::parse('aws', 'aws-config.json');
        $this->assertSame('AWS::EC2::Instance', $instance['provider_type']);
        $this->assertSame('444455556666', $instance['account']);
        $this->assertSame('dev-sandbox-01', $instance['attributes']['name']);
        $this->assertNull($instance['attributes']['last_seen_at'], 'Config capture time is not a last-seen time');
        $this->assertSame('198.51.100.20', $instance['attributes']['public_ips']);
        // No ARN: the resource ID, qualified by account, region and type
        $this->assertSame('eipalloc-0123456789abcdef0', $eip['attributes']['resource_id']);
        $this->assertSame('444455556666:eu-west-2:aws::ec2::eip:eipalloc-0123456789abcdef0', $eip['attributes']['source_id']);
        $this->assertSame('198.51.100.21', $eip['attributes']['public_ips']);
        $this->assertSame('dev-web-sg', $sg['attributes']['name']);
        $this->assertSame('AWS::Logs::LogGroup', $logs['provider_type']);

        [$bucket, $vpc] = self::parse('aws', 'aws-config.csv');
        $this->assertSame(2, $bucket['line']);
        $this->assertSame('AWS::S3::Bucket', $bucket['provider_type']);
        $this->assertSame('dev-artifacts', $bucket['attributes']['name']);
        $this->assertSame('arn:aws:s3:::example-dev-artifacts', $bucket['attributes']['source_id']);
        $this->assertSame('vpc-0f1e2d3c4b5a69788', $vpc['attributes']['name'], 'no resourceName: from the ARN');
    }

    public function testAwsResourceExplorerCsvAndEdgeCases(): void
    {
        [$instance, $function, $route, $queue] = self::parse('aws', 'aws-resource-explorer.csv');
        $this->assertSame('portal-web-01', $instance['attributes']['name'], '"Name" tag column');
        $this->assertSame('export-reports', $function['attributes']['name']);
        $this->assertSame('billing-events', $queue['attributes']['name'], '"(not tagged)" is no name: the ARN\'s is used');
        $this->assertSame('ec2:route-table', $route['provider_type']);

        // Bare list (--query Resources), no account or region beyond the ARN, a non-ARN identifier, a row without any ID
        $records = (new AwsExport())->parse(json_encode([
            ['Arn' => 'arn:aws:sqs:eu-west-2:111122223333:orders', 'ResourceType' => 'sqs:queue'],
            ['resourceId' => 'vol-1', 'resourceType' => 'AWS::EC2::Volume', 'accountId' => '111122223333'],
            ['ResourceType' => 'ec2:volume'],
            'not an object',
        ]));
        $this->assertSame('111122223333', $records[0]['account']);
        $this->assertSame('eu-west-2', $records[0]['attributes']['region']);
        $this->assertSame('orders', $records[0]['attributes']['name']);
        $this->assertSame('111122223333:global:aws::ec2::volume:vol-1', $records[1]['attributes']['source_id']);
        $this->assertSame('No ARN or resource ID.', $records[2]['error']);
        $this->assertSame('No ARN or resource ID.', $records[3]['error']);

        $csv = (new AwsExport())->parse("Identifier,Resource type\ni-0123,ec2:instance\n");
        $this->assertSame('i-0123', $csv[0]['attributes']['resource_id']);
        $this->assertNull($csv[0]['account']);

        $this->assertSame('ci-deploy', AwsExport::arnName('arn:aws:iam::111122223333:role/service/ci-deploy'));
        $this->assertSame('my-fn', AwsExport::arnName('arn:aws:lambda:eu-west-2:111122223333:function:my-fn'));
        $this->assertSame('web', AwsExport::arnName('arn:aws:elasticloadbalancing:eu-west-2:111122223333:loadbalancer/net/web/0123'));
        $this->assertSame('arn:aws:s3:::', AwsExport::arnName('arn:aws:s3:::'));
    }

    public function testAzureResourceGraph(): void
    {
        [$vm, $pip, $fn, $db, $nic] = self::parse('azure', 'azure-resource-graph.json');
        $this->assertSame('microsoft.compute/virtualmachines', $vm['provider_type']);
        $this->assertSame('00000000-0000-0000-0000-000000000001', $vm['account']);
        $this->assertSame('vm-web-01', $vm['attributes']['name']);
        $this->assertSame(strtolower($vm['attributes']['resource_id']), $vm['attributes']['source_id'], 'IDs matched in lower case');
        $this->assertSame('uksouth', $vm['attributes']['region']);
        $this->assertSame(['Ubuntu', '22.04'], [$vm['attributes']['os'], $vm['attributes']['os_version']]);
        $this->assertSame(CloudExport::time('2025-03-01T09:30:00Z'), $vm['attributes']['first_seen_at']);
        $this->assertSame('203.0.113.40', $pip['attributes']['public_ips']);
        $this->assertSame('microsoft.web/sites#functionapp', $fn['provider_type']);
        $this->assertSame('microsoft.sql/servers/databases', $db['provider_type']);
        $this->assertSame('microsoft.network/networkinterfaces', $nic['provider_type']);

        // Portal CSV: subscription names ("Format results"), the type left out, properties as JSON text
        [$storage, $vault] = self::parse('azure', 'azure-resource-graph.csv');
        $this->assertSame('00000000-0000-0000-0000-000000000001', $storage['account'], 'from the resource ID');
        $this->assertSame('microsoft.storage/storageaccounts', $storage['provider_type']);
        $this->assertSame('microsoft.keyvault/vaults', $vault['provider_type'], 'type from the resource ID');

        // Plain list, REST "value", OS disk type only, name from the ID, no ID
        $sub = '/subscriptions/00000000-0000-0000-0000-000000000002/resourceGroups/rg/providers';
        $records = (new AzureExport())->parse(json_encode(['value' => [
            ['id' => "$sub/Microsoft.Compute/virtualMachines/vm2", 'properties' => ['storageProfile' => ['osDisk' => ['osType' => 'Windows']]]],
            ['id' => "$sub/Microsoft.Web/sites/app1", 'kind' => 'app'],
            ['name' => 'no-id', 'type' => 'microsoft.compute/disks'],
        ]]));
        $this->assertSame(['Windows', null], [$records[0]['attributes']['os'], $records[0]['attributes']['os_version']]);
        $this->assertSame('vm2', $records[0]['attributes']['name']);
        $this->assertSame('microsoft.web/sites', $records[1]['provider_type']);
        $this->assertSame('No Azure resource ID (/subscriptions/...).', $records[2]['error']);
        $this->assertCount(1, (new AzureExport())->parse(json_encode([['id' => "$sub/Microsoft.Cache/redis/c1", 'properties' => 'not json']])));

        $this->assertSame('microsoft.sql/servers/databases', AzureExport::typeFromId("$sub/Microsoft.Sql/servers/a/databases/b"));
        $this->assertSame('', AzureExport::typeFromId('/subscriptions/x/resourceGroups/rg'));
    }

    public function testGcpAssetInventory(): void
    {
        [$project, $bucket, $instance, $address, $route] = self::parse('gcp', 'gcp-assets.json');
        $this->assertSame('cloudresourcemanager.googleapis.com/Project', $project['provider_type']);
        // A bucket's name has no project ID: the project row pairs the number with it
        $this->assertSame('example-prod-123456', $bucket['account']);
        $this->assertSame(['example-prod-123456', '123456789012'], $bucket['account_aliases']);
        $this->assertSame('Example Prod', $bucket['account_name']);
        $this->assertSame('example-prod-exports', $bucket['attributes']['name']);
        $this->assertSame('//storage.googleapis.com/example-prod-exports', $bucket['attributes']['source_id']);
        $this->assertSame(CloudExport::time('2025-06-01T08:00:00Z'), $bucket['attributes']['first_seen_at']);
        $this->assertArrayNotHasKey('last_seen_at', $bucket['attributes'], 'updateTime is not a last-seen time');
        $this->assertNull($bucket['state']);
        $this->assertSame('terminated', $instance['state']);
        $this->assertSame('europe-west2-b', $instance['attributes']['region']);
        $this->assertSame('192.0.2.50', $address['attributes']['public_ips']);
        $this->assertSame('compute.googleapis.com/Route', $route['provider_type']);

        [$sql, $topic] = self::parse('gcp', 'gcp-assets.csv');
        $this->assertSame('sqladmin.googleapis.com/Instance', $sql['provider_type']);
        $this->assertNull($sql['state'], 'RUNNABLE is Cloud SQL\'s normal state');
        $this->assertSame('example-prod-123456', $sql['account']);
        $this->assertSame('orders', $topic['attributes']['name'], 'no display name: the last part of the name');

        // REST {"results": [...]}, a project number only, a row without a full resource name
        $records = (new GcpExport())->parse(json_encode(['results' => [
            ['name' => '//storage.googleapis.com/b1', 'assetType' => 'storage.googleapis.com/Bucket', 'project' => 'projects/999999999999'],
            ['name' => 'b2', 'assetType' => 'storage.googleapis.com/Bucket'],
            7,
        ]]));
        $this->assertSame('999999999999', $records[0]['account']);
        $this->assertNull($records[0]['account_name']);
        $this->assertSame('No full resource name (//service.googleapis.com/...).', $records[1]['error']);
        $this->assertNotNull($records[2]['error']);
    }

    public function testExportErrorsAndHelpers(): void
    {
        $this->assertSame(['aws', 'azure', 'gcp'], CloudExport::providers());
        $this->assertSame('aws', CloudExport::for('aws')::provider());
        $this->assertSame('azure', CloudExport::for('azure')::provider());
        $this->assertSame('gcp', CloudExport::for('gcp')::provider());
        foreach ([
            ['other', '{}', "Unknown provider 'other'"],
            ['aws', "\xEF\xBB\xBF  \n", 'The file is empty.'],
            ['aws', '{"Resources": [', 'The file is not valid JSON'],
            ['aws', '{"hello": 1}', 'Not an AWS Resource Explorer or AWS Config export: expected'],
            ['azure', '{"hello": 1}', 'Not an Azure Resource Graph export: expected'],
            ['gcp', '{"hello": 1}', 'Not a Google Cloud Asset Inventory export: expected'],
            ['aws', "Identifier,Region\nx,y\n", 'Not an AWS Resource Explorer or AWS Config export: no resourcetype / type column.'],
            ['azure', "name,type\nx,y\n", 'no id / resourceid column'],
            ['gcp', "name,location\nx,y\n", 'no assettype column'],
        ] as [$provider, $content, $message]) {
            try {
                CloudExport::for($provider)->parse($content);
                $this->fail("$provider: $message");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        // Blank lines and repeated headers are ignored
        $this->assertCount(1, (new GcpExport())->parse("name,assetType,name\n\n//storage.googleapis.com/b,storage.googleapis.com/Bucket,x\n"));

        $this->assertSame('resourcetype', CloudExport::key(' Resource_Type '));
        $this->assertNull(CloudExport::time(null));
        $this->assertNull(CloudExport::time(' '));
        $this->assertNull(CloudExport::time('not a date'));
        $this->assertSame('2026-07-27 07:00:00', CloudExport::time('2026-07-27T06:00:00Z', new DateTimeZone('Europe/London')));
        $long = (new AzureExport())->parse(json_encode([['id' => '/subscriptions/00000000-0000-0000-0000-000000000002/resourceGroups/rg/providers/Microsoft.Compute/disks/d', 'name' => str_repeat('n', 200)]]));
        $this->assertSame(128, mb_strlen($long[0]['attributes']['name']));
    }

    // ---- Type map ---------------------------------------------------------------

    public function testTypeMap(): void
    {
        $this->assertSame('instance', CloudTypeMap::type('aws', 'ec2:instance'));
        $this->assertSame('instance', CloudTypeMap::type('aws', 'AWS::EC2::Instance'));
        $this->assertSame('bucket', CloudTypeMap::type('azure', 'Microsoft.Storage/storageAccounts'));
        $this->assertSame('serverless', CloudTypeMap::type('azure', 'microsoft.web/sites#functionapp'));
        $this->assertSame('database', CloudTypeMap::type('gcp', 'sqladmin.googleapis.com/Instance'));
        $this->assertSame('dns_zone', CloudTypeMap::type('cloudflare', 'zone'));
        $this->assertNull(CloudTypeMap::type('aws', 'ec2:network-interface'));
        // Subnets and individual snapshots would bury the register; backup vaults stay
        foreach (['aws' => ['ec2:subnet', 'AWS::EC2::Subnet', 'ec2:snapshot', 'rds:snapshot', 'rds:cluster-snapshot', 'AWS::RDS::DBSnapshot'],
            'azure' => ['Microsoft.Network/virtualNetworks/subnets', 'Microsoft.Compute/snapshots'],
            'gcp' => ['compute.googleapis.com/Subnetwork', 'compute.googleapis.com/Snapshot']] as $provider => $skipped) {
            foreach ($skipped as $type) {
                $this->assertNull(CloudTypeMap::type($provider, $type), "$provider $type");
            }
        }
        $this->assertSame('backup', CloudTypeMap::type('aws', 'backup:backup-vault'));
        $this->assertSame('backup', CloudTypeMap::type('gcp', 'backupdr.googleapis.com/BackupVault'));
        $this->assertNull(CloudTypeMap::type('github', 'repository'));
        $this->assertSame([], CloudTypeMap::forProvider('github'));

        // Every mapping targets a catalogue type, and every provider covers the main security-relevant ones
        $types = CloudCatalogue::typeOptions();
        foreach (['aws', 'azure', 'gcp'] as $provider) {
            $map = CloudTypeMap::forProvider($provider);
            $this->assertSame([], array_diff($map, array_keys($types)), $provider);
            foreach (['instance', 'container_cluster', 'serverless', 'vpc', 'security_group', 'load_balancer', 'public_ip', 'bucket', 'volume',
                'backup', 'database', 'cache', 'queue', 'iam_role', 'kms_key', 'secret_store', 'certificate', 'dns_zone', 'waf'] as $type) {
                $this->assertContains($type, $map, "$provider has no $type mapping");
            }
        }
    }

    // ---- Importer ---------------------------------------------------------------

    /** The CSV written by scripts/aws-inventory.sh (scripts/tests/aws-inventory-test.sh keeps the fixture in step with the script) */
    public function testAwsInventoryScriptCsv(): void
    {
        $records = self::parse('aws', 'aws-inventory.csv');
        $this->assertCount(57, $records);
        [$settings] = array_values(array_filter($records, fn($r) => $r['provider_type'] === 'account'));
        $resources = array_values(array_filter($records, fn($r) => $r['provider_type'] !== 'account'));
        $this->assertCount(56, $resources);
        foreach ($resources as $record) {
            $this->assertNull($record['error'], $record['provider_type']);
            $this->assertNotNull(CloudTypeMap::type('aws', $record['provider_type']), $record['provider_type'] . ' is imported');
            $this->assertSame('111122223333', $record['account']);
            $this->assertSame('example-prod', $record['account_name'], 'named after the account alias');
            $this->assertStringStartsWith('arn:aws:', $record['attributes']['source_id']);
            $this->assertSame($record['provider_type'], $record['attributes']['provider_type']);
        }
        // The script collects every Resource Explorer type the import keeps ("wafv2:webacl" is only a spelling of the two scoped ones)
        $explorerTypes = array_filter(array_keys(CloudTypeMap::forProvider('aws')), fn($t) => !str_starts_with($t, 'aws::') && $t !== 'wafv2:webacl');
        $this->assertSame([], array_values(array_diff($explorerTypes, array_column($records, 'provider_type'))));

        $this->assertSame(['111122223333', 'example-prod', []], [$settings['account'], $settings['account_name'], $settings['attributes']]);
        $this->assertSame(['root_mfa' => true, 'cloudtrail_multi_region' => true, 's3_block_public_access' => 'none', 'guardduty_off' => ['us-east-1']],
            array_intersect_key($settings['account_properties'], array_flip(['root_mfa', 'cloudtrail_multi_region', 's3_block_public_access', 'guardduty_off'])));

        $byArn = array_column(array_map(fn($r) => $r['attributes'] + ['type' => $r['provider_type']], $resources), null, 'resource_id');
        $instance = $byArn['arn:aws:ec2:eu-west-2:111122223333:instance/i-0a1b2c3d4e5f60001'];
        $this->assertSame(['portal-web-01', 'eu-west-2', '203.0.113.10', null, true, 'Ubuntu', '22.04', 'ec2-203-0-113-10.eu-west-2.compute.amazonaws.com'],
            [$instance['name'], $instance['region'], $instance['public_ips'], $instance['last_seen_at'], $instance['internet_facing'], $instance['os'],
                $instance['os_version'], $instance['public_endpoints']]);
        $this->assertSame('optional', json_decode($instance['properties'], true)['imds']);
        $this->assertSame('i-0a1b2c3d4e5f60002', $byArn['arn:aws:ec2:eu-west-2:111122223333:instance/i-0a1b2c3d4e5f60002']['name'], 'untagged: from the ARN');
        $this->assertSame('billing-events', $byArn['arn:aws:sqs:eu-west-2:111122223333:billing-events']['name']);
        $this->assertSame('portal "static" assets', $byArn['arn:aws:cloudfront::111122223333:distribution/E1ABCDEF']['name']);
        $this->assertSame('d1.cloudfront.net, static.example.com', $byArn['arn:aws:cloudfront::111122223333:distribution/E1ABCDEF']['public_endpoints']);
        $this->assertSame('office, london', $byArn['arn:aws:ec2:eu-west-2:111122223333:vpn-connection/vpn-0123456789abcdef0']['name']);
        $this->assertSame('global', $byArn['arn:aws:iam::111122223333:role/ci/deploy']['region']);
        $this->assertSame('eu-west-2', $byArn['arn:aws:s3:::example-portal-uploads']['region'], 'bucket Region from list-buckets');
        $this->assertSame([true, 'yes'], [$byArn['arn:aws:s3:::example-old-logs']['internet_facing'], $byArn['arn:aws:s3:::example-old-logs']['encryption_at_rest']]);
        $this->assertSame([false, 'yes'], [$byArn['arn:aws:s3:::example-portal-uploads']['internet_facing'], $byArn['arn:aws:s3:::example-portal-uploads']['encryption_at_rest']]);
        $this->assertSame('no', $byArn['arn:aws:rds:eu-west-2:111122223333:db:portal-db']['encryption_at_rest']);
        $this->assertArrayNotHasKey('properties', array_filter($byArn['arn:aws:ecs:eu-west-2:111122223333:cluster/jobs']), 'nothing collected: no configuration');
        $this->assertSame('elasticloadbalancing:loadbalancer/net', $byArn['arn:aws:elasticloadbalancing:eu-west-2:111122223333:loadbalancer/net/sftp/0123456789abcdef']['type']);
        $this->assertSame('arn:aws:apigateway:eu-west-2::/restapis/a1b2c3d4e5', $byArn['arn:aws:apigateway:eu-west-2::/restapis/a1b2c3d4e5']['source_id'], 'no account in the ARN: from the Account ID column');

        [$results, $importer] = $this->import($records);
        $this->assertSame(array_merge(['updated'], array_fill(0, 56, 'created')), array_column($results, 'action'), 'the account settings come first');
        $this->assertSame([], $importer->skippedTypes);
        $this->assertCount(1, $importer->createdAccounts);
        $account = CloudAccount::findOne($importer->createdAccounts[0]->id);
        $this->assertSame(['example-prod', self::NOW], [$account->name, $account->properties_at]);
        $this->assertSame($account->id, $results[0]['account']->id);
        $this->assertNull($results[0]['asset']);
        $this->assertSame(['medium', 'low'], array_values(array_unique(array_column($account->getFindings(), 'severity'))));
        $this->assertContains('GuardDuty off in 1 Region: us-east-1', array_column($account->getFindings(), 'title'));

        $asset = fn(string $arn) => CloudAsset::findOne(['source' => 'aws', 'source_id' => $arn]);
        $bucket = $asset('arn:aws:s3:::example-old-logs');
        $this->assertSame([4, 'critical', 'S3 bucket', 's3:bucket', 1], [(int) $bucket->finding_count, $bucket->finding_severity, $bucket->getKindLabel(),
            $bucket->provider_type, (int) $bucket->internet_facing]);
        $this->assertSame([0, null, 'yes'], [(int) $asset('arn:aws:s3:::example-portal-uploads')->finding_count,
            $asset('arn:aws:s3:::example-portal-uploads')->finding_severity, $asset('arn:aws:s3:::example-portal-uploads')->encryption_at_rest]);
        $this->assertContains('Console password without MFA', array_column($asset('arn:aws:iam::111122223333:user/break-glass')->getFindings(), 'title'));
        $this->assertContains('Can be assumed from AWS account 999988887777 without a condition such as an external ID',
            array_column($asset('arn:aws:iam::111122223333:role/partner-audit')->getFindings(), 'title'));
        $this->assertSame('high', $asset('arn:aws:ec2:eu-west-2:111122223333:security-group/sg-0123456789abcdef1')->finding_severity, 'open SSH');
        $this->assertSame('high', $asset('arn:aws:rds:eu-west-2:111122223333:db:portal-db')->finding_severity);
        $this->assertSame(['Ubuntu', '22.04'], [$asset('arn:aws:ec2:eu-west-2:111122223333:instance/i-0a1b2c3d4e5f60001')->os,
            $asset('arn:aws:ec2:eu-west-2:111122223333:instance/i-0a1b2c3d4e5f60001')->os_version]);

        // Importing the same file again changes nothing but the seen times
        [$again] = $this->import($records);
        $this->assertSame(array_fill(0, 57, 'unchanged'), array_column($again, 'action'));
    }

    /** The configuration and what it answers are the source's: refreshed on every import, while the analyst keeps the rest */
    public function testImportSyncsConfiguration(): void
    {
        $account = $this->makeAccount();
        $config = fn(array $properties) => ['provider_type' => 's3:bucket', 'properties' => json_encode($properties)]
            + array_filter(AwsConfig::derive('s3:bucket', $properties), fn($v) => $v !== null);
        $bucket = fn(array $attributes) => self::record($attributes + ['name' => 'exports', 'resource_id' => 'arn:aws:s3:::exports',
            'source_id' => 'arn:aws:s3:::exports', 'region' => 'eu-west-2'], ['provider_type' => 's3:bucket']);
        $public = ['block_public_access' => 'none', 'block_public_access_off' => ['RestrictPublicBuckets'], 'policy_public' => true, 'encrypted' => false];

        [[$r]] = $this->import([$bucket($config($public))]);
        $this->assertSame('created', $r['action']);
        $asset = CloudAsset::findOne($r['asset']->id);
        $this->assertSame([1, 'no', 'critical', 3], [(int) $asset->internet_facing, $asset->encryption_at_rest, $asset->finding_severity, (int) $asset->finding_count]);
        $this->assertSame('S3 bucket', $asset->getProviderTypeLabel());

        // The analyst's answers are replaced when the configuration answers them; their own fields stay
        $asset->setAttributes(['internet_facing' => false, 'encryption_at_rest' => 'unknown', 'criticality' => 'critical']);
        $this->assertTrue($asset->save());
        $this->assertSame('critical', $asset->finding_severity, 'findings come from the configuration, not the form');
        $fixed = ['block_public_access' => 'all', 'policy_public' => false, 'acl_public' => false, 'encrypted' => true, 'versioning' => 'Enabled'];
        [[$r]] = $this->import([$bucket($config($fixed))]);
        $this->assertSame('updated', $r['action']);
        $this->assertEqualsCanonicalizing(['encryption_at_rest', 'properties', 'findings', 'finding_count', 'finding_severity'], $r['changed']);
        $asset = CloudAsset::findOne($asset->id);
        $this->assertSame([0, 'yes', null, 0, null, 'critical'], [(int) $asset->internet_facing, $asset->encryption_at_rest, $asset->finding_severity,
            (int) $asset->finding_count, $asset->findings, $asset->criticality]);
        $this->assertSame($fixed, $asset->getProperties());

        // An export without configuration (Resource Explorer) keeps the last one
        [[$r]] = $this->import([$bucket(['provider_type' => 's3:bucket'])]);
        $this->assertSame('unchanged', $r['action']);
        $this->assertSame($fixed, CloudAsset::findOne($asset->id)->getProperties());

        // The audit trail records the finding count and severity, not the JSON
        $changes = array_merge(...array_map(fn($row) => array_keys(json_decode($row, true) ?: []),
            (new \yii\db\Query())->select('changes')->from('audit_log')->where(['entity_type' => 'cloud_asset', 'entity_id' => $asset->id])->column()));
        $this->assertContains('finding_count', $changes);
        $this->assertNotContains('properties', $changes);
        $this->assertNotContains('findings', $changes);
        $this->assertSame($account->id, (int) $asset->account_id);
    }

    public function testImportAccountSettings(): void
    {
        $account = $this->makeAccount(['account_identifier' => '444455556666']);
        $settings = fn(array $properties, string $id = '444455556666', array $extra = []) => self::record([], $extra + [
            'provider_type' => 'account', 'account' => $id, 'account_properties' => $properties]);

        // Forced into an account; a second import with the same settings changes nothing
        [[$r]] = $this->import([$settings(['root_mfa' => false, 'cloudtrail_multi_region' => true], '999999999999')], $account);
        $this->assertSame(['updated', null], [$r['action'], $r['asset']]);
        $this->assertContains('properties', $r['changed']);
        $account = CloudAccount::findOne($account->id);
        $this->assertSame([1, 'critical', ['Root user has no MFA'], self::NOW], [(int) $account->finding_count, $account->finding_severity,
            array_column($account->getFindings(), 'title'), $account->properties_at]);
        $this->assertSame(['Root user MFA', 'No'], $account->getConfigFacts()[0]);
        [[$r]] = $this->import([$settings(['root_mfa' => false, 'cloudtrail_multi_region' => true])]);
        $this->assertSame('unchanged', $r['action']);

        // Empty settings clear the configuration; an older import does not move the time back
        $importer = new CloudImporter('2026-01-01 00:00:00');
        [$r] = $importer->import([$settings([])], 'Imported in a test.');
        $this->assertSame('updated', $r['action']);
        $account = CloudAccount::findOne($account->id);
        $this->assertSame([null, 0, null, self::NOW], [$account->properties, (int) $account->finding_count, $account->finding_severity, $account->properties_at]);
        $this->assertFalse($account->hasConfiguration());
        $this->assertSame([], $account->getConfigFacts());

        // Settings of an account that cannot be found or created
        [[$r]] = $this->import([$settings(['root_mfa' => true], 'not-an-id')]);
        $this->assertSame('failed', $r['action']);
        $this->assertStringContainsString('is not a 12-digit AWS account ID', $r['errors'][0]);
        // A broken Details column fails the row
        $broken = (new AwsExport())->parse("ARN,Resource type,Region,Account ID,Name,Public IP,Details\n"
            . "arn:aws:s3:::b,s3:bucket,eu-west-2,111122223333,b,,not json\narn:aws:iam::111122223333:root,account,global,111122223333,,,\n");
        $this->assertSame('The Details column is not a JSON object.', $broken[0]['error']);
        $this->assertSame([], $broken[1]['account_properties'], 'an account row without details');
    }

    public function testImportCreatesAccountsAndAssetsAndSkipsUnmappedTypes(): void
    {
        [$results, $importer] = $this->import(self::parse('aws', 'aws-resource-explorer.json'));
        $this->assertSame(['created', 'created', 'created', 'created', 'skipped'], array_column($results, 'action'));
        $this->assertSame(['ec2:network-interface' => 1], $importer->skippedTypes);

        $this->assertCount(1, $importer->createdAccounts);
        $account = $importer->createdAccounts[0];
        $this->assertSame('AWS account 111122223333', $account->name);
        $this->assertSame(['aws', '111122223333', 'production', 'medium', 'internal'],
            [$account->provider, $account->account_identifier, $account->environment, $account->criticality, $account->data_classification]);
        $this->assertStringContainsString('Created by the import', $account->notes);

        $instance = $results[0]['asset'];
        $this->assertSame($account->id, (int) $instance->account_id);
        $this->assertSame(['instance', 'production', 'active', 'medium', 'internal', 'unknown'],
            [$instance->resource_type, $instance->environment, $instance->status, $instance->criticality, $instance->data_classification, $instance->encryption_at_rest]);
        $this->assertSame('Imported in a test.', $instance->notes);
        $this->assertSame($instance->last_seen_at, $instance->first_seen_at, 'first seen: the earliest time known');
        $this->assertSame('load_balancer', $results[2]['asset']->resource_type);

        // Re-import: nothing changes; an older export doesn't move last seen back
        [$again] = $this->import(self::parse('aws', 'aws-resource-explorer.json'));
        $this->assertSame(['unchanged', 'unchanged', 'unchanged', 'unchanged', 'skipped'], array_column($again, 'action'));
    }

    public function testImportUpdatesOnlyWhatTheSourceOwns(): void
    {
        $account = $this->makeAccount();
        [[$first]] = $this->import([self::record(['last_seen_at' => '2026-10-01 06:00:00', 'first_seen_at' => '2026-09-01 06:00:00'])]);
        $asset = CloudAsset::findOne($first['asset']->id);
        $this->assertSame($account->id, (int) $asset->account_id, 'existing account matched');
        $this->assertSame('staging', $asset->environment, "from the account");

        // The analyst's fields and edits
        $asset->setAttributes(['name' => 'Portal web 1', 'criticality' => 'critical', 'data_classification' => 'restricted', 'internet_facing' => true,
            'encryption_at_rest' => 'yes', 'status' => 'stopped', 'environment' => 'production', 'notes' => 'Hand notes', 'os' => 'Amazon Linux']);
        $this->assertTrue($asset->save());
        $asset->archive();

        [[$r]] = $this->import([self::record(['name' => 'portal-web-01-renamed', 'region' => 'eu-west-1', 'os' => 'Ubuntu', 'os_version' => '22.04',
            'public_ips' => '10.0.0.4, 203.0.113.9', 'last_seen_at' => '2026-10-20 06:00:00', 'first_seen_at' => '2026-08-01 06:00:00'])]);
        $this->assertSame('updated', $r['action']);
        $this->assertEqualsCanonicalizing(['region', 'os_version', 'public_ips', 'last_seen_at', 'first_seen_at'], $r['changed']);
        $asset = CloudAsset::findOne($asset->id);
        $this->assertSame(['Portal web 1', 'critical', 'restricted', 1, 'yes', 'stopped', 'production', 'Hand notes', 'Amazon Linux'],
            [$asset->name, $asset->criticality, $asset->data_classification, (int) $asset->internet_facing, $asset->encryption_at_rest, $asset->status,
                $asset->environment, $asset->notes, $asset->os]);
        $this->assertNotNull($asset->archived_at, 'archived assets are matched and stay archived');
        $this->assertSame('eu-west-1', $asset->region);
        $this->assertSame('22.04', $asset->os_version, 'filled while empty');
        $this->assertSame('203.0.113.9', $asset->public_ips, 'private addresses dropped');
        $this->assertSame('2026-08-01 06:00:00', $asset->first_seen_at);

        // Last seen only moves forward, first seen only back; private-only IPs and empty values change nothing
        [[$r]] = $this->import([self::record(['public_ips' => '10.0.0.5', 'region' => '', 'last_seen_at' => '2026-10-02 06:00:00', 'first_seen_at' => '2026-09-01 06:00:00'])]);
        $this->assertSame('unchanged', $r['action']);
        $asset = CloudAsset::findOne($asset->id);
        $this->assertSame(['eu-west-1', '2026-10-20 06:00:00', '2026-08-01 06:00:00', '203.0.113.9'],
            [$asset->region, $asset->last_seen_at, $asset->first_seen_at, $asset->public_ips]);
    }

    public function testImportAdoptsHandEnteredAssetsAndUsesForcedAccounts(): void
    {
        $account = $this->makeAccount();
        $manual = new CloudAsset(['account_id' => $account->id, 'name' => 'Portal web', 'resource_type' => 'instance', 'status' => 'active',
            'criticality' => 'high', 'data_classification' => 'internal', 'resource_id' => 'arn:aws:ec2:eu-west-2:111122223333:instance/i-01']);
        $this->assertTrue($manual->save());

        [[$r]] = $this->import([self::record()]);
        $this->assertSame('updated', $r['action']);
        $this->assertSame($manual->id, $r['asset']->id);
        $this->assertContains('source_id', $r['changed']);
        $this->assertSame('aws', CloudAsset::findOne($manual->id)->source);

        // --account: every asset in that account, whatever the rows say
        $other = $this->makeAccount(['name' => 'Shared', 'account_identifier' => '444455556666']);
        [$results, $importer] = $this->import([
            self::record(['source_id' => 'x-1', 'resource_id' => 'x-1'], ['account' => null]),
            self::record(['source_id' => 'x-2', 'resource_id' => 'x-2'], ['account' => '999999999999']),
        ], $other);
        $this->assertSame(['created', 'created'], array_column($results, 'action'));
        $this->assertSame([$other->id, $other->id], array_map(static fn($r) => (int) $r['asset']->account_id, $results));
        $this->assertSame([], $importer->createdAccounts);
    }

    public function testImportMatchesAccountsByAliasAndReportsFailures(): void
    {
        $gcp = $this->makeAccount(['name' => 'Prod project', 'provider' => 'gcp', 'account_identifier' => '123456789012']);
        $records = self::parse('gcp', 'gcp-assets.json');
        [$results, $importer] = $this->import($records);
        $this->assertSame(['skipped', 'created', 'created', 'created', 'skipped'], array_column($results, 'action'));
        $this->assertSame([], $importer->createdAccounts, 'matched on the project number');
        $this->assertSame($gcp->id, (int) $results[1]['asset']->account_id);
        $this->assertSame('192.0.2.50', $results[3]['asset']->public_ips);
        $this->assertSame('terminated', $results[2]['state']);

        [$results, $importer] = $this->import([
            self::record([], ['provider_type' => '']),                               // no type: skipped
            self::record([], ['error' => 'No ARN or resource ID.']),
            self::record([], ['account' => null]),
            self::record([], ['account' => '1234']),
            self::record([], ['provider' => 'other', 'provider_type' => 'x']),       // nothing maps for "other"
            self::record(['source_id' => 'bad-region', 'resource_id' => 'bad-region', 'region' => 'eu west$']),
        ]);
        $this->assertSame(['skipped', 'failed', 'failed', 'failed', 'skipped', 'failed'], array_column($results, 'action'));
        $this->assertSame(['(no type)' => 1, 'x' => 1], $importer->skippedTypes);
        $this->assertSame(['No ARN or resource ID.'], $results[1]['errors']);
        $this->assertStringContainsString('--account=ACC-xxxx', $results[2]['errors'][0]);
        $this->assertSame(['"1234" is not a 12-digit AWS account ID.'], $results[3]['errors']);
        $this->assertStringContainsString('Region may contain', $results[5]['errors'][0]);
        $this->assertNull($results[5]['asset']->id);

        // An update that fails validation; an account that cannot be created
        $this->assertSame('created', $this->import([self::record(['source_id' => 's-1', 'resource_id' => 's-1'])])[0][0]['action']);
        $this->assertSame('failed', $this->import([self::record(['source_id' => 's-1', 'resource_id' => 's-1', 'region' => 'eu west$'])])[0][0]['action']);
        [[$r]] = $this->import([self::record([], ['account' => '555566667777'])], null, str_repeat('x', 20001));
        $this->assertSame('failed', $r['action']);
        $this->assertStringStartsWith('Could not create the account:', $r['errors'][0]);
        $this->assertFalse(CloudImporter::isValidIdentifier('other', 'x'));
        $this->assertTrue(CloudImporter::isValidIdentifier('azure', '00000000-0000-0000-0000-000000000001'));
    }

    // ---- Cloudflare zone sync -----------------------------------------------------

    public function testCloudflareZones(): void
    {
        try {
            CloudflareZones::records();
            $this->fail('No token');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CLOUDFLARE_API_TOKEN', $e->getMessage());
        }

        putenv('CLOUDFLARE_API_TOKEN=test-token');
        $account = ['id' => '0123456789abcdef0123456789abcdef', 'name' => 'Example Ltd'];
        $this->zonePages = [
            [['id' => '023e105f4ecef8ad9ca31a8372d0c353', 'name' => 'example.com', 'status' => 'active', 'paused' => false, 'account' => $account, 'created_on' => '2024-01-02T03:04:05.123Z']],
            [['id' => '1234567890abcdef1234567890abcdef', 'name' => 'example.net', 'status' => 'active', 'paused' => true, 'account' => $account],
                ['id' => 'abcdefabcdefabcdefabcdefabcdefab', 'name' => 'example.org', 'status' => 'moved', 'account' => $account],
                ['name' => 'broken.example', 'account' => $account]],
        ];
        $records = CloudflareZones::records();
        $this->assertCount(4, $records);
        $this->assertSame(['cloudflare', 'zone', '0123456789abcdef0123456789abcdef', 'Example Ltd'],
            [$records[0]['provider'], $records[0]['provider_type'], $records[0]['account'], $records[0]['account_name']]);
        $this->assertSame(['example.com', '023e105f4ecef8ad9ca31a8372d0c353', 'global', 'example.com'],
            [$records[0]['attributes']['name'], $records[0]['attributes']['source_id'], $records[0]['attributes']['region'], $records[0]['attributes']['public_endpoints']]);
        $this->assertSame(['internet_facing' => true], $records[0]['create']);
        $this->assertSame([null, 'paused', 'moved'], array_column(array_slice($records, 0, 3), 'state'));
        $this->assertSame('The zone has no ID or name.', $records[3]['error']);

        [$results, $importer] = $this->import($records);
        $this->assertSame(['created', 'created', 'created', 'failed'], array_column($results, 'action'));
        $this->assertSame('Example Ltd', $importer->createdAccounts[0]->name);
        $zone = $results[0]['asset'];
        $this->assertSame(['dns_zone', 1, 'example.com'], [$zone->resource_type, (int) $zone->internet_facing, $zone->public_endpoints]);

        // The analyst marks a zone as not internet-facing: a later sync leaves that alone
        $zone->internet_facing = false;
        $this->assertTrue($zone->save());
        $this->assertSame('unchanged', $this->import(CloudflareZones::records())[0][0]['action']);
        $this->assertSame(0, (int) CloudAsset::findOne($zone->id)->internet_facing);

        // API errors surface; a successful call without a result is an error for the WAF rule code
        $this->zonePages = [];
        try {
            CloudflareZones::records();
            $this->fail('API error');
        } catch (RuntimeException $e) {
            $this->assertSame('Cloudflare: Unauthorized to access requested resource (9109)', $e->getMessage());
        }
        putenv('CLOUDFLARE_ZONE_ID=zone1');
        Http::$fake = static fn(array $r) => ['body' => json_encode(['success' => true, 'result' => null])];
        $this->assertTrue(Cloudflare::hasToken());
        $this->expectExceptionMessage('Cloudflare: the response has no result.');
        Cloudflare::sync([], [], []);
    }

    // ---- Console commands ---------------------------------------------------------

    public function testImportCommand(): void
    {
        $file = self::fixture('azure-resource-graph.json');
        [$code, $out] = OutputCapture::run('cloud/import', [$file, 'provider' => 'azure', 'dryRun' => 1]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('Dry run (nothing saved): 4 created, 0 updated, 0 unchanged, 1 skipped, 0 failed.', $out);
        $this->assertStringContainsString('New account', $out);
        $this->assertStringContainsString('Skipped 1 row(s) of 1 resource type(s) the inventory does not track: microsoft.network/networkinterfaces (1).', $out);
        $this->assertFalse(CloudAsset::find()->where(['source' => 'azure'])->exists());

        $actor = User::findOne($this->userId());
        // --user takes the email as well as the username, like the sign-in form
        [$code, $out] = OutputCapture::run('cloud/import', [$file, 'provider' => 'azure', 'user' => $actor->email]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $vm = CloudAsset::findOne(['name' => 'vm-web-01']);
        $this->assertSame($actor->id, (int) $vm->created_by);
        $this->assertSame('Imported from a Microsoft Azure export on ' . date('Y-m-d') . '.', $vm->notes);

        // Re-import: the same assets (seen again now, so only last seen can change); a cleared region comes back
        Yii::$app->db->createCommand()->update('cloud_asset', ['region' => null], ['id' => $vm->id])->execute();
        [$code, $out] = OutputCapture::run('cloud/import', [$file, 'provider' => 'azure']);
        $this->assertMatchesRegularExpression('/^0 created, \d updated, \d unchanged, 1 skipped, 0 failed\.$/m', $out);
        $this->assertMatchesRegularExpression('/updated\s+' . $vm->reference . '\s+vm-web-01\s+changed: region/', $out);

        // --account: an existing account of the same provider
        $account = CloudAccount::findOne(['provider' => 'azure']);
        [$code, $out] = OutputCapture::run('cloud/import', [self::fixture('azure-resource-graph.csv'), 'provider' => 'azure', 'account' => strtolower($account->reference)]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertSame((int) $account->id, (int) CloudAsset::findOne(['name' => 'kv-prod'])->account_id);

        // More unmapped types than are listed by name; a state in the summary; failed rows exit non-zero
        $rows = [['name' => '//compute.googleapis.com/projects/example-prod-123456/zones/z/instances/i', 'assetType' => 'compute.googleapis.com/Instance',
            'project' => 'projects/123456789012', 'state' => 'STOPPING'], ['name' => 'bad', 'assetType' => 'storage.googleapis.com/Bucket']];
        foreach (range(1, 12) as $i) {
            $rows[] = ['name' => "//x.googleapis.com/t$i", 'assetType' => "x.googleapis.com/Type$i"];
        }
        [$code, $out] = OutputCapture::run('cloud/import', [$this->tmpFile(json_encode($rows)), 'provider' => 'gcp']);
        $this->assertSame(ExitCode::DATAERR, $code, $out);
        $this->assertStringContainsString('(state: stopping)', $out);
        $this->assertStringContainsString('No full resource name', $out);
        $this->assertStringContainsString('Skipped 12 row(s) of 12 resource type(s)', $out);
        $this->assertStringContainsString(' and 2 more.', $out);
    }

    public function testImportCommandErrors(): void
    {
        $file = self::fixture('aws-config.csv');
        $this->assertSame(ExitCode::USAGE, OutputCapture::run('cloud/import', [$file])[0]);
        $this->assertSame(ExitCode::NOINPUT, OutputCapture::run('cloud/import', ['/nonexistent/export.json', 'provider' => 'aws'])[0]);
        [$code, $out] = OutputCapture::run('cloud/import', [$file, 'provider' => 'gcp']);
        $this->assertSame(ExitCode::DATAERR, $code);
        $this->assertStringContainsString('Not a Google Cloud Asset Inventory export', $out);

        $gcp = $this->makeAccount(['name' => 'GCP', 'provider' => 'gcp', 'account_identifier' => 'example-prod-123456']);
        [$code, $out] = OutputCapture::run('cloud/import', [$file, 'provider' => 'aws', 'account' => $gcp->reference]);
        $this->assertSame(ExitCode::DATAERR, $code);
        $this->assertStringContainsString("{$gcp->reference} is not an account of Amazon Web Services.", $out);
        $this->assertSame(ExitCode::DATAERR, OutputCapture::run('cloud/import', [$file, 'provider' => 'aws', 'account' => 'ACC-9999'])[0]);

        [$code, $out] = OutputCapture::run('cloud/import', [$file, 'provider' => 'aws', 'user' => 'no-such-user']);
        $this->assertSame(ExitCode::DATAERR, $code);
        $this->assertStringContainsString("User 'no-such-user' not found", $out);

        // A database error rolls the whole import back
        $handler = static fn() => throw new RuntimeException('Disk full');
        Event::on(CloudAsset::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $handler);
        try {
            [$code, $out] = OutputCapture::run('cloud/import', [$file, 'provider' => 'aws']);
        } finally {
            Event::off(CloudAsset::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $handler);
        }
        $this->assertSame(ExitCode::SOFTWARE, $code);
        $this->assertStringContainsString('Import failed: Disk full', $out);
        $this->assertFalse(CloudAccount::find()->where(['account_identifier' => '444455556666'])->exists(), 'the new account was rolled back');

        $aliases = (new CloudController('cloud', Yii::$app))->optionAliases();
        $this->assertSame(['dryRun', 'provider'], [$aliases['n'], $aliases['p']]);
        $this->assertSame([], array_diff(['provider', 'account', 'user', 'dryRun'], (new CloudController('cloud', Yii::$app))->options('import')));
    }

    public function testSyncCloudflareCommand(): void
    {
        [$code, $out] = OutputCapture::run('cloud/sync-cloudflare');
        $this->assertSame(ExitCode::UNAVAILABLE, $code);
        $this->assertStringContainsString('Cloudflare is not configured', $out);

        putenv('CLOUDFLARE_API_TOKEN=test-token');
        $this->zonePages = [[['id' => '023e105f4ecef8ad9ca31a8372d0c353', 'name' => 'example.com', 'status' => 'pending',
            'account' => ['id' => '0123456789abcdef0123456789abcdef', 'name' => 'Example Ltd']]]];
        [$code, $out] = OutputCapture::run('cloud/sync-cloudflare', ['dryRun' => 1]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('(state: pending)', $out);
        $this->assertStringContainsString('Dry run (nothing saved): 1 created', $out);
        $this->assertFalse(CloudAsset::find()->where(['source' => 'cloudflare'])->exists());

        [$code, $out] = OutputCapture::run('cloud/sync-cloudflare', ['user' => User::findOne($this->userId())->username]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('Synced from Cloudflare on', CloudAsset::findOne(['source' => 'cloudflare'])->notes);
    }
}
