<?php

namespace app\modules\supplier\components;

use app\models\Lookup;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\models\CloudAccount;
use app\modules\supplier\models\Supplier;

/**
 * Suppliers for the providers of the cloud accounts on record: one supplier
 * per vendor of the in-service accounts (Google Cloud and Google Workspace are both Google; Azure and
 * Microsoft 365 both Microsoft), named and categorised from the map below, at
 * the highest criticality and data classification of that vendor's accounts.
 * "Other" accounts are skipped, and so is a vendor that already has a supplier
 * of that name. Accounts are not linked: that is left to the user.
 */
final class CloudSuppliers
{
    public const CREATED = 'created';
    public const EXISTS = 'exists';
    public const FAILED = 'failed';

    /** provider => [supplier name, website, category] */
    private const VENDORS = [
        'aws' => ['Amazon Web Services', 'https://aws.amazon.com', 'Cloud infrastructure (IaaS / PaaS)'],
        'azure' => ['Microsoft', 'https://www.microsoft.com', 'Cloud infrastructure (IaaS / PaaS)'],
        'm365' => ['Microsoft', 'https://www.microsoft.com', 'SaaS application'],
        'gcp' => ['Google', 'https://cloud.google.com', 'Cloud infrastructure (IaaS / PaaS)'],
        'google_workspace' => ['Google', 'https://workspace.google.com', 'SaaS application'],
        'cloudflare' => ['Cloudflare', 'https://www.cloudflare.com', 'SaaS application'],
        'github' => ['GitHub', 'https://github.com', 'SaaS application'],
    ];

    private const RANK = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4, 'public' => 1, 'internal' => 2, 'confidential' => 3, 'restricted' => 4];

    public static function vendorName(string $provider): ?string
    {
        return self::VENDORS[$provider][0] ?? null;
    }

    /**
     * Creates the missing suppliers (owned by $ownerId) and reports every vendor.
     * @return array<int,array{action:string, supplier:Supplier, providers:string[], accounts:int, errors:string[]}>
     */
    public static function create(?int $ownerId): array
    {
        $vendors = [];
        foreach (CloudAccount::find()->active()->inService()->orderBy('reference')->all() as $account) {
            $name = self::vendorName($account->provider);
            if ($name === null) {
                continue;
            }
            $v = &$vendors[$name];
            $v['providers'][$account->provider] = CloudCatalogue::providerLabel($account->provider);
            $v['accounts'] = ($v['accounts'] ?? 0) + 1;
            $v['criticality'] = self::max($v['criticality'] ?? null, $account->criticality);
            $v['classification'] = self::max($v['classification'] ?? null, $account->data_classification);
            // The first provider seen decides the website and category; IaaS wins over SaaS
            $vendor = self::VENDORS[$account->provider];
            if (!isset($v['vendor']) || ($vendor[2] !== $v['vendor'][2] && CloudCatalogue::providerKind($account->provider) === CloudCatalogue::KIND_IAAS)) {
                $v['vendor'] = $vendor;
            }
            unset($v);
        }

        $results = [];
        foreach ($vendors as $name => $v) {
            $existing = Supplier::find()->where(['name' => $name])->orderBy(['archived_at' => SORT_ASC])->one();
            $row = ['providers' => array_values($v['providers']), 'accounts' => $v['accounts']];
            if ($existing) {
                $results[] = $row + ['action' => self::EXISTS, 'supplier' => $existing, 'errors' => []];
                continue;
            }
            $supplier = new Supplier([
                'name' => $name,
                'website' => $v['vendor'][1],
                'category_id' => Lookup::find()->select('id')->where(['type' => Lookup::TYPE_SUPPLIER_CATEGORY, 'name' => $v['vendor'][2]])->scalar() ?: null,
                'services' => implode(', ', $v['providers']),
                'owner_id' => $ownerId,
                'status' => Supplier::STATUS_ACTIVE,
                'criticality' => $v['criticality'],
                'data_classification' => $v['classification'],
                'access_level' => Supplier::ACCESS_NONE,
                'personal_data' => false,
                'notes' => sprintf('Created from the Cloud Accounts (%s) on %s. Check the legal entity, personal data, access, contract terms and assurance, and link its cloud accounts.',
                    implode(', ', $v['providers']), date('Y-m-d')),
            ]);
            $saved = $supplier->save();
            $results[] = $row + ['action' => $saved ? self::CREATED : self::FAILED, 'supplier' => $supplier,
                'errors' => $saved ? [] : array_merge(...array_values($supplier->getErrors()))];
        }
        return $results;
    }

    private static function max(?string $a, string $b): string
    {
        return $a !== null && (self::RANK[$a] ?? 0) >= (self::RANK[$b] ?? 0) ? $a : $b;
    }
}
