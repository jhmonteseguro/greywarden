<?php

namespace app\modules\attack\components;

use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;

/**
 * ATT&CK view of the Cloud Inventory: which Enterprise platforms a cloud
 * account or asset falls under. IaaS providers' resources are IaaS (plus the
 * guest OS for instances and Containers for clusters); SaaS tenants are SaaS,
 * and Google Workspace / Microsoft 365 also Office Suite and Identity Provider.
 */
final class CloudAttack
{
    /** @return string[] e.g. ['IaaS'] or ['SaaS', 'Office Suite', 'Identity Provider'] */
    public static function accountPlatforms(CloudAccount $account): array
    {
        return self::forProvider($account->provider, null);
    }

    /** @return string[] e.g. ['Linux', 'IaaS'] for an EC2 instance running Amazon Linux */
    public static function assetPlatforms(CloudAsset $asset): array
    {
        $platforms = self::forProvider($asset->getProvider(), $asset->resource_type);
        if (CloudCatalogue::hasOs($asset->resource_type)) {
            // The guest OS, as for a server in the device inventory
            $platforms = array_merge(DeviceAttack::platforms('server', $asset->os), $platforms);
        }
        return array_values(array_unique($platforms));
    }

    /** @return string[] */
    private static function forProvider(?string $provider, ?string $resourceType): array
    {
        $category = CloudCatalogue::category($resourceType);
        $kind = CloudCatalogue::providerKind($provider);
        if ($kind === CloudCatalogue::KIND_OTHER) {
            // Unknown provider: infrastructure resources are IaaS, anything else (or the account itself) SaaS
            $kind = in_array($category, CloudCatalogue::INFRASTRUCTURE_CATEGORIES, true) ? CloudCatalogue::KIND_IAAS : CloudCatalogue::KIND_SAAS;
        }
        if ($kind === CloudCatalogue::KIND_IAAS) {
            $platforms = ['IaaS'];
            if ($resourceType === 'container_cluster') {
                $platforms[] = 'Containers';
            }
            if ($category === 'identity') {
                $platforms[] = 'Identity Provider';
            }
            return $platforms;
        }
        $platforms = ['SaaS'];
        if (CloudCatalogue::isOfficeSuite($provider)) {
            $platforms[] = 'Office Suite';
            $platforms[] = 'Identity Provider';
        } elseif ($category === 'identity') {
            $platforms[] = 'Identity Provider';
        }
        return $platforms;
    }
}
