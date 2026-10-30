<?php

namespace app\modules\cloud\components;

use DateTimeImmutable;

/**
 * Reads the AWS configuration scripts/aws-inventory.sh collects (the CSV's
 * Details column): readable labels for the resource types and settings, the
 * asset fields the settings answer (encrypted at rest, internet-facing, public
 * endpoints, OS), and the findings: settings that weaken security, each with a
 * severity. The rules follow the AWS Foundational Security Best Practices and
 * the CIS AWS Foundations Benchmark, kept to what the collected settings show.
 *
 * Properties are flat: booleans, strings, numbers, lists, and for IAM users a
 * list of access keys. A setting the script could not read is left out, and a
 * rule never fires on a missing setting. Titles carry no running day counts,
 * so the findings only change when the configuration (or a threshold) does.
 */
final class AwsConfig
{
    /** Resource Explorer type => label */
    private const TYPE_LABELS = [
        'account' => 'AWS account settings',
        'ec2:instance' => 'EC2 instance', 'ec2:volume' => 'EBS volume', 'ec2:vpc' => 'VPC', 'ec2:security-group' => 'Security group',
        'ec2:network-acl' => 'Network ACL', 'ec2:internet-gateway' => 'Internet gateway', 'ec2:natgateway' => 'NAT gateway',
        'ec2:transit-gateway' => 'Transit gateway', 'ec2:vpn-connection' => 'Site-to-Site VPN connection', 'ec2:vpn-gateway' => 'Virtual private gateway',
        'ec2:elastic-ip' => 'Elastic IP address',
        'elasticloadbalancing:loadbalancer' => 'Classic Load Balancer', 'elasticloadbalancing:loadbalancer/app' => 'Application Load Balancer',
        'elasticloadbalancing:loadbalancer/net' => 'Network Load Balancer', 'elasticloadbalancing:loadbalancer/gwy' => 'Gateway Load Balancer',
        'eks:cluster' => 'EKS cluster', 'ecs:cluster' => 'ECS cluster', 'lambda:function' => 'Lambda function', 'ecr:repository' => 'ECR repository',
        'apigateway:restapis' => 'API Gateway REST API', 'apigateway:apis' => 'API Gateway HTTP / WebSocket API',
        'elasticfilesystem:file-system' => 'EFS file system', 'fsx:file-system' => 'FSx file system', 'backup:backup-vault' => 'AWS Backup vault',
        's3:bucket' => 'S3 bucket', 'rds:db' => 'RDS database instance', 'rds:cluster' => 'RDS / Aurora cluster', 'dynamodb:table' => 'DynamoDB table',
        'es:domain' => 'OpenSearch domain', 'redshift:cluster' => 'Redshift cluster', 'elasticache:cluster' => 'ElastiCache cluster',
        'elasticache:replicationgroup' => 'ElastiCache replication group', 'sqs:queue' => 'SQS queue', 'sns:topic' => 'SNS topic',
        'kinesis:stream' => 'Kinesis data stream', 'kafka:cluster' => 'MSK (Kafka) cluster', 'iam:role' => 'IAM role', 'iam:user' => 'IAM user',
        'kms:key' => 'KMS key', 'secretsmanager:secret' => 'Secrets Manager secret', 'acm:certificate' => 'ACM certificate',
        'cloudfront:distribution' => 'CloudFront distribution', 'route53:hostedzone' => 'Route 53 hosted zone',
        'wafv2:regional/webacl' => 'WAF web ACL (regional)', 'wafv2:global/webacl' => 'WAF web ACL (CloudFront)', 'wafv2:webacl' => 'WAF web ACL',
        'codecommit:repository' => 'CodeCommit repository',
    ];

    /** property => [label, unit] in the order they are shown; unlisted properties follow, labelled from their key */
    private const LABELS = [
        'state' => ['State'], 'status' => ['Status'], 'enabled' => ['Enabled'],
        'block_public_access' => ['Block Public Access'], 'block_public_access_off' => ['Block Public Access settings off'],
        'policy_public' => ['Public bucket policy'], 'acl_public' => ['Public ACL grant'],
        'publicly_accessible' => ['Publicly accessible'], 'scheme' => ['Scheme'], 'in_vpc' => ['In a VPC'], 'public_access' => ['Public access'],
        'endpoint_public' => ['Public API endpoint'], 'public_access_cidrs' => ['Public endpoint open to'], 'endpoint_private' => ['Private API endpoint'],
        'url' => ['Function URL'], 'url_auth' => ['Function URL authentication'],
        'open_ingress' => ['Open to the internet (0.0.0.0/0, ::/0)'], 'in_use' => ['In use'], 'rules' => ['Rules'], 'default' => ['AWS default'],
        'encrypted' => ['Encrypted at rest'], 'encryption' => ['Encryption'], 'kms_key' => ['KMS key'], 'in_transit_encryption' => ['Encryption in transit'],
        'node_to_node_encryption' => ['Node-to-node encryption'], 'secrets_encrypted' => ['Secrets encrypted with KMS'], 'enforce_https' => ['HTTPS enforced'],
        'tls_policy' => ['TLS policy'], 'ssl_policies' => ['TLS policies'], 'min_tls' => ['Minimum TLS version'], 'viewer_protocol' => ['Viewer protocol policy'],
        'listeners' => ['Listeners'], 'waf' => ['WAF web ACL'], 'auth_token' => ['AUTH token'], 'fine_grained_access' => ['Fine-grained access control'],
        'iam_auth' => ['IAM database authentication'],
        'imds' => ['Instance metadata tokens (IMDSv2)'], 'platform' => ['Platform'], 'os' => ['OS (Systems Manager)'], 'os_version' => ['OS version'],
        'ssm_managed' => ['Managed by Systems Manager'], 'instance_type' => ['Instance type'], 'iam_profile' => ['Instance profile'],
        'security_groups' => ['Security groups'], 'private_ip' => ['Private IP'], 'public_dns' => ['Public DNS name'], 'launched' => ['Launched'],
        'console' => ['Console password'], 'mfa' => ['MFA'], 'password_last_used' => ['Password last used'], 'access_keys' => ['Active access keys'],
        'admin' => ['Administrator access'], 'groups' => ['Groups'], 'trusted' => ['Trusted principals'],
        'trusted_unconditional' => ['Trusted without conditions'], 'last_used' => ['Last used'], 'max_session_hours' => ['Maximum session', 'hours'],
        'rotation' => ['Automatic rotation'], 'last_rotated' => ['Last rotated'], 'last_accessed' => ['Last accessed'], 'last_changed' => ['Last changed'],
        'key_state' => ['Key state'], 'key_spec' => ['Key spec'], 'deletion_date' => ['Deletion date'], 'aliases' => ['Aliases'],
        'expires' => ['Expires'], 'cert_type' => ['Certificate type'], 'renewal' => ['Managed renewal'],
        'versioning' => ['Versioning'], 'mfa_delete' => ['MFA delete'], 'pitr' => ['Point-in-time recovery'],
        'backup_retention_days' => ['Automated backups kept', 'days'], 'deletion_protection' => ['Deletion protection'], 'multi_az' => ['Multi-AZ'],
        'auto_minor_upgrade' => ['Automatic minor version upgrades'],
        'engine' => ['Engine'], 'engine_version' => ['Engine version'], 'version' => ['Version'], 'kafka_version' => ['Kafka version'],
        'instance_class' => ['Instance class'], 'node_type' => ['Node type'], 'runtime' => ['Runtime'], 'last_modified' => ['Last modified'],
        'role' => ['Execution role'], 'lb_type' => ['Type'], 'dns_name' => ['DNS name'], 'domain' => ['Domain name'], 'endpoint' => ['Endpoint'],
        'endpoint_type' => ['Endpoint type'], 'protocol' => ['Protocol'], 'default_endpoint' => ['Default execute-api endpoint'],
        'api_key_source' => ['API key source'], 'logging' => ['Control plane logging'], 'flow_logs' => ['Flow logs'], 'cidr' => ['CIDR'],
        'vpc' => ['VPC'], 'description' => ['Description'], 'associated' => ['Associated'], 'instance' => ['Instance'], 'tunnels_up' => ['Tunnels up'],
        'size_gb' => ['Size', 'GB'], 'volume_type' => ['Volume type'], 'attached_to' => ['Attached to'], 'fs_type' => ['File system type'],
        'recovery_points' => ['Recovery points'], 'locked' => ['Vault lock'], 'scan_on_push' => ['Scan images on push'],
        'tag_mutability' => ['Tag mutability'], 'retention_hours' => ['Retention', 'hours'], 'subscriptions' => ['Confirmed subscriptions'],
        'cluster_type' => ['Cluster type'], 'cluster' => ['Cluster'], 'private' => ['Private zone'],
        'records' => ['Records'], 'scope' => ['Scope'], 'path' => ['Path'], 'created' => ['Created'],
        // Account settings
        'alias' => ['Account alias'], 'root_mfa' => ['Root user MFA'], 'root_access_keys' => ['Root user access keys'], 'iam_users' => ['IAM users'],
        'password_policy' => ['IAM password policy'], 'password_min_length' => ['Minimum password length'],
        'cloudtrail_multi_region' => ['Multi-Region CloudTrail trail logging'], 'cloudtrail_trails' => ['CloudTrail trails logging'],
        's3_block_public_access' => ['S3 Block Public Access (account)'], 'guardduty_off' => ['GuardDuty off in'],
        'ebs_encryption_off' => ['EBS encryption by default off in'], 'unused_default_vpcs' => ['Unused default VPCs in'], 'regions' => ['Regions scanned'],
    ];

    /** Ports that should never be open to the whole internet: remote administration, databases, file sharing, management APIs */
    private const SENSITIVE_PORTS = [
        20, 21, 22, 23, 25, 110, 135, 137, 138, 139, 143, 445, 1433, 1434, 1521, 2049, 2375, 2376, 2379, 2380, 3000, 3306, 3389, 4333,
        5432, 5500, 5601, 5800, 5900, 5984, 6379, 7001, 8020, 8086, 8888, 9042, 9092, 9200, 9300, 11211, 27017, 27018, 50070,
    ];

    /** Lambda runtimes => the date AWS stopped patching them (deprecation) */
    private const LAMBDA_DEPRECATED = [
        'nodejs' => '2016-10-31', 'nodejs4.3' => '2020-03-05', 'nodejs6.10' => '2019-08-12', 'nodejs8.10' => '2020-03-06',
        'nodejs10.x' => '2021-07-30', 'nodejs12.x' => '2023-03-31', 'nodejs14.x' => '2023-12-04', 'nodejs16.x' => '2024-06-12',
        'nodejs18.x' => '2025-09-01', 'python2.7' => '2021-07-15', 'python3.6' => '2022-07-18', 'python3.7' => '2023-12-04',
        'python3.8' => '2024-10-14', 'python3.9' => '2025-12-15', 'ruby2.5' => '2021-07-30', 'ruby2.7' => '2023-12-07',
        'ruby3.2' => '2026-03-31', 'java8' => '2024-01-08', 'dotnetcore1.0' => '2019-07-30', 'dotnetcore2.0' => '2019-05-30',
        'dotnetcore2.1' => '2022-01-05', 'dotnetcore3.1' => '2023-04-03', 'dotnet5.0' => '2022-05-10', 'dotnet6' => '2024-12-20',
        'dotnet7' => '2024-05-14', 'go1.x' => '2024-01-08', 'provided' => '2024-01-08',
    ];

    /** Inactivity after which a credential or role counts as unused */
    public const UNUSED_DAYS = 90;
    /** Access key age after which it should have been rotated */
    public const KEY_ROTATION_DAYS = 90;
    /** Certificates that do not renew themselves are flagged this long before they expire */
    public const CERT_WARNING_DAYS = 30;

    public static function typeLabel(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        $key = strtolower($type);
        if (isset(self::TYPE_LABELS[$key])) {
            return self::TYPE_LABELS[$key];
        }
        // AWS Config's spelling: "AWS::EC2::Volume" => "EC2 Volume"
        if (preg_match('/^aws::([^:]+)::(.+)$/i', $type, $m)) {
            return $m[1] . ' ' . preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $m[2]);
        }
        return $type;
    }

    /**
     * @return list<array{0:string, 1:string}> [label, value] for display, known settings first
     */
    public static function facts(array $properties): array
    {
        $ordered = array_intersect_key(self::LABELS, $properties) + array_fill_keys(array_keys($properties), null);
        $facts = [];
        foreach (array_keys($ordered) as $key) {
            [$label, $unit] = (self::LABELS[$key] ?? [ucfirst(str_replace('_', ' ', $key))]) + [1 => null];
            $facts[] = [$label, self::format($key, $properties[$key], $unit)];
        }
        return $facts;
    }

    private static function format(string $key, mixed $value, ?string $unit): string
    {
        if ($key === 'access_keys' && is_array($value)) {
            return $value ? implode('; ', array_map(static fn($k) => sprintf('rotated %s, last used %s',
                $k['last_rotated'] ?? 'unknown', $k['last_used'] ?? 'never'), $value)) : 'None';
        }
        if ($key === 'imds' && is_string($value)) {
            return $value === 'required' ? 'Required (IMDSv2 only)' : 'Optional (IMDSv1 allowed)';
        }
        return match (true) {
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) && !$value => 'None',
            is_array($value) => array_is_list($value)
                ? implode(', ', array_map(static fn($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES), $value))
                : json_encode($value, JSON_UNESCAPED_SLASHES),
            $value === null => '',
            default => $value . ($unit ? ' ' . $unit : ''),
        };
    }

    /**
     * CloudAsset attributes the settings answer; null when they say nothing about it
     * @return array{encryption_at_rest:string|null, internet_facing:bool|null, public_endpoints:string|null, os:string|null, os_version:string|null}
     */
    public static function derive(string $type, array $p, ?string $publicIp = null): array
    {
        $type = strtolower($type);
        $exposed = match (true) {
            $type === 'ec2:instance' => ($publicIp ?? '') !== '',
            $type === 'ec2:elastic-ip' => true,
            $type === 'ec2:natgateway' => false,
            str_starts_with($type, 'elasticloadbalancing:') => isset($p['scheme']) ? $p['scheme'] === 'internet-facing'
                : ($type === 'elasticloadbalancing:loadbalancer/gwy' ? false : null),
            $type === 's3:bucket' => self::bucketPublic($p) !== [] ? true : (isset($p['policy_public']) || isset($p['acl_public']) ? false : null),
            in_array($type, ['rds:db', 'rds:cluster', 'redshift:cluster'], true) => self::bool($p, 'publicly_accessible'),
            $type === 'eks:cluster' => self::bool($p, 'endpoint_public'),
            $type === 'lambda:function' => isset($p['runtime']) || isset($p['url']) ? isset($p['url']) : null,
            $type === 'cloudfront:distribution' => true,
            $type === 'apigateway:restapis' => isset($p['endpoint_type']) ? !str_contains((string) $p['endpoint_type'], 'PRIVATE') : null,
            $type === 'apigateway:apis' => self::bool($p, 'default_endpoint'),
            $type === 'es:domain' => isset($p['in_vpc']) ? !$p['in_vpc'] : null,
            $type === 'kafka:cluster' => self::bool($p, 'public_access'),
            $type === 'ec2:security-group' => isset($p['open_ingress']) ? ($p['open_ingress'] !== [] && ($p['in_use'] ?? true) !== false) : null,
            default => null,
        };
        $endpoints = match (true) {
            str_starts_with($type, 'elasticloadbalancing:') && $exposed => [$p['dns_name'] ?? null],
            $type === 'cloudfront:distribution' => array_merge([$p['domain'] ?? null], (array) ($p['aliases'] ?? [])),
            $type === 'apigateway:apis' && $exposed => [$p['endpoint'] ?? null],
            in_array($type, ['rds:db', 'rds:cluster', 'redshift:cluster', 'es:domain'], true) && $exposed => [$p['endpoint'] ?? null],
            $type === 'lambda:function' => [$p['url'] ?? null],
            $type === 'ec2:instance' && $exposed => [$p['public_dns'] ?? null],
            default => [],
        };
        $endpoints = array_values(array_filter($endpoints, static fn($e) => is_string($e) && $e !== ''));
        return [
            'encryption_at_rest' => isset($p['encrypted']) && is_bool($p['encrypted']) ? ($p['encrypted'] ? 'yes' : 'no') : null,
            'internet_facing' => $exposed,
            'public_endpoints' => $endpoints ? implode(', ', $endpoints) : null,
            'os' => is_string($p['os'] ?? null) ? $p['os'] : null,
            'os_version' => is_scalar($p['os_version'] ?? null) ? (string) $p['os_version'] : null,
        ];
    }

    /**
     * @param string $account the account's own ID (trust from it is not cross-account)
     * @return list<array{severity:string, title:string}>
     */
    public static function findings(string $type, array $p, string $today, string $account = ''): array
    {
        $out = [];
        $add = static function (string $severity, string $title) use (&$out) {
            $out[] = ['severity' => $severity, 'title' => $title];
        };
        $is = static fn(string $key, mixed $value) => array_key_exists($key, $p) && $p[$key] === $value;
        $type = strtolower($type);

        if ($type === 'account') {
            return self::accountFindings($p);
        }
        // Encryption at rest, wherever the setting exists (queues and streams hold less: low)
        if ($is('encrypted', false)) {
            $add(in_array($type, ['sqs:queue', 'sns:topic', 'kinesis:stream'], true) ? 'low' : 'medium', 'Not encrypted at rest');
        }

        switch ($type) {
            case 's3:bucket':
                foreach (self::bucketPublic($p) as $how) {
                    $add('critical', "Public through the bucket $how");
                }
                if (in_array($p['block_public_access'] ?? null, ['none', 'partial'], true)) {
                    $add('medium', 'Block Public Access is not fully on');
                }
                if (in_array($p['versioning'] ?? null, ['Off', 'Suspended'], true)) {
                    $add('low', 'Versioning ' . strtolower($p['versioning']));
                }
                break;
            case 'ec2:instance':
                if ($is('imds', 'optional')) {
                    $add('medium', 'IMDSv1 allowed: instance metadata does not require session tokens');
                }
                break;
            case 'ec2:volume':
                if ($is('state', 'available')) {
                    $add('low', 'Not attached to any instance');
                }
                break;
            case 'ec2:vpc':
                if ($is('flow_logs', false)) {
                    $add('low', 'No VPC flow logs');
                }
                break;
            case 'ec2:security-group':
                foreach (self::openIngressFindings((array) ($p['open_ingress'] ?? [])) as [$severity, $title]) {
                    $add($severity, $title);
                }
                if ($is('default', true) && ($p['rules'] ?? 0) > 0) {
                    $add('low', 'Default security group has rules (it should allow nothing)');
                } elseif ($is('in_use', false) && !$is('default', true)) {
                    $add('low', 'Not used by any network interface');
                }
                break;
            case 'ec2:elastic-ip':
                if ($is('associated', false)) {
                    $add('low', 'Not associated with anything');
                }
                break;
            case 'elasticloadbalancing:loadbalancer':
            case 'elasticloadbalancing:loadbalancer/app':
            case 'elasticloadbalancing:loadbalancer/net':
                $plain = array_filter((array) ($p['listeners'] ?? []), static fn($l) => preg_match('/^HTTP:\d+$/', (string) $l));
                if ($plain && $is('scheme', 'internet-facing')) {
                    $add('medium', 'Plain HTTP listener without a redirect: ' . implode(', ', $plain));
                }
                $old = array_filter((array) ($p['ssl_policies'] ?? []), static fn($s) => preg_match('/2015-|2016-08|TLS-1-[01]-|FS-2018-06|FS-1-1-/', (string) $s));
                if ($old) {
                    $add('medium', 'TLS policy allows TLS 1.0 / 1.1: ' . implode(', ', $old));
                }
                if ($is('lb_type', 'classic')) {
                    $add('low', 'Classic Load Balancer (previous generation)');
                }
                break;
            case 'eks:cluster':
                if ($is('endpoint_public', true) && in_array('0.0.0.0/0', (array) ($p['public_access_cidrs'] ?? []), true)) {
                    $add('medium', 'Kubernetes API endpoint open to the whole internet');
                }
                if ($is('secrets_encrypted', false)) {
                    $add('low', 'Kubernetes secrets not encrypted with a KMS key');
                }
                if (isset($p['logging']) && !in_array('audit', (array) $p['logging'], true)) {
                    $add('low', 'Kubernetes audit logging off');
                }
                break;
            case 'lambda:function':
                if ($is('url_auth', 'NONE')) {
                    $add('high', 'Function URL needs no authentication');
                }
                $deprecated = self::LAMBDA_DEPRECATED[$p['runtime'] ?? ''] ?? null;
                if ($deprecated !== null && $deprecated <= $today) {
                    $add('medium', sprintf('Runtime %s deprecated since %s (no security patches)', $p['runtime'], self::date($deprecated)));
                }
                break;
            case 'ecr:repository':
                if ($is('scan_on_push', false)) {
                    $add('low', 'Images not scanned on push');
                }
                break;
            case 'rds:db':
            case 'rds:cluster':
            case 'redshift:cluster':
                if ($is('publicly_accessible', true)) {
                    $add('high', 'Publicly accessible from the internet');
                }
                if ($is('backup_retention_days', 0)) {
                    $add('medium', 'Automated backups off');
                }
                if ($is('deletion_protection', false)) {
                    $add('low', 'Deletion protection off');
                }
                break;
            case 'dynamodb:table':
                if ($is('pitr', false)) {
                    $add('low', 'Point-in-time recovery off');
                }
                break;
            case 'es:domain':
                if ($is('in_vpc', false)) {
                    $add('medium', 'Public endpoint (not in a VPC)');
                }
                if ($is('enforce_https', false)) {
                    $add('medium', 'HTTPS not enforced');
                }
                if ($is('node_to_node_encryption', false)) {
                    $add('low', 'Node-to-node encryption off');
                }
                if (str_contains((string) ($p['tls_policy'] ?? ''), 'TLS-1-0')) {
                    $add('low', 'TLS policy allows TLS 1.0');
                }
                break;
            case 'elasticache:cluster':
            case 'elasticache:replicationgroup':
                if ($is('in_transit_encryption', false)) {
                    $add('low', 'Not encrypted in transit');
                }
                break;
            case 'kafka:cluster':
                if ($is('public_access', true)) {
                    $add('medium', 'Public access to the brokers is on');
                }
                if (in_array($p['in_transit_encryption'] ?? null, ['PLAINTEXT', 'TLS_PLAINTEXT'], true)) {
                    $add('medium', 'Clients may connect in plaintext');
                }
                break;
            case 'kms:key':
                if ($is('key_state', 'PendingDeletion')) {
                    $add('medium', 'Scheduled for deletion' . (isset($p['deletion_date']) ? ' on ' . self::date($p['deletion_date']) : ''));
                } elseif ($is('rotation', false)) {
                    $add('low', 'Automatic key rotation off');
                }
                break;
            case 'secretsmanager:secret':
                if ($is('rotation', false)) {
                    $add('low', 'Automatic rotation off');
                }
                if (($days = self::daysSince($p['last_accessed'] ?? null, $today)) !== null && $days > self::UNUSED_DAYS) {
                    $add('low', sprintf('Not used for over %d days', self::UNUSED_DAYS));
                }
                break;
            case 'acm:certificate':
                $since = self::daysSince($p['expires'] ?? null, $today);
                $days = $since === null ? null : -$since;
                if ($days !== null && $days < 0) {
                    $in = ($p['in_use'] ?? true) !== false;
                    $add($in ? 'high' : 'low', 'Expired on ' . self::date($p['expires']) . ($in ? '' : ' (not in use)'));
                } elseif ($days !== null && $days <= self::CERT_WARNING_DAYS && ($p['renewal'] ?? null) !== 'ELIGIBLE') {
                    $add('medium', 'Expires on ' . self::date($p['expires']) . ' and does not renew automatically');
                }
                break;
            case 'cloudfront:distribution':
                if ($is('viewer_protocol', 'allow-all')) {
                    $add('medium', 'Viewers may use plain HTTP');
                }
                if (in_array($p['min_tls'] ?? null, ['SSLv3', 'TLSv1', 'TLSv1_2016', 'TLSv1.1_2016'], true)) {
                    $add('medium', "Allows TLS older than 1.2 ({$p['min_tls']})");
                }
                if ($is('waf', false)) {
                    $add('low', 'No WAF web ACL');
                }
                break;
            case 'iam:user':
                if ($is('console', true) && $is('mfa', false)) {
                    $add('high', 'Console password without MFA');
                }
                if ($is('admin', true)) {
                    $add('medium', 'Administrator access on a long-lived IAM user');
                }
                foreach ((array) ($p['access_keys'] ?? []) as $key) {
                    $age = self::daysSince($key['last_rotated'] ?? null, $today);
                    if ($age !== null && $age > self::KEY_ROTATION_DAYS) {
                        $add('medium', sprintf('Access key not rotated for over %d days', self::KEY_ROTATION_DAYS));
                    }
                    $idle = self::daysSince($key['last_used'] ?? null, $today) ?? $age;
                    if ($idle !== null && $idle > self::UNUSED_DAYS) {
                        $add('low', isset($key['last_used']) ? sprintf('Access key unused for over %d days', self::UNUSED_DAYS) : 'Access key never used');
                    }
                }
                if ($is('console', true)) {
                    $idle = self::daysSince($p['password_last_used'] ?? null, $today) ?? self::daysSince($p['created'] ?? null, $today);
                    if ($idle !== null && $idle > self::UNUSED_DAYS) {
                        $add('low', sprintf('Console password unused for over %d days', self::UNUSED_DAYS));
                    }
                }
                break;
            case 'iam:role':
                foreach ((array) ($p['trusted_unconditional'] ?? []) as $principal) {
                    $other = self::principalAccount((string) $principal);
                    if ($principal === '*' || $other === '*') {
                        $add('critical', 'Anyone can assume this role');
                    } elseif ($other !== null && $other !== $account) {
                        $add('medium', "Can be assumed from AWS account $other without a condition such as an external ID");
                    }
                }
                if ($is('admin', true)) {
                    $add('low', 'Administrator access');
                }
                $idle = self::daysSince($p['last_used'] ?? null, $today) ?? self::daysSince($p['created'] ?? null, $today);
                if ($idle !== null && $idle > self::UNUSED_DAYS) {
                    $add('low', isset($p['last_used']) ? sprintf('Not used for over %d days', self::UNUSED_DAYS) : 'Never used');
                }
                break;
        }
        return $out;
    }

    /** @return list<array{severity:string, title:string}> */
    private static function accountFindings(array $p): array
    {
        $out = [];
        $add = static function (string $severity, string $title) use (&$out) {
            $out[] = ['severity' => $severity, 'title' => $title];
        };
        if (($p['root_mfa'] ?? null) === false) {
            $add('critical', 'Root user has no MFA');
        }
        if (($p['root_access_keys'] ?? null) === true) {
            $add('critical', 'Root user has access keys');
        }
        if (($p['cloudtrail_multi_region'] ?? null) === false) {
            $add('high', 'No multi-Region CloudTrail trail is logging');
        }
        if (in_array($p['s3_block_public_access'] ?? null, ['none', 'partial'], true)) {
            $add('medium', 'S3 Block Public Access is not fully on for the account');
        }
        if (!empty($p['guardduty_off'])) {
            $add('medium', 'GuardDuty off in ' . self::regionList($p['guardduty_off']));
        }
        if (!empty($p['ebs_encryption_off'])) {
            $add('low', 'EBS encryption by default off in ' . self::regionList($p['ebs_encryption_off']));
        }
        if (($p['password_policy'] ?? null) === false && ($p['iam_users'] ?? 0) > 0) {
            $add('low', 'No IAM password policy');
        } elseif (isset($p['password_min_length']) && $p['password_min_length'] < 14) {
            $add('low', "IAM password policy allows passwords shorter than 14 characters ({$p['password_min_length']})");
        }
        if (!empty($p['unused_default_vpcs'])) {
            $add('low', 'Unused default VPCs in ' . self::regionList($p['unused_default_vpcs']));
        }
        return $out;
    }

    /** How a bucket is public despite Block Public Access: "policy", "ACL" */
    private static function bucketPublic(array $p): array
    {
        $off = (array) ($p['block_public_access_off'] ?? []);
        $known = isset($p['block_public_access']);
        $how = [];
        if (($p['policy_public'] ?? false) === true && (!$known || in_array('RestrictPublicBuckets', $off, true))) {
            $how[] = 'policy';
        }
        if (($p['acl_public'] ?? false) === true && (!$known || in_array('IgnorePublicAcls', $off, true))) {
            $how[] = 'ACL';
        }
        return $how;
    }

    /** @return list<array{0:string, 1:string}> [severity, title] for the ports a security group opens to the internet */
    private static function openIngressFindings(array $rules): array
    {
        $out = $sensitive = $wide = [];
        foreach ($rules as $rule) {
            $rule = (string) $rule;
            if ($rule === 'all' || str_starts_with($rule, '-1')) {
                $out[] = ['high', 'All traffic open to the internet'];
                continue;
            }
            if (!preg_match('#^(tcp|udp)/(\d+)(?:-(\d+))?$#', $rule, $m)) {
                continue;
            }
            [$from, $to] = [(int) $m[2], (int) ($m[3] ?? $m[2])];
            $hit = array_filter(self::SENSITIVE_PORTS, static fn($port) => $port >= $from && $port <= $to);
            if ($to - $from >= 100) {
                $wide[] = $rule;
            } elseif ($hit) {
                array_push($sensitive, ...$hit);
            }
        }
        if ($sensitive) {
            $sensitive = array_unique($sensitive);
            sort($sensitive);
            $out[] = ['high', 'Open to the internet on remote access / database port ' . implode(', ', $sensitive)];
        }
        if ($wide) {
            $out[] = ['high', 'Wide port range open to the internet: ' . implode(', ', $wide)];
        }
        return $out;
    }

    /** The AWS account in a principal ("arn:aws:iam::999988887777:root", "999988887777", "*"); null for services and federation */
    private static function principalAccount(string $principal): ?string
    {
        if (preg_match('/^(\d{12})$/', $principal, $m) || preg_match('/^arn:aws[\w-]*:(?:iam|sts)::(\d{12}|\*):/', $principal, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function bool(array $p, string $key): ?bool
    {
        return isset($p[$key]) && is_bool($p[$key]) ? $p[$key] : null;
    }

    /** Whole days from a Y-m-d date to today; null without a readable date */
    private static function daysSince(mixed $date, string $today): ?int
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            return null;
        }
        return (int) (new DateTimeImmutable(substr($date, 0, 10)))->diff(new DateTimeImmutable($today))->format('%r%a');
    }

    private static function date(string $date): string
    {
        return (new DateTimeImmutable(substr($date, 0, 10)))->format('j M Y');
    }

    private static function regionList(array $regions): string
    {
        $shown = array_slice($regions, 0, 4);
        $more = count($regions) - count($shown);
        return sprintf('%d Region%s: %s%s', count($regions), count($regions) === 1 ? '' : 's', implode(', ', $shown), $more ? " and $more more" : '');
    }
}
