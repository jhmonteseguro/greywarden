<?php

namespace app\modules\cloud\components;

/**
 * Which provider resource types an import keeps, and as which CloudCatalogue
 * type. The Cloud Inventory is a security register, not a CMDB: types that
 * are not listed here (network interfaces, route tables, log groups...) are
 * skipped and counted in the import summary.
 *
 * Keys are lower-cased. AWS has two spellings: Resource Explorer's
 * "service:resource-type" and AWS Config's "AWS::Service::Type".
 */
final class CloudTypeMap
{
    /** provider => [provider type (lower case) => catalogue type] */
    private const MAP = [
        'aws' => [
            'ec2:instance' => 'instance', 'aws::ec2::instance' => 'instance',
            'eks:cluster' => 'container_cluster', 'aws::eks::cluster' => 'container_cluster',
            'ecs:cluster' => 'container_cluster', 'aws::ecs::cluster' => 'container_cluster',
            'lambda:function' => 'serverless', 'aws::lambda::function' => 'serverless',
            'ecr:repository' => 'container_registry', 'aws::ecr::repository' => 'container_registry',
            'ec2:vpc' => 'vpc', 'aws::ec2::vpc' => 'vpc',
            'ec2:subnet' => 'subnet', 'aws::ec2::subnet' => 'subnet',
            'ec2:security-group' => 'security_group', 'aws::ec2::securitygroup' => 'security_group',
            'ec2:network-acl' => 'security_group', 'aws::ec2::networkacl' => 'security_group',
            'elasticloadbalancing:loadbalancer' => 'load_balancer', 'aws::elasticloadbalancing::loadbalancer' => 'load_balancer',
            'elasticloadbalancing:loadbalancer/app' => 'load_balancer', 'elasticloadbalancing:loadbalancer/net' => 'load_balancer',
            'elasticloadbalancing:loadbalancer/gwy' => 'load_balancer', 'aws::elasticloadbalancingv2::loadbalancer' => 'load_balancer',
            'ec2:internet-gateway' => 'gateway', 'aws::ec2::internetgateway' => 'gateway',
            'ec2:natgateway' => 'gateway', 'aws::ec2::natgateway' => 'gateway',
            'ec2:transit-gateway' => 'gateway', 'aws::ec2::transitgateway' => 'gateway',
            'ec2:vpn-connection' => 'vpn', 'aws::ec2::vpnconnection' => 'vpn',
            'ec2:vpn-gateway' => 'vpn', 'aws::ec2::vpngateway' => 'vpn',
            'apigateway:restapis' => 'api_gateway', 'aws::apigateway::restapi' => 'api_gateway',
            'apigateway:apis' => 'api_gateway', 'aws::apigatewayv2::api' => 'api_gateway',
            'ec2:elastic-ip' => 'public_ip', 'aws::ec2::eip' => 'public_ip',
            'cloudfront:distribution' => 'cdn', 'aws::cloudfront::distribution' => 'cdn',
            's3:bucket' => 'bucket', 'aws::s3::bucket' => 'bucket',
            'ec2:volume' => 'volume', 'aws::ec2::volume' => 'volume',
            'elasticfilesystem:file-system' => 'file_share', 'aws::efs::filesystem' => 'file_share',
            'fsx:file-system' => 'file_share', 'aws::fsx::filesystem' => 'file_share',
            'backup:backup-vault' => 'backup', 'aws::backup::backupvault' => 'backup',
            'ec2:snapshot' => 'backup', 'aws::ec2::snapshot' => 'backup',
            'rds:snapshot' => 'backup', 'aws::rds::dbsnapshot' => 'backup',
            'rds:cluster-snapshot' => 'backup', 'aws::rds::dbclustersnapshot' => 'backup',
            'rds:db' => 'database', 'aws::rds::dbinstance' => 'database',
            'rds:cluster' => 'database', 'aws::rds::dbcluster' => 'database',
            'dynamodb:table' => 'database', 'aws::dynamodb::table' => 'database',
            'es:domain' => 'database', 'aws::opensearch::domain' => 'database', 'aws::elasticsearch::domain' => 'database',
            'redshift:cluster' => 'warehouse', 'aws::redshift::cluster' => 'warehouse',
            'elasticache:cluster' => 'cache', 'aws::elasticache::cachecluster' => 'cache',
            'elasticache:replicationgroup' => 'cache', 'aws::elasticache::replicationgroup' => 'cache',
            'sqs:queue' => 'queue', 'aws::sqs::queue' => 'queue',
            'sns:topic' => 'queue', 'aws::sns::topic' => 'queue',
            'kinesis:stream' => 'queue', 'aws::kinesis::stream' => 'queue',
            'kafka:cluster' => 'queue', 'aws::msk::cluster' => 'queue',
            'iam:role' => 'iam_role', 'aws::iam::role' => 'iam_role',
            'iam:user' => 'iam_user', 'aws::iam::user' => 'iam_user',
            'kms:key' => 'kms_key', 'aws::kms::key' => 'kms_key',
            'secretsmanager:secret' => 'secret_store', 'aws::secretsmanager::secret' => 'secret_store',
            'acm:certificate' => 'certificate', 'aws::acm::certificate' => 'certificate',
            'route53:hostedzone' => 'dns_zone', 'aws::route53::hostedzone' => 'dns_zone',
            'wafv2:webacl' => 'waf', 'wafv2:regional/webacl' => 'waf', 'wafv2:global/webacl' => 'waf',
            'aws::wafv2::webacl' => 'waf', 'aws::waf::webacl' => 'waf', 'aws::wafregional::webacl' => 'waf',
            'codecommit:repository' => 'repository', 'aws::codecommit::repository' => 'repository',
        ],
        'azure' => [
            'microsoft.compute/virtualmachines' => 'instance',
            'microsoft.compute/virtualmachinescalesets' => 'instance',
            'microsoft.containerservice/managedclusters' => 'container_cluster',
            'microsoft.web/sites#functionapp' => 'serverless',
            'microsoft.web/sites' => 'other',
            'microsoft.containerregistry/registries' => 'container_registry',
            'microsoft.network/virtualnetworks' => 'vpc',
            'microsoft.network/networksecuritygroups' => 'security_group',
            'microsoft.network/azurefirewalls' => 'security_group',
            'microsoft.network/firewallpolicies' => 'security_group',
            'microsoft.network/loadbalancers' => 'load_balancer',
            'microsoft.network/applicationgateways' => 'load_balancer',
            'microsoft.network/natgateways' => 'gateway',
            'microsoft.network/bastionhosts' => 'gateway',
            'microsoft.network/virtualnetworkgateways' => 'vpn',
            'microsoft.network/vpngateways' => 'vpn',
            'microsoft.network/connections' => 'vpn',
            'microsoft.apimanagement/service' => 'api_gateway',
            'microsoft.network/publicipaddresses' => 'public_ip',
            'microsoft.cdn/profiles' => 'cdn',
            'microsoft.network/frontdoors' => 'cdn',
            'microsoft.storage/storageaccounts' => 'bucket',
            'microsoft.compute/disks' => 'volume',
            'microsoft.compute/snapshots' => 'backup',
            'microsoft.recoveryservices/vaults' => 'backup',
            'microsoft.dataprotection/backupvaults' => 'backup',
            'microsoft.sql/servers' => 'database',
            'microsoft.sql/servers/databases' => 'database',
            'microsoft.sql/managedinstances' => 'database',
            'microsoft.dbforpostgresql/flexibleservers' => 'database',
            'microsoft.dbforpostgresql/servers' => 'database',
            'microsoft.dbformysql/flexibleservers' => 'database',
            'microsoft.dbformysql/servers' => 'database',
            'microsoft.documentdb/databaseaccounts' => 'database',
            'microsoft.synapse/workspaces' => 'warehouse',
            'microsoft.cache/redis' => 'cache',
            'microsoft.servicebus/namespaces' => 'queue',
            'microsoft.eventhub/namespaces' => 'queue',
            'microsoft.eventgrid/topics' => 'queue',
            'microsoft.managedidentity/userassignedidentities' => 'iam_role',
            'microsoft.keyvault/vaults' => 'secret_store',
            'microsoft.keyvault/managedhsms' => 'kms_key',
            'microsoft.web/certificates' => 'certificate',
            'microsoft.network/dnszones' => 'dns_zone',
            'microsoft.network/privatednszones' => 'dns_zone',
            'microsoft.domainregistration/domains' => 'domain',
            'microsoft.network/applicationgatewaywebapplicationfirewallpolicies' => 'waf',
            'microsoft.network/frontdoorwebapplicationfirewallpolicies' => 'waf',
        ],
        'gcp' => [
            'compute.googleapis.com/instance' => 'instance',
            'container.googleapis.com/cluster' => 'container_cluster',
            'cloudfunctions.googleapis.com/cloudfunction' => 'serverless',
            'cloudfunctions.googleapis.com/function' => 'serverless',
            'run.googleapis.com/service' => 'serverless',
            'artifactregistry.googleapis.com/repository' => 'container_registry',
            'compute.googleapis.com/network' => 'vpc',
            'compute.googleapis.com/subnetwork' => 'subnet',
            'compute.googleapis.com/firewall' => 'security_group',
            'compute.googleapis.com/firewallpolicy' => 'security_group',
            'compute.googleapis.com/forwardingrule' => 'load_balancer',
            'compute.googleapis.com/globalforwardingrule' => 'load_balancer',
            'compute.googleapis.com/router' => 'gateway',
            'compute.googleapis.com/vpngateway' => 'vpn',
            'compute.googleapis.com/targetvpngateway' => 'vpn',
            'compute.googleapis.com/vpntunnel' => 'vpn',
            'apigateway.googleapis.com/gateway' => 'api_gateway',
            'compute.googleapis.com/address' => 'public_ip',
            'compute.googleapis.com/globaladdress' => 'public_ip',
            'storage.googleapis.com/bucket' => 'bucket',
            'compute.googleapis.com/disk' => 'volume',
            'file.googleapis.com/instance' => 'file_share',
            'compute.googleapis.com/snapshot' => 'backup',
            'sqladmin.googleapis.com/instance' => 'database',
            'spanner.googleapis.com/instance' => 'database',
            'bigtableadmin.googleapis.com/instance' => 'database',
            'bigquery.googleapis.com/dataset' => 'warehouse',
            'redis.googleapis.com/instance' => 'cache',
            'pubsub.googleapis.com/topic' => 'queue',
            'iam.googleapis.com/serviceaccount' => 'iam_role',
            'iam.googleapis.com/role' => 'iam_role',
            'iam.googleapis.com/serviceaccountkey' => 'iam_user',
            'cloudkms.googleapis.com/cryptokey' => 'kms_key',
            'secretmanager.googleapis.com/secret' => 'secret_store',
            'compute.googleapis.com/sslcertificate' => 'certificate',
            'certificatemanager.googleapis.com/certificate' => 'certificate',
            'dns.googleapis.com/managedzone' => 'dns_zone',
            'compute.googleapis.com/securitypolicy' => 'waf',
            'sourcerepo.googleapis.com/repo' => 'repository',
        ],
        'cloudflare' => [
            'zone' => 'dns_zone',
        ],
    ];

    /** The catalogue type for a provider's resource type, or null when imports skip it */
    public static function type(string $provider, string $providerType): ?string
    {
        return self::MAP[$provider][strtolower(trim($providerType))] ?? null;
    }

    /** @return array<string,string> provider type => catalogue type */
    public static function forProvider(string $provider): array
    {
        return self::MAP[$provider] ?? [];
    }
}
