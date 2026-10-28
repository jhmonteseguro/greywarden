<?php

namespace app\modules\cloud\models;

use app\models\DomainRecord;
use app\models\User;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\components\CloudPosture;
use app\modules\control\models\Control;
use app\modules\inventory\models\Device;

/**
 * Cloud account: an AWS account, Azure subscription, GCP project or SaaS tenant
 * (Google Workspace, Microsoft 365, GitHub...). Cloud assets belong to one
 * account; account-level cloud checks (root MFA, audit logging...) are recorded
 * on the account.
 *
 * @property int $id
 * @property string $reference
 * @property string $name
 * @property string $provider
 * @property string|null $account_identifier
 * @property string $environment
 * @property int|null $owner_id
 * @property string $status
 * @property string $criticality
 * @property string $data_classification
 * @property string|null $notes
 *
 * @property-read User|null $owner
 * @property-read CloudAsset[] $assets
 * @property-read Control[] $controls cloud-check controls with a result recorded on this account
 */
class CloudAccount extends DomainRecord
{
    use RecordsCloudChecks;

    public const STATUS_ACTIVE = 'active';
    /** Statuses in which the account still exists and is checked */
    public const IN_SERVICE_STATUSES = [self::STATUS_ACTIVE, 'suspended'];

    /** @var array<int,string>|string|null manual check results from the form: control id => pass | fail | '' (null = not submitted) */
    public $checks = null;

    public static function tableName(): string
    {
        return 'cloud_account';
    }

    public static function entityType(): string
    {
        return 'cloud_account';
    }

    public static function entityLabel(): string
    {
        return 'Cloud account';
    }

    public static function referencePrefix(): ?string
    {
        return 'ACC';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function viewRoute(): string
    {
        return '/cloud/account/view';
    }

    public static function find(): CloudAccountQuery
    {
        return new CloudAccountQuery(static::class);
    }

    public static function checkTable(): string
    {
        return 'cloud_account_control';
    }

    public static function checkColumn(): string
    {
        return 'cloud_account_id';
    }

    public static function manualChecks(): array
    {
        return CloudChecks::forAccounts();
    }

    public static function environmentOptions(): array
    {
        return ['production' => 'Production', 'staging' => 'Staging', 'development' => 'Development', 'test' => 'Test', 'shared' => 'Shared services'];
    }

    public static function statusOptions(): array
    {
        return [self::STATUS_ACTIVE => 'Active', 'suspended' => 'Suspended', 'closed' => 'Closed'];
    }

    public function enumAttributes(): array
    {
        return [
            'provider' => CloudCatalogue::providerOptions(),
            'environment' => self::environmentOptions(),
            'status' => self::statusOptions(),
            'criticality' => Device::criticalityOptions(),
            'data_classification' => Device::classificationOptions(),
        ];
    }

    public function userAttributes(): array
    {
        return ['owner_id', 'archived_by'];
    }

    public function rules(): array
    {
        return [
            [['name', 'account_identifier', 'notes'], 'trim'],
            [['name', 'provider', 'environment', 'status', 'criticality', 'data_classification'], 'required'],
            ['name', 'string', 'max' => 128],
            ['account_identifier', 'string', 'max' => 128],
            ['account_identifier', 'unique', 'targetAttribute' => ['provider', 'account_identifier'], 'skipOnEmpty' => true,
                'message' => 'Another account of this provider already has this identifier.'],
            ['notes', 'string', 'max' => 20000],
            ['provider', 'in', 'range' => array_keys(CloudCatalogue::providerOptions())],
            ['environment', 'in', 'range' => array_keys(self::environmentOptions())],
            ['status', 'in', 'range' => array_keys(self::statusOptions())],
            ['criticality', 'in', 'range' => array_keys(Device::criticalityOptions())],
            ['data_classification', 'in', 'range' => array_keys(Device::classificationOptions())],
            ['owner_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            ['checks', 'validateChecks'],
        ];
    }

    public function beforeValidate(): bool
    {
        foreach (['account_identifier', 'owner_id'] as $a) {
            if ($this->$a === '') {
                $this->$a = null;
            }
        }
        return parent::beforeValidate();
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'reference' => 'Reference',
            'name' => 'Account name',
            'provider' => 'Provider',
            'account_identifier' => 'Account identifier',
            'environment' => 'Environment',
            'owner_id' => 'Owner',
            'status' => 'Status',
            'criticality' => 'Criticality',
            'data_classification' => 'Data classification',
            'notes' => 'Notes',
            'checks' => 'Cloud checks',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'name' => 'e.g. "Production (AWS)" or "Company Google Workspace".',
            'account_identifier' => 'AWS account ID, Azure subscription ID, GCP project ID, or the tenant\'s domain / organisation.',
            'environment' => 'Assets added to the account start with this environment.',
        ];
    }

    // ---- Relations ------------------------------------------------------------

    public function getOwner()
    {
        return $this->hasOne(User::class, ['id' => 'owner_id']);
    }

    public function getAssets()
    {
        return $this->hasMany(CloudAsset::class, ['account_id' => 'id']);
    }

    public function getControls()
    {
        return $this->hasMany(Control::class, ['id' => 'control_id'])->viaTable(self::checkTable(), [self::checkColumn() => 'id']);
    }

    // ---- Computed ---------------------------------------------------------------

    /**
     * @return array{state:string, compliant:bool, items:array, failures:array<int,string>, unknown:array<int,string>}
     * @see CloudPosture::evaluateAccount()
     */
    public function getPosture(): array
    {
        return CloudPosture::evaluateAccount(CloudChecks::all(), $this->getCheckResults());
    }

    public function isInService(): bool
    {
        return in_array($this->status, self::IN_SERVICE_STATUSES, true);
    }

    public function getProviderLabel(): string
    {
        return CloudCatalogue::providerLabel($this->provider);
    }

    public function getEnvironmentLabel(): string
    {
        return self::environmentOptions()[$this->environment] ?? (string) $this->environment;
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    /** "Amazon Web Services · 111122223333" */
    public function getProviderSummary(): string
    {
        return implode(' · ', array_filter([$this->getProviderLabel(), $this->account_identifier]));
    }
}
