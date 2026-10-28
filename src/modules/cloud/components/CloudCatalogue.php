<?php

namespace app\modules\cloud\components;

/**
 * The cloud providers and resource types the Cloud Inventory knows about, in
 * one place. Providers are IaaS platforms (assets are infrastructure), SaaS
 * tenants (Google Workspace, Microsoft 365, GitHub...) or edge services
 * (Cloudflare); "Other" covers everything else.
 */
final class CloudCatalogue
{
    public const KIND_IAAS = 'iaas';
    public const KIND_SAAS = 'saas';
    public const KIND_OTHER = 'other';

    /** Scope token for checks recorded on cloud accounts (see Control::$cloud_scope) */
    public const SCOPE_ACCOUNT = 'account';

    /**
     * key => [label, kind, account identifier label, identifier placeholder, region placeholder,
     *         office suite / identity provider tenant]
     */
    private const PROVIDERS = [
        'aws' => ['Amazon Web Services', self::KIND_IAAS, 'AWS account ID', '111122223333', 'eu-west-2', false],
        'azure' => ['Microsoft Azure', self::KIND_IAAS, 'Subscription ID', '00000000-0000-0000-0000-000000000000', 'uksouth', false],
        'gcp' => ['Google Cloud', self::KIND_IAAS, 'Project ID', 'example-prod-123456', 'europe-west2', false],
        'cloudflare' => ['Cloudflare', self::KIND_SAAS, 'Account ID', '0123456789abcdef0123456789abcdef', 'global', false],
        'google_workspace' => ['Google Workspace', self::KIND_SAAS, 'Primary domain', 'example.com', 'global', true],
        'm365' => ['Microsoft 365', self::KIND_SAAS, 'Tenant domain', 'example.onmicrosoft.com', 'global', true],
        'github' => ['GitHub', self::KIND_SAAS, 'Organisation', 'example-org', 'global', false],
        'other' => ['Other', self::KIND_OTHER, 'Account identifier', '', '', false],
    ];

    /** category => label */
    private const CATEGORIES = [
        'compute' => 'Compute',
        'network' => 'Network',
        'storage' => 'Storage',
        'data' => 'Data',
        'identity' => 'Identity & secrets',
        'edge' => 'Edge & DNS',
        'application' => 'Applications',
    ];

    /** key => [label, category, has an operating system] */
    private const TYPES = [
        'instance' => ['Virtual machine / instance', 'compute', true],
        'container_cluster' => ['Container cluster', 'compute', false],
        'serverless' => ['Serverless function', 'compute', false],
        'container_registry' => ['Container registry', 'compute', false],
        'vpc' => ['VPC / virtual network', 'network', false],
        'subnet' => ['Subnet', 'network', false],
        'security_group' => ['Security group / firewall rules', 'network', false],
        'load_balancer' => ['Load balancer', 'network', false],
        'gateway' => ['Internet / NAT gateway', 'network', false],
        'vpn' => ['VPN / private connection', 'network', false],
        'api_gateway' => ['API gateway', 'network', false],
        'public_ip' => ['Public IP address', 'network', false],
        'bucket' => ['Object storage bucket', 'storage', false],
        'volume' => ['Block volume / disk', 'storage', false],
        'file_share' => ['File share', 'storage', false],
        'backup' => ['Snapshot / backup vault', 'storage', false],
        'database' => ['Managed database', 'data', false],
        'warehouse' => ['Data warehouse', 'data', false],
        'cache' => ['Cache', 'data', false],
        'queue' => ['Message queue / stream', 'data', false],
        'iam_role' => ['IAM role / service account', 'identity', false],
        'iam_user' => ['IAM user / access key', 'identity', false],
        'kms_key' => ['Encryption key', 'identity', false],
        'secret_store' => ['Secrets store', 'identity', false],
        'certificate' => ['TLS certificate', 'identity', false],
        'dns_zone' => ['DNS zone', 'edge', false],
        'domain' => ['Domain name', 'edge', false],
        'cdn' => ['CDN distribution', 'edge', false],
        'waf' => ['WAF policy', 'edge', false],
        'repository' => ['Code repository', 'application', false],
        'saas_app' => ['SaaS app / integration', 'application', false],
        'other' => ['Other resource', 'application', false],
    ];

    /** Categories whose resources are infrastructure (the IaaS ATT&CK platform for "Other" providers) */
    public const INFRASTRUCTURE_CATEGORIES = ['compute', 'network', 'storage', 'data'];

    /** Categories that hold data at rest (the default scope of an encryption check) */
    public const DATA_CATEGORIES = ['storage', 'data'];

    // ---- Providers ----------------------------------------------------------

    /** @return array<string,string> key => label */
    public static function providerOptions(): array
    {
        return array_map(static fn($p) => $p[0], self::PROVIDERS);
    }

    public static function providerLabel(?string $provider): string
    {
        return self::PROVIDERS[$provider][0] ?? (string) $provider;
    }

    /** iaas | saas | other */
    public static function providerKind(?string $provider): string
    {
        return self::PROVIDERS[$provider][1] ?? self::KIND_OTHER;
    }

    public static function identifierLabel(?string $provider): string
    {
        return self::PROVIDERS[$provider][2] ?? 'Account identifier';
    }

    public static function identifierPlaceholder(?string $provider): string
    {
        return self::PROVIDERS[$provider][3] ?? '';
    }

    public static function regionPlaceholder(?string $provider): string
    {
        return self::PROVIDERS[$provider][4] ?? '';
    }

    /** Google Workspace / Microsoft 365: an office suite whose tenant is also the identity provider */
    public static function isOfficeSuite(?string $provider): bool
    {
        return self::PROVIDERS[$provider][5] ?? false;
    }

    // ---- Resource types -------------------------------------------------------

    /** @return array<string,string> category => label */
    public static function categoryOptions(): array
    {
        return self::CATEGORIES;
    }

    /** @return array<string,string> type => label */
    public static function typeOptions(): array
    {
        return array_map(static fn($t) => $t[0], self::TYPES);
    }

    /** @return array<string,array<string,string>> category label => [type => label], for grouped dropdowns */
    public static function groupedTypeOptions(): array
    {
        $out = [];
        foreach (self::TYPES as $key => [$label, $category]) {
            $out[self::CATEGORIES[$category]][$key] = $label;
        }
        return $out;
    }

    /** @return string[] the types in a category */
    public static function typesIn(string $category): array
    {
        return array_keys(array_filter(self::TYPES, static fn($t) => $t[1] === $category));
    }

    public static function typeLabel(?string $type): string
    {
        return self::TYPES[$type][0] ?? (string) $type;
    }

    public static function category(?string $type): ?string
    {
        return self::TYPES[$type][1] ?? null;
    }

    public static function categoryLabel(?string $category): string
    {
        return self::CATEGORIES[$category] ?? (string) $category;
    }

    /** Compute instances run an operating system, so they get OS end-of-support checks */
    public static function hasOs(?string $type): bool
    {
        return self::TYPES[$type][2] ?? false;
    }

    /** @return string[] */
    public static function osTypes(): array
    {
        return array_keys(array_filter(self::TYPES, static fn($t) => $t[2]));
    }

    // ---- Check scope ------------------------------------------------------------

    /** @return array<string,string> scope token => label (cloud accounts, then every resource type) */
    public static function scopeOptions(): array
    {
        return [self::SCOPE_ACCOUNT => 'Cloud accounts'] + self::typeOptions();
    }
}
