<?php

namespace app\modules\cloud\models;

use app\models\DomainRecord;
use app\models\RecordsChecks;
use app\models\User;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\components\CloudConfig;
use app\modules\cloud\components\CloudPosture;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\components\OsLifecycle;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use Yii;
use yii\validators\IpValidator;

/**
 * Cloud asset: a resource in a cloud account (instance, VPC, bucket, database,
 * IAM role, DNS zone, repository...), from the resource types in CloudCatalogue.
 * The provider comes from the account.
 *
 * @property int $id
 * @property string $reference
 * @property string $name
 * @property int $account_id
 * @property string $resource_type
 * @property string|null $provider_type the provider's own type ("s3:bucket"), set by imports
 * @property string|null $resource_id
 * @property string|null $region
 * @property string $environment
 * @property int|null $owner_id
 * @property string $status
 * @property string $criticality
 * @property string $data_classification
 * @property bool|int $internet_facing
 * @property string|null $public_ips
 * @property string|null $public_endpoints
 * @property string $encryption_at_rest
 * @property string|null $os
 * @property string|null $os_version
 * @property string|null $source
 * @property string|null $source_id
 * @property string|null $first_seen_at
 * @property string|null $last_seen_at
 * @property string|null $notes
 *
 * @property-read CloudAccount|null $account
 * @property-read User|null $owner
 * @property-read Risk[] $risks
 * @property-read Incident[] $incidents
 * @property-read Decision[] $decisions
 * @property-read Control[] $controls cloud-check controls with a result recorded on this asset
 */
class CloudAsset extends DomainRecord
{
    use RecordsChecks;
    use HasConfiguration;

    public const STATUS_ACTIVE = 'active';
    /** Statuses in which the resource still exists and is checked (a stopped instance keeps its disks) */
    public const IN_SERVICE_STATUSES = [self::STATUS_ACTIVE, 'stopped'];

    public const ENCRYPTION_YES = 'yes';
    public const ENCRYPTION_NO = 'no';
    public const ENCRYPTION_UNKNOWN = 'unknown';

    /** @var array<int,string>|string|null manual check results from the form: control id => pass | fail | '' (null = not submitted) */
    public $checks = null;

    public static function tableName(): string
    {
        return 'cloud_asset';
    }

    public static function entityType(): string
    {
        return 'cloud_asset';
    }

    public static function entityLabel(): string
    {
        return 'Cloud asset';
    }

    public static function referencePrefix(): ?string
    {
        return 'CLD';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function viewRoute(): string
    {
        return '/cloud/asset/view';
    }

    public static function find(): CloudAssetQuery
    {
        return new CloudAssetQuery(static::class);
    }

    public static function checkTable(): string
    {
        return 'cloud_asset_control';
    }

    public static function checkColumn(): string
    {
        return 'cloud_asset_id';
    }

    public static function manualChecks(): array
    {
        return CloudChecks::manualForAssets();
    }

    public static function statusOptions(): array
    {
        return [self::STATUS_ACTIVE => 'Active', 'stopped' => 'Stopped', 'decommissioned' => 'Decommissioned'];
    }

    public static function encryptionOptions(): array
    {
        return [self::ENCRYPTION_YES => 'Yes', self::ENCRYPTION_NO => 'No', self::ENCRYPTION_UNKNOWN => 'Unknown'];
    }

    /** A synced asset is stale when its last sync is older than this */
    public static function staleDays(): int
    {
        return (int) (Yii::$app->params['cloud.staleDays'] ?? 30);
    }

    /** ... and missing (shown in red) when older than this */
    public static function staleCriticalDays(): int
    {
        return max(self::staleDays(), (int) (Yii::$app->params['cloud.staleCriticalDays'] ?? 90));
    }

    public function enumAttributes(): array
    {
        return [
            'resource_type' => CloudCatalogue::typeOptions(),
            'environment' => CloudAccount::environmentOptions(),
            'status' => self::statusOptions(),
            'criticality' => Device::criticalityOptions(),
            'data_classification' => Device::classificationOptions(),
            'encryption_at_rest' => self::encryptionOptions(),
            'source' => CloudCatalogue::providerOptions(),
        ];
    }

    public function userAttributes(): array
    {
        return ['owner_id', 'archived_by'];
    }

    public function booleanAttributes(): array
    {
        return ['internet_facing'];
    }

    public function rules(): array
    {
        $text = ['name', 'resource_id', 'region', 'public_ips', 'public_endpoints', 'os', 'os_version', 'notes', 'source', 'source_id'];
        return [
            [$text, 'trim'],
            ['environment', 'default', 'value' => fn() => $this->account->environment ?? null],
            ['encryption_at_rest', 'default', 'value' => self::ENCRYPTION_UNKNOWN],
            [['name', 'account_id', 'resource_type', 'environment', 'status', 'criticality', 'data_classification', 'encryption_at_rest'], 'required'],
            ['name', 'string', 'max' => 128],
            ['account_id', 'integer'],
            ['account_id', 'exist', 'targetClass' => CloudAccount::class, 'targetAttribute' => 'id'],
            ['resource_type', 'in', 'range' => array_keys(CloudCatalogue::typeOptions())],
            ['resource_id', 'string', 'max' => 512],
            ['resource_id', 'unique', 'targetAttribute' => ['account_id', 'resource_id'], 'skipOnEmpty' => true,
                'message' => 'Another asset in this account already has this resource ID.'],
            ['region', 'string', 'max' => 64],
            ['region', 'match', 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9 ._()-]*$/', 'message' => 'Region may contain letters, numbers, spaces, dots, dashes and underscores.'],
            ['environment', 'in', 'range' => array_keys(CloudAccount::environmentOptions())],
            ['status', 'in', 'range' => array_keys(self::statusOptions())],
            ['criticality', 'in', 'range' => array_keys(Device::criticalityOptions())],
            ['data_classification', 'in', 'range' => array_keys(Device::classificationOptions())],
            ['encryption_at_rest', 'in', 'range' => array_keys(self::encryptionOptions())],
            ['internet_facing', 'boolean'],
            ['public_ips', 'validateIpAddresses'],
            ['public_ips', 'string', 'max' => 512],
            ['public_endpoints', 'validateEndpoints'],
            ['public_endpoints', 'string', 'max' => 1024],
            ['os', 'string', 'max' => 100],
            ['os_version', 'string', 'max' => 64],
            ['owner_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            ['source', 'in', 'range' => array_keys(CloudCatalogue::providerOptions())],
            ['source_id', 'string', 'max' => 512],
            ['source_id', 'required', 'when' => fn() => (bool) $this->source, 'message' => 'A synced asset needs its source resource ID.'],
            ['source_id', 'unique', 'targetAttribute' => ['source', 'source_id'], 'skipOnEmpty' => true, 'message' => 'Another asset already has this source resource ID.'],
            [['first_seen_at', 'last_seen_at'], 'datetime', 'format' => 'php:Y-m-d H:i:s'],
            ['notes', 'string', 'max' => 20000],
            ['checks', 'validateChecks'],
        ];
    }

    /** Validates and normalises a comma/space/newline separated list of public IPv4/IPv6 addresses. */
    public function validateIpAddresses(string $attribute): void
    {
        $parts = Device::splitIps((string) $this->$attribute);
        $validator = new IpValidator(['ipv4' => true, 'ipv6' => true, 'subnet' => false]);
        $invalid = array_filter($parts, static fn($ip) => !$validator->validate($ip));
        if ($invalid) {
            $this->addError($attribute, 'Invalid IP address: ' . implode(', ', $invalid));
            return;
        }
        $this->$attribute = $parts ? implode(', ', $parts) : null;
    }

    /** Hostnames or http(s) URLs, comma / space / newline separated */
    public function validateEndpoints(string $attribute): void
    {
        $parts = Device::splitIps((string) $this->$attribute);
        $invalid = array_filter($parts, static fn($e) => !self::isEndpoint($e));
        if ($invalid) {
            $this->addError($attribute, 'Not a hostname or http(s) URL: ' . implode(', ', $invalid));
            return;
        }
        $this->$attribute = $parts ? implode(', ', $parts) : null;
    }

    public static function isEndpoint(string $value): bool
    {
        if (preg_match('#^https?://#i', $value)) {
            return filter_var($value, FILTER_VALIDATE_URL) !== false;
        }
        return (bool) preg_match('/^(\*\.)?[A-Za-z0-9]([A-Za-z0-9-]{0,62})(\.[A-Za-z0-9]([A-Za-z0-9-]{0,62}))+$/', $value);
    }

    public function beforeValidate(): bool
    {
        foreach (['account_id', 'owner_id', 'resource_id', 'region', 'public_ips', 'public_endpoints', 'os', 'os_version', 'source', 'source_id', 'first_seen_at', 'last_seen_at'] as $a) {
            if ($this->$a === '') {
                $this->$a = null;
            }
        }
        // Only compute instances run an operating system
        if ($this->resource_type && !CloudCatalogue::hasOs($this->resource_type)) {
            $this->os = $this->os_version = null;
        }
        $this->refreshFindings();
        return parent::beforeValidate();
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['audit']['ignored'] = $this->configAuditIgnored();
        return $behaviors;
    }

    protected function configProvider(): ?string
    {
        return $this->account?->provider;
    }

    protected function configType(): ?string
    {
        return $this->provider_type;
    }

    protected function configAccount(): string
    {
        return (string) $this->account?->account_identifier;
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'reference' => 'Reference',
            'name' => 'Asset name',
            'account_id' => 'Cloud account',
            'resource_type' => 'Resource type',
            'provider_type' => 'Provider type',
            'resource_id' => 'Resource ID',
            'region' => 'Region',
            'environment' => 'Environment',
            'owner_id' => 'Owner',
            'status' => 'Status',
            'criticality' => 'Criticality',
            'data_classification' => 'Data classification',
            'internet_facing' => 'Internet-facing',
            'public_ips' => 'Public IP address(es)',
            'public_endpoints' => 'Public endpoints',
            'encryption_at_rest' => 'Encrypted at rest',
            'os' => 'Operating system',
            'os_version' => 'OS version',
            'source' => 'Synced from',
            'source_id' => 'Source resource ID',
            'first_seen_at' => 'First seen',
            'last_seen_at' => 'Last seen',
            'notes' => 'Notes',
            'checks' => 'Cloud checks',
            'finding_count' => 'Findings',
            'finding_severity' => 'Worst finding',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'resource_id' => 'ARN, Azure resource ID or GCP resource name; unique within the account.',
            'region' => 'e.g. eu-west-2, uksouth, europe-west2, or "global".',
            'environment' => 'Leave as the account\'s unless this asset differs.',
            'internet_facing' => 'Reachable from the internet: public IP, public endpoint, public bucket or listener.',
            'public_ips' => 'Separate multiple addresses with commas. Threat intel lookups recognise them as yours.',
            'public_endpoints' => 'Hostnames or URLs, separated by commas.',
            'os' => 'Instances only: OS end of support is checked like devices.',
        ];
    }

    // ---- Relations ------------------------------------------------------------

    public function getAccount()
    {
        return $this->hasOne(CloudAccount::class, ['id' => 'account_id']);
    }

    public function getOwner()
    {
        return $this->hasOne(User::class, ['id' => 'owner_id']);
    }

    public function getRisks()
    {
        return $this->hasMany(Risk::class, ['id' => 'risk_id'])->viaTable('risk_cloud_asset', ['cloud_asset_id' => 'id']);
    }

    public function getIncidents()
    {
        return $this->hasMany(Incident::class, ['id' => 'incident_id'])->viaTable('incident_cloud_asset', ['cloud_asset_id' => 'id']);
    }

    public function getDecisions()
    {
        return $this->hasMany(Decision::class, ['id' => 'decision_id'])->viaTable('decision_cloud_asset', ['cloud_asset_id' => 'id']);
    }

    public function getControls()
    {
        return $this->hasMany(Control::class, ['id' => 'control_id'])->viaTable(self::checkTable(), [self::checkColumn() => 'id']);
    }

    // ---- Computed ---------------------------------------------------------------

    /**
     * @return array{state:string, compliant:bool, items:array, failures:array<int,string>, unknown:array<int,string>}
     * @see CloudPosture::evaluateAsset()
     */
    public function getPosture(?string $today = null): array
    {
        return CloudPosture::evaluateAsset(CloudChecks::all(), $this->resource_type, $this->getCheckResults(), $this->os, $this->os_version,
            $this->encryption_at_rest, $today);
    }

    public function isInService(): bool
    {
        return in_array($this->status, self::IN_SERVICE_STATUSES, true);
    }

    public function isInternetFacing(): bool
    {
        return (bool) $this->internet_facing;
    }

    public function getProvider(): ?string
    {
        return $this->account?->provider;
    }

    /**
     * @return array{state:string, endDate:string|null, estimated:bool, days:int|null}
     * @see OsLifecycle::evaluate()
     */
    public function getOsSupport(?string $today = null): array
    {
        return OsLifecycle::evaluate($this->os, $this->os_version, $today);
    }

    /** Days since the asset last synced with its source; null for assets that are not synced */
    public function getLastSeenDays(?string $today = null): ?int
    {
        return $this->last_seen_at ? DevicePosture::patchAgeDays($this->last_seen_at, $today) : null;
    }

    public function isStale(?string $today = null): bool
    {
        $days = $this->getLastSeenDays($today);
        return $days !== null && $days > self::staleDays();
    }

    /** Synced assets not seen for more than CloudAsset::staleCriticalDays() */
    public function isMissing(?string $today = null): bool
    {
        $days = $this->getLastSeenDays($today);
        return $days !== null && $days > self::staleCriticalDays();
    }

    /** Last-seen colour: null (recent or not synced) | warning (stale) | danger (missing) */
    public function getLastSeenTone(?string $today = null): ?string
    {
        return $this->isMissing($today) ? 'danger' : ($this->isStale($today) ? 'warning' : null);
    }

    public function getSourceLabel(): ?string
    {
        return $this->source ? CloudCatalogue::providerLabel($this->source) : null;
    }

    public function getTypeLabel(): string
    {
        return CloudCatalogue::typeLabel($this->resource_type);
    }

    /** The provider's name for the resource type ("S3 bucket", "EBS volume"), or null when not imported */
    public function getProviderTypeLabel(): ?string
    {
        return CloudConfig::typeLabel($this->getProvider(), $this->provider_type);
    }

    /** What the resource is, most specific first: "S3 bucket", else "Object storage bucket" */
    public function getKindLabel(): string
    {
        return $this->getProviderTypeLabel() ?? $this->getTypeLabel();
    }

    public function getCategoryLabel(): string
    {
        return CloudCatalogue::categoryLabel(CloudCatalogue::category($this->resource_type));
    }

    public function getEnvironmentLabel(): string
    {
        return CloudAccount::environmentOptions()[$this->environment] ?? (string) $this->environment;
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    public function getIpList(): array
    {
        return $this->public_ips ? Device::splitIps($this->public_ips) : [];
    }

    public function getEndpointList(): array
    {
        return $this->public_endpoints ? Device::splitIps($this->public_endpoints) : [];
    }
}
