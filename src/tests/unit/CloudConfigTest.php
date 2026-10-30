<?php

namespace tests\unit;

use app\modules\cloud\components\AwsConfig;
use app\modules\cloud\components\CloudConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Imported cloud configuration: AWS type labels, the settings shown, the asset
 * fields they answer (encryption, exposure, endpoints, OS) and the findings.
 */
class CloudConfigTest extends TestCase
{
    private const TODAY = '2026-10-30';

    public function testTypeLabels(): void
    {
        $this->assertSame('S3 bucket', AwsConfig::typeLabel('s3:bucket'));
        $this->assertSame('EBS volume', AwsConfig::typeLabel('EC2:Volume'));
        $this->assertSame('Application Load Balancer', AwsConfig::typeLabel('elasticloadbalancing:loadbalancer/app'));
        $this->assertSame('EC2 Network Interface', AwsConfig::typeLabel('AWS::EC2::NetworkInterface'), 'AWS Config spelling');
        $this->assertSame('lightsail:instance', AwsConfig::typeLabel('lightsail:instance'));
        $this->assertNull(AwsConfig::typeLabel(null));
        $this->assertNull(AwsConfig::typeLabel(''));
        $this->assertSame('S3 bucket', CloudConfig::typeLabel('aws', 's3:bucket'));
        $this->assertSame('Microsoft.Storage/storageAccounts', CloudConfig::typeLabel('azure', 'Microsoft.Storage/storageAccounts'));
        $this->assertNull(CloudConfig::typeLabel('azure', null));
    }

    public function testFacts(): void
    {
        $this->assertSame([
            ['State', 'running'],
            ['Encrypted at rest', 'No'],
            ['KMS key', ''],
            ['Instance metadata tokens (IMDSv2)', 'Required (IMDSv2 only)'],
            ['Security groups', 'sg-1, sg-2'],
            ['Active access keys', 'rotated 2026-01-01, last used never; rotated unknown, last used 2026-10-01'],
            ['Aliases', 'None'],
            ['Size', '30 GB'],
            ['Zz custom setting', 'x'],
            ['Odd', '{"nested":true}'],
        ], AwsConfig::facts([
            'zz_custom_setting' => 'x', 'encrypted' => false, 'state' => 'running', 'security_groups' => ['sg-1', 'sg-2'], 'aliases' => [],
            'size_gb' => 30, 'imds' => 'required', 'kms_key' => null, 'odd' => ['nested' => true],
            'access_keys' => [['last_rotated' => '2026-01-01', 'last_used' => null], ['last_used' => '2026-10-01']],
        ]));
        $this->assertSame([['Instance metadata tokens (IMDSv2)', 'Optional (IMDSv1 allowed)'], ['Active access keys', 'None']],
            AwsConfig::facts(['imds' => 'optional', 'access_keys' => []]));
        $this->assertSame([['Mode', 'strict'], ['Ports', '[22,80]']], CloudConfig::facts('azure', ['mode' => 'strict', 'ports' => [22, 80]]));
    }

    public function testFactsFollowTheLabelOrder(): void
    {
        $labels = array_column(AwsConfig::facts(['zz' => 1, 'encrypted' => true, 'state' => 'on']), 0);
        $this->assertSame(['State', 'Encrypted at rest', 'Zz'], $labels, 'known settings in catalogue order, unknown ones last');
    }

    public static function deriveCases(): array
    {
        $none = ['encryption_at_rest' => null, 'internet_facing' => null, 'public_endpoints' => null, 'os' => null, 'os_version' => null];
        return [
            'instance with a public IP' => ['ec2:instance', ['os' => 'Ubuntu', 'os_version' => '22.04', 'public_dns' => 'ec2.example'], '203.0.113.1',
                ['internet_facing' => true, 'public_endpoints' => 'ec2.example', 'os' => 'Ubuntu', 'os_version' => '22.04'] + $none],
            'instance without' => ['ec2:instance', ['public_dns' => 'ec2.example'], '', ['internet_facing' => false] + $none],
            'numeric OS version' => ['ec2:instance', ['os' => 'Amazon Linux', 'os_version' => 2], null, ['internet_facing' => false, 'os' => 'Amazon Linux', 'os_version' => '2'] + $none],
            'encrypted volume' => ['ec2:volume', ['encrypted' => true], null, ['encryption_at_rest' => 'yes'] + $none],
            'unencrypted file system' => ['elasticfilesystem:file-system', ['encrypted' => false], null, ['encryption_at_rest' => 'no'] + $none],
            'encryption not a boolean' => ['sqs:queue', ['encrypted' => 'maybe'], null, $none],
            'elastic IP' => ['ec2:elastic-ip', [], null, ['internet_facing' => true] + $none],
            'NAT gateway' => ['ec2:natgateway', [], null, ['internet_facing' => false] + $none],
            'public ALB' => ['elasticloadbalancing:loadbalancer/app', ['scheme' => 'internet-facing', 'dns_name' => 'lb.example'], null,
                ['internet_facing' => true, 'public_endpoints' => 'lb.example'] + $none],
            'internal NLB' => ['elasticloadbalancing:loadbalancer/net', ['scheme' => 'internal', 'dns_name' => 'nlb.example'], null, ['internet_facing' => false] + $none],
            'gateway LB' => ['elasticloadbalancing:loadbalancer/gwy', [], null, ['internet_facing' => false] + $none],
            'classic LB, scheme unknown' => ['elasticloadbalancing:loadbalancer', [], null, $none],
            'public bucket (policy)' => ['s3:bucket', ['block_public_access' => 'none', 'block_public_access_off' => ['RestrictPublicBuckets'], 'policy_public' => true,
                'encrypted' => true], null, ['internet_facing' => true, 'encryption_at_rest' => 'yes'] + $none],
            'policy public but restricted' => ['s3:bucket', ['block_public_access' => 'all', 'policy_public' => true, 'acl_public' => false], null,
                ['internet_facing' => false] + $none],
            'bucket, access unknown' => ['s3:bucket', ['encrypted' => true], null, ['encryption_at_rest' => 'yes'] + $none],
            'public database' => ['rds:db', ['publicly_accessible' => true, 'endpoint' => 'db.example', 'encrypted' => false], null,
                ['internet_facing' => true, 'public_endpoints' => 'db.example', 'encryption_at_rest' => 'no'] + $none],
            'private cluster' => ['redshift:cluster', ['publicly_accessible' => false, 'endpoint' => 'rs.example'], null, ['internet_facing' => false] + $none],
            'EKS' => ['eks:cluster', ['endpoint_public' => true], null, ['internet_facing' => true] + $none],
            'function with a URL' => ['lambda:function', ['runtime' => 'python3.12', 'url' => 'https://fn.example/'], null,
                ['internet_facing' => true, 'public_endpoints' => 'https://fn.example/'] + $none],
            'function without' => ['lambda:function', ['runtime' => 'python3.12'], null, ['internet_facing' => false] + $none],
            'function, nothing known' => ['lambda:function', [], null, $none],
            'CloudFront' => ['cloudfront:distribution', ['domain' => 'd1.cloudfront.net', 'aliases' => ['static.example.com']], null,
                ['internet_facing' => true, 'public_endpoints' => 'd1.cloudfront.net, static.example.com'] + $none],
            'private REST API' => ['apigateway:restapis', ['endpoint_type' => 'PRIVATE'], null, ['internet_facing' => false] + $none],
            'regional REST API' => ['apigateway:restapis', ['endpoint_type' => 'REGIONAL'], null, ['internet_facing' => true] + $none],
            'HTTP API' => ['apigateway:apis', ['default_endpoint' => true, 'endpoint' => 'https://api.example'], null,
                ['internet_facing' => true, 'public_endpoints' => 'https://api.example'] + $none],
            'OpenSearch outside a VPC' => ['es:domain', ['in_vpc' => false, 'endpoint' => 'search.example', 'encrypted' => true], null,
                ['internet_facing' => true, 'public_endpoints' => 'search.example', 'encryption_at_rest' => 'yes'] + $none],
            'MSK' => ['kafka:cluster', ['public_access' => false], null, ['internet_facing' => false] + $none],
            'open security group' => ['ec2:security-group', ['open_ingress' => ['tcp/443'], 'in_use' => true], null, ['internet_facing' => true] + $none],
            'unused open group' => ['ec2:security-group', ['open_ingress' => ['tcp/443'], 'in_use' => false], null, ['internet_facing' => false] + $none],
            'closed group' => ['ec2:security-group', ['open_ingress' => []], null, ['internet_facing' => false] + $none],
            'role' => ['iam:role', ['admin' => true], null, $none],
        ];
    }

    #[DataProvider('deriveCases')]
    public function testDerive(string $type, array $properties, ?string $ip, array $expected): void
    {
        $actual = AwsConfig::derive($type, $properties, $ip);
        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
    }

    public function testDeriveDispatch(): void
    {
        $this->assertSame([], CloudConfig::derive('azure', 'microsoft.storage/storageaccounts', ['encrypted' => true]));
        $this->assertSame('yes', CloudConfig::derive('aws', 'ec2:volume', ['encrypted' => true])['encryption_at_rest']);
    }

    public static function findingCases(): array
    {
        return [
            'public bucket' => ['s3:bucket', ['block_public_access' => 'none', 'block_public_access_off' => ['BlockPublicAcls', 'IgnorePublicAcls',
                'BlockPublicPolicy', 'RestrictPublicBuckets'], 'policy_public' => true, 'acl_public' => true, 'encrypted' => false, 'versioning' => 'Off'],
                [['critical', 'Public through the bucket policy'], ['critical', 'Public through the bucket ACL'], ['medium', 'Not encrypted at rest'],
                    ['medium', 'Block Public Access is not fully on'], ['low', 'Versioning off']]],
            'bucket protected by Block Public Access' => ['s3:bucket', ['block_public_access' => 'all', 'policy_public' => true, 'acl_public' => true,
                'versioning' => 'Enabled'], []],
            'bucket, partial BPA, suspended versioning' => ['s3:bucket', ['block_public_access' => 'partial', 'block_public_access_off' => ['BlockPublicAcls'],
                'versioning' => 'Suspended'], [['medium', 'Block Public Access is not fully on'], ['low', 'Versioning suspended']]],
            'bucket, BPA unknown' => ['s3:bucket', ['policy_public' => true], [['critical', 'Public through the bucket policy']]],
            'instance IMDSv1' => ['ec2:instance', ['imds' => 'optional'], [['medium', 'IMDSv1 allowed: instance metadata does not require session tokens']]],
            'instance IMDSv2' => ['ec2:instance', ['imds' => 'required'], []],
            'loose volume' => ['ec2:volume', ['state' => 'available', 'encrypted' => false], [['medium', 'Not encrypted at rest'], ['low', 'Not attached to any instance']]],
            'VPC without flow logs' => ['ec2:vpc', ['flow_logs' => false], [['low', 'No VPC flow logs']]],
            'open SSH and RDP' => ['ec2:security-group', ['open_ingress' => ['tcp/443', 'tcp/22', 'tcp/3389', 'tcp/80'], 'in_use' => true],
                [['high', 'Open to the internet on remote access / database port 22, 3389']]],
            'all traffic' => ['ec2:security-group', ['open_ingress' => ['all'], 'in_use' => true], [['high', 'All traffic open to the internet']]],
            'wide range' => ['ec2:security-group', ['open_ingress' => ['tcp/0-65535', 'udp/5000-5010', 'tcp/6379'], 'in_use' => true],
                [['high', 'Open to the internet on remote access / database port 6379'], ['high', 'Wide port range open to the internet: tcp/0-65535']]],
            'odd rules ignored' => ['ec2:security-group', ['open_ingress' => ['icmp/-1', 'tcp/443'], 'in_use' => true], []],
            'default group with rules' => ['ec2:security-group', ['default' => true, 'rules' => 2, 'in_use' => false],
                [['low', 'Default security group has rules (it should allow nothing)']]],
            'unused group' => ['ec2:security-group', ['default' => false, 'rules' => 1, 'in_use' => false], [['low', 'Not used by any network interface']]],
            'unused default group without rules' => ['ec2:security-group', ['default' => true, 'rules' => 0, 'in_use' => false], []],
            'loose elastic IP' => ['ec2:elastic-ip', ['associated' => false], [['low', 'Not associated with anything']]],
            'classic LB on HTTP' => ['elasticloadbalancing:loadbalancer', ['lb_type' => 'classic', 'scheme' => 'internet-facing', 'listeners' => ['HTTP:80']],
                [['medium', 'Plain HTTP listener without a redirect: HTTP:80'], ['low', 'Classic Load Balancer (previous generation)']]],
            'ALB redirecting, old TLS' => ['elasticloadbalancing:loadbalancer/app', ['scheme' => 'internet-facing', 'listeners' => ['HTTPS:443', 'HTTP:80 redirect'],
                'ssl_policies' => ['ELBSecurityPolicy-2016-08', 'ELBSecurityPolicy-TLS13-1-2-2021-06']],
                [['medium', 'TLS policy allows TLS 1.0 / 1.1: ELBSecurityPolicy-2016-08']]],
            'internal HTTP' => ['elasticloadbalancing:loadbalancer/app', ['scheme' => 'internal', 'listeners' => ['HTTP:80']], []],
            'EKS' => ['eks:cluster', ['endpoint_public' => true, 'public_access_cidrs' => ['0.0.0.0/0'], 'secrets_encrypted' => false, 'logging' => ['api']],
                [['medium', 'Kubernetes API endpoint open to the whole internet'], ['low', 'Kubernetes secrets not encrypted with a KMS key'],
                    ['low', 'Kubernetes audit logging off']]],
            'EKS locked down' => ['eks:cluster', ['endpoint_public' => true, 'public_access_cidrs' => ['198.51.100.0/24'], 'secrets_encrypted' => true,
                'logging' => ['api', 'audit']], []],
            'open function URL, old runtime' => ['lambda:function', ['runtime' => 'python3.8', 'url_auth' => 'NONE'],
                [['high', 'Function URL needs no authentication'], ['medium', 'Runtime python3.8 deprecated since 14 Oct 2024 (no security patches)']]],
            'supported runtime' => ['lambda:function', ['runtime' => 'nodejs22.x', 'url_auth' => 'AWS_IAM'], []],
            'deprecated this year' => ['lambda:function', ['runtime' => 'ruby3.2'], [['medium', 'Runtime ruby3.2 deprecated since 31 Mar 2026 (no security patches)']]],
            'ECR' => ['ecr:repository', ['scan_on_push' => false], [['low', 'Images not scanned on push']]],
            'public database' => ['rds:db', ['publicly_accessible' => true, 'encrypted' => false, 'backup_retention_days' => 0, 'deletion_protection' => false],
                [['high', 'Publicly accessible from the internet'], ['medium', 'Not encrypted at rest'], ['medium', 'Automated backups off'],
                    ['low', 'Deletion protection off']]],
            'Redshift' => ['redshift:cluster', ['publicly_accessible' => true, 'encrypted' => true], [['high', 'Publicly accessible from the internet']]],
            'DynamoDB' => ['dynamodb:table', ['encrypted' => true, 'pitr' => false], [['low', 'Point-in-time recovery off']]],
            'OpenSearch' => ['es:domain', ['in_vpc' => false, 'enforce_https' => false, 'node_to_node_encryption' => false, 'tls_policy' => 'Policy-Min-TLS-1-0-2019-07'],
                [['medium', 'Public endpoint (not in a VPC)'], ['medium', 'HTTPS not enforced'], ['low', 'Node-to-node encryption off'],
                    ['low', 'TLS policy allows TLS 1.0']]],
            'ElastiCache' => ['elasticache:cluster', ['encrypted' => false, 'in_transit_encryption' => false],
                [['medium', 'Not encrypted at rest'], ['low', 'Not encrypted in transit']]],
            'queue' => ['sqs:queue', ['encrypted' => false], [['low', 'Not encrypted at rest']]],
            'MSK' => ['kafka:cluster', ['public_access' => true, 'in_transit_encryption' => 'TLS_PLAINTEXT'],
                [['medium', 'Public access to the brokers is on'], ['medium', 'Clients may connect in plaintext']]],
            'KMS rotation' => ['kms:key', ['key_state' => 'Enabled', 'rotation' => false], [['low', 'Automatic key rotation off']]],
            'KMS deletion' => ['kms:key', ['key_state' => 'PendingDeletion', 'deletion_date' => '2026-11-20'], [['medium', 'Scheduled for deletion on 20 Nov 2026']]],
            'KMS deletion, no date' => ['kms:key', ['key_state' => 'PendingDeletion'], [['medium', 'Scheduled for deletion']]],
            'secret' => ['secretsmanager:secret', ['rotation' => false, 'last_accessed' => '2026-05-01'],
                [['low', 'Automatic rotation off'], ['low', 'Not used for over 90 days']]],
            'secret in use' => ['secretsmanager:secret', ['rotation' => true, 'last_accessed' => '2026-10-29'], []],
            'expired certificate in use' => ['acm:certificate', ['expires' => '2026-10-01', 'in_use' => true], [['high', 'Expired on 1 Oct 2026']]],
            'expired certificate unused' => ['acm:certificate', ['expires' => '2025-01-01', 'in_use' => false], [['low', 'Expired on 1 Jan 2025 (not in use)']]],
            'imported certificate expiring' => ['acm:certificate', ['expires' => '2026-11-15', 'renewal' => 'INELIGIBLE'],
                [['medium', 'Expires on 15 Nov 2026 and does not renew automatically']]],
            'managed certificate expiring' => ['acm:certificate', ['expires' => '2026-11-15', 'renewal' => 'ELIGIBLE'], []],
            'certificate date unreadable' => ['acm:certificate', ['expires' => 'soon'], []],
            'CloudFront' => ['cloudfront:distribution', ['viewer_protocol' => 'allow-all', 'min_tls' => 'TLSv1_2016', 'waf' => false],
                [['medium', 'Viewers may use plain HTTP'], ['medium', 'Allows TLS older than 1.2 (TLSv1_2016)'], ['low', 'No WAF web ACL']]],
            'user without MFA' => ['iam:user', ['console' => true, 'mfa' => false, 'admin' => true, 'password_last_used' => '2026-01-01',
                'access_keys' => [['last_rotated' => '2024-01-01', 'last_used' => null], ['last_rotated' => '2026-10-01', 'last_used' => '2026-10-29']]],
                [['high', 'Console password without MFA'], ['medium', 'Administrator access on a long-lived IAM user'],
                    ['medium', 'Access key not rotated for over 90 days'], ['low', 'Access key never used'], ['low', 'Console password unused for over 90 days']]],
            'key used long ago' => ['iam:user', ['console' => false, 'access_keys' => [['last_rotated' => '2026-09-01', 'last_used' => '2026-06-01']]],
                [['low', 'Access key unused for over 90 days']]],
            'console never used' => ['iam:user', ['console' => true, 'mfa' => true, 'created' => '2025-01-01'], [['low', 'Console password unused for over 90 days']]],
            'new user' => ['iam:user', ['console' => true, 'mfa' => true, 'created' => '2026-10-01', 'access_keys' => []], []],
            'role anyone can assume' => ['iam:role', ['trusted_unconditional' => ['*'], 'last_used' => '2026-10-29'], [['critical', 'Anyone can assume this role']]],
            'role for any account' => ['iam:role', ['trusted_unconditional' => ['arn:aws:iam::*:root'], 'last_used' => '2026-10-29'], [['critical', 'Anyone can assume this role']]],
            'cross-account role' => ['iam:role', ['trusted_unconditional' => ['arn:aws:iam::999988887777:root', '111122223333', 'ec2.amazonaws.com'],
                'admin' => true, 'created' => '2023-01-01'],
                [['medium', 'Can be assumed from AWS account 999988887777 without a condition such as an external ID'], ['low', 'Administrator access'],
                    ['low', 'Never used']]],
            'idle role' => ['iam:role', ['trusted_unconditional' => [], 'last_used' => '2026-01-01'], [['low', 'Not used for over 90 days']]],
            'nothing to say' => ['codecommit:repository', [], []],
        ];
    }

    #[DataProvider('findingCases')]
    public function testFindings(string $type, array $properties, array $expected): void
    {
        $findings = CloudConfig::findings('aws', $type, $properties, self::TODAY, '111122223333');
        $this->assertSame($expected, array_map(static fn($f) => [$f['severity'], $f['title']], $findings));
    }

    public function testAccountFindings(): void
    {
        $findings = CloudConfig::findings('aws', 'account', [
            'root_mfa' => false, 'root_access_keys' => true, 'cloudtrail_multi_region' => false, 's3_block_public_access' => 'partial',
            'guardduty_off' => ['eu-west-1', 'eu-west-2', 'eu-west-3', 'us-east-1', 'us-east-2', 'us-west-1'], 'ebs_encryption_off' => ['us-east-1'],
            'password_policy' => false, 'iam_users' => 3, 'unused_default_vpcs' => ['ap-south-1'],
        ], self::TODAY);
        $this->assertSame([
            ['critical', 'Root user has no MFA'],
            ['critical', 'Root user has access keys'],
            ['high', 'No multi-Region CloudTrail trail is logging'],
            ['medium', 'S3 Block Public Access is not fully on for the account'],
            ['medium', 'GuardDuty off in 6 Regions: eu-west-1, eu-west-2, eu-west-3, us-east-1 and 2 more'],
            ['low', 'EBS encryption by default off in 1 Region: us-east-1'],
            ['low', 'No IAM password policy'],
            ['low', 'Unused default VPCs in 1 Region: ap-south-1'],
        ], array_map(static fn($f) => [$f['severity'], $f['title']], $findings));

        $this->assertSame([], CloudConfig::findings('aws', 'account', ['root_mfa' => true, 'root_access_keys' => false, 'cloudtrail_multi_region' => true,
            's3_block_public_access' => 'all', 'guardduty_off' => [], 'ebs_encryption_off' => [], 'password_policy' => false, 'iam_users' => 0], self::TODAY),
            'no password policy needed without IAM users');
        $this->assertSame([['low', 'IAM password policy allows passwords shorter than 14 characters (8)']], array_map(static fn($f) => [$f['severity'], $f['title']],
            CloudConfig::findings('aws', 'ACCOUNT', ['password_policy' => true, 'password_min_length' => 8], self::TODAY)));
    }

    public function testSeverityHelpers(): void
    {
        $this->assertSame([], CloudConfig::findings('azure', 'microsoft.storage/storageaccounts', ['encrypted' => false], self::TODAY), 'only AWS has rules');
        $this->assertSame('critical', CloudConfig::worst([['severity' => 'low'], ['severity' => 'critical'], ['severity' => 'high']]));
        $this->assertSame('medium', CloudConfig::worst([['severity' => 'low'], ['severity' => 'medium']]));
        $this->assertNull(CloudConfig::worst([]));
        $this->assertSame(['critical', 'high'], CloudConfig::atLeast('high'));
        $this->assertSame(['critical', 'high', 'medium', 'low'], CloudConfig::atLeast('low'));
        $this->assertSame([], CloudConfig::atLeast('nope'));
        // Worst first, whatever order the rules found them in
        $findings = CloudConfig::findings('aws', 'iam:user', ['console' => true, 'mfa' => false, 'admin' => true,
            'access_keys' => [['last_rotated' => '2020-01-01', 'last_used' => '2020-01-02']]], self::TODAY);
        $this->assertSame(['high', 'medium', 'medium', 'low'], array_column($findings, 'severity'));
        $this->assertNotEmpty(CloudConfig::findings('aws', 'ec2:instance', ['imds' => 'optional']), 'defaults to today');
    }
}
