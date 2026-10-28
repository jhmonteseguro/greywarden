<?php

namespace app\modules\cloud\components;

use app\behaviors\AuditBehavior;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;

/**
 * Creates or updates cloud assets from export records (see CloudExport and
 * CloudflareZones).
 *
 * Only resource types in CloudTypeMap are imported; the rest are skipped and
 * counted per provider type in $skippedTypes. Assets are matched on source +
 * source ID, archived ones included, so importing a newer export refreshes the
 * same records; an asset entered by hand with the same account and resource ID
 * is adopted. An update only touches what the source owns: the region, the
 * resource ID and the seen times are refreshed (last seen only moves forward,
 * first seen only back), and the OS, public IPs and endpoints fill fields that
 * are still empty. The name, type, environment, owner, status, criticality,
 * classification, exposure, encryption, checks and notes are the analyst's and
 * are never overwritten, and an empty source value never clears a field.
 *
 * Accounts are matched on provider + account identifier. Missing ones are
 * created (listed in $createdAccounts, to review) unless an account is forced.
 */
final class CloudImporter
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /** Defaults of accounts the import creates */
    public const NEW_ACCOUNT = ['environment' => 'production', 'criticality' => 'medium', 'data_classification' => 'internal'];

    /** Refreshed from the source on every import (when the source has a value) */
    private const SYNCED = ['region', 'resource_id'];

    /** Taken from the source only while still empty */
    private const FILL = ['os', 'os_version', 'public_ips', 'public_endpoints'];

    /** provider => [account identifier pattern, description] */
    private const IDENTIFIERS = [
        'aws' => ['/^\d{12}$/', 'a 12-digit AWS account ID'],
        'azure' => ['/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', 'an Azure subscription ID'],
        'gcp' => ['/^([a-z][a-z0-9-]{4,28}[a-z0-9]|\d{1,20})$/', 'a Google Cloud project ID or number'],
        'cloudflare' => ['/^[0-9a-f]{32}$/', 'a Cloudflare account ID'],
    ];

    /** provider => name of an account the import creates */
    private const ACCOUNT_NAMES = [
        'aws' => 'AWS account %s',
        'azure' => 'Azure subscription %s',
        'gcp' => 'Google Cloud project %s',
        'cloudflare' => 'Cloudflare account %s',
    ];

    /** @var CloudAccount[] accounts this import created */
    public array $createdAccounts = [];

    /** @var array<string,int> unmapped provider type => rows skipped */
    public array $skippedTypes = [];

    /** @var array<string,CloudAccount> "provider|identifier" => account */
    private array $accounts = [];

    /**
     * @param string $now import time ("Y-m-d H:i:s"): last seen for records without one
     * @param CloudAccount|null $account put every asset in this account
     */
    public function __construct(private readonly string $now, private readonly ?CloudAccount $account = null)
    {
    }

    /**
     * @param list<array> $records
     * @param string $importNote first line of the notes on created assets and accounts
     * @return list<array{line: int, action: string, type: string, asset: CloudAsset|null, changed: string[], errors: string[], state: string|null}>
     */
    public function import(array $records, string $importNote): array
    {
        $results = [];
        foreach ($records as $record) {
            $results[] = ['line' => $record['line'], 'type' => (string) $record['provider_type'], 'state' => $record['state'] ?? null]
                + $this->importOne($record, $importNote);
        }
        return $results;
    }

    public static function isValidIdentifier(string $provider, string $identifier): bool
    {
        return isset(self::IDENTIFIERS[$provider]) && preg_match(self::IDENTIFIERS[$provider][0], $identifier) === 1;
    }

    /** @return array{action: string, asset: CloudAsset|null, changed: string[], errors: string[]} */
    private function importOne(array $record, string $importNote): array
    {
        $type = CloudTypeMap::type($record['provider'], (string) $record['provider_type']);
        if ($type === null) {
            $key = (string) $record['provider_type'] !== '' ? (string) $record['provider_type'] : '(no type)';
            $this->skippedTypes[$key] = ($this->skippedTypes[$key] ?? 0) + 1;
            return self::result(self::SKIPPED);
        }
        if ($record['error'] ?? null) {
            return self::result(self::FAILED, null, [], [$record['error']]);
        }
        $account = $this->account ?? $this->account($record, $importNote, $error);
        if ($account === null) {
            return self::result(self::FAILED, null, [], [$error]);
        }

        $attributes = $record['attributes'];
        if (isset($attributes['public_ips'])) {
            $attributes['public_ips'] = self::publicIps($attributes['public_ips']);
        }
        $source = array_filter($attributes, static fn($v) => $v !== null && $v !== '');
        $source['last_seen_at'] ??= $this->now;
        $source['first_seen_at'] ??= min($source['last_seen_at'], $this->now);

        $asset = CloudAsset::findOne(['source' => $source['source'], 'source_id' => $source['source_id']])
            ?? (isset($source['resource_id']) ? CloudAsset::findOne(['account_id' => $account->id, 'resource_id' => $source['resource_id'], 'source' => null]) : null);
        if ($asset === null) {
            $asset = new CloudAsset($source + ($record['create'] ?? []) + [
                'account_id' => $account->id,
                'resource_type' => $type,
                'status' => CloudAsset::STATUS_ACTIVE,
                'criticality' => 'medium',
                'data_classification' => 'internal',
            ]);
            $asset->notes = $importNote;
            $saved = $asset->save();
            return self::result($saved ? self::CREATED : self::FAILED, $asset, [], $saved ? [] : $asset->getErrorSummary(true));
        }

        // Adopted: an asset entered by hand becomes the synced copy of this resource
        $asset->source ??= $source['source'];
        $asset->source_id ??= $source['source_id'];
        foreach (self::SYNCED as $attr) {
            if (isset($source[$attr])) {
                $asset->$attr = $source[$attr];
            }
        }
        foreach (self::FILL as $attr) {
            if (($asset->$attr === null || $asset->$attr === '') && isset($source[$attr])) {
                $asset->$attr = $source[$attr];
            }
        }
        if (!$asset->last_seen_at || $source['last_seen_at'] > $asset->last_seen_at) {
            $asset->last_seen_at = $source['last_seen_at'];
        }
        if (!$asset->first_seen_at || $source['first_seen_at'] < $asset->first_seen_at) {
            $asset->first_seen_at = $source['first_seen_at'];
        }
        if (!$asset->validate()) {
            return self::result(self::FAILED, $asset, [], $asset->getErrorSummary(true));
        }
        // Compare after validation, which normalises values (trim turns NULL into '')
        $changed = array_keys(array_filter($asset->getDirtyAttributes(),
            static fn($value, $attr) => !AuditBehavior::same($asset->getOldAttribute($attr), $value), ARRAY_FILTER_USE_BOTH));
        if (!$changed) {
            return self::result(self::UNCHANGED, $asset);
        }
        $asset->save(false);
        return self::result(self::UPDATED, $asset, $changed);
    }

    /** The record's account: found by its identifier (or an alias), else created */
    private function account(array $record, string $importNote, ?string &$error): ?CloudAccount
    {
        $provider = $record['provider'];
        $identifier = $record['account'] ?? null;
        if ($identifier === null || $identifier === '') {
            $error = 'No account ID in the row: import it with --account=ACC-xxxx.';
            return null;
        }
        if (!self::isValidIdentifier($provider, $identifier)) {
            $error = sprintf('"%s" is not %s.', $identifier, self::IDENTIFIERS[$provider][1] ?? 'a valid account identifier');
            return null;
        }
        $candidates = array_values(array_unique(array_merge([$identifier], $record['account_aliases'] ?? [])));
        foreach ($candidates as $candidate) {
            if (isset($this->accounts["$provider|$candidate"])) {
                return $this->accounts["$provider|$candidate"];
            }
        }
        $account = CloudAccount::find()->where(['provider' => $provider, 'account_identifier' => $candidates])->orderBy(['id' => SORT_ASC])->one();
        if ($account === null) {
            $account = new CloudAccount(self::NEW_ACCOUNT + [
                'name' => mb_strimwidth($record['account_name'] ?? sprintf(self::ACCOUNT_NAMES[$provider], $identifier), 0, 128, '…'),
                'provider' => $provider,
                'account_identifier' => $identifier,
                'status' => CloudAccount::STATUS_ACTIVE,
                'notes' => $importNote . "\nCreated by the import: set its environment, owner, criticality and data classification.",
            ]);
            if (!$account->save()) {
                $error = 'Could not create the account: ' . implode(' ', $account->getErrorSummary(true));
                return null;
            }
            $this->createdAccounts[] = $account;
        }
        foreach ($candidates as $candidate) {
            $this->accounts["$provider|$candidate"] = $account;
        }
        return $account;
    }

    /** Only routable addresses: an internal address resource (10.x, fd00::) is not a public IP */
    private static function publicIps(string $value): ?string
    {
        $ips = array_filter(preg_split('/[\s,;]+/', $value) ?: [],
            static fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
        return $ips ? implode(', ', $ips) : null;
    }

    private static function result(string $action, ?CloudAsset $asset = null, array $changed = [], array $errors = []): array
    {
        return ['action' => $action, 'asset' => $asset, 'changed' => $changed, 'errors' => $errors];
    }
}
