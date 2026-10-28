<?php

namespace app\modules\inventory\models;

use app\models\DomainRecord;
use app\models\Lookup;
use app\components\Actor;
use app\modules\control\models\Control;
use app\modules\incident\models\Incident;
use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\components\OsLifecycle;
use app\modules\risk\models\Risk;
use Yii;
use yii\db\Query;
use yii\validators\IpValidator;

/**
 * Inventory device.
 *
 * @property int $id
 * @property string $asset_tag
 * @property string $name
 * @property string|null $hostname
 * @property string $device_type
 * @property string $ownership
 * @property string|null $manufacturer
 * @property string|null $model
 * @property string|null $serial_number
 * @property string|null $os
 * @property string|null $os_version
 * @property string|null $assigned_to
 * @property string|null $assigned_email
 * @property int|null $department_id
 * @property int|null $location_id
 * @property string|null $ip_addresses
 * @property string|null $mac_address
 * @property string|null $purchase_date
 * @property string|null $warranty_expiry
 * @property string|null $eol_date
 * @property string $status
 * @property string $criticality
 * @property string $data_classification
 * @property string|null $last_patched_at
 * @property string|null $notes
 * @property string|null $source
 * @property string|null $source_id
 * @property string|null $first_seen_at
 * @property string|null $last_seen_at
 *
 * @property-read Lookup|null $department
 * @property-read Lookup|null $location
 * @property-read Risk[] $risks
 * @property-read Incident[] $incidents
 * @property-read Control[] $controls device-check controls with a result recorded on this device
 */
class Device extends DomainRecord
{
    public const STATUS_ACTIVE = 'active';
    public const OWNERSHIP_COMPANY = 'company';
    public const OWNERSHIP_BYOD = 'byod';
    public const SOURCE_GOOGLE = 'google';

    /** @var array<int,string>|string|null manual check results from the form: control id => pass | fail | '' (null = not submitted) */
    public $checks = null;

    /** @var array<int,string>|null recorded results (cache) */
    private ?array $checkResults = null;

    public static function tableName(): string
    {
        return 'device';
    }

    public static function entityType(): string
    {
        return 'device';
    }

    public static function entityLabel(): string
    {
        return 'Device';
    }

    public static function referencePrefix(): ?string
    {
        return 'DEV';
    }

    public static function referenceAttribute(): string
    {
        return 'asset_tag';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function viewRoute(): string
    {
        return '/inventory/device/view';
    }

    public static function find(): DeviceQuery
    {
        return new DeviceQuery(static::class);
    }

    public static function typeOptions(): array
    {
        return [
            'laptop' => 'Laptop',
            'desktop' => 'Desktop',
            'server' => 'Server',
            'vm' => 'Virtual Machine',
            'mobile' => 'Mobile',
            'tablet' => 'Tablet',
            'network' => 'Network Device',
            'firewall' => 'Firewall',
            'printer' => 'Printer',
            'iot' => 'IoT',
            'other' => 'Other',
        ];
    }

    public static function ownershipOptions(): array
    {
        return [self::OWNERSHIP_COMPANY => 'Company-owned', self::OWNERSHIP_BYOD => 'Personal (BYOD)'];
    }

    public static function statusOptions(): array
    {
        return [
            'active' => 'Active',
            'in_storage' => 'In Storage',
            'in_repair' => 'In Repair',
            'retired' => 'Retired',
            'lost' => 'Lost',
            'stolen' => 'Stolen',
            'disposed' => 'Disposed',
        ];
    }

    public static function criticalityOptions(): array
    {
        return ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
    }

    public static function classificationOptions(): array
    {
        return ['public' => 'Public', 'internal' => 'Internal', 'confidential' => 'Confidential', 'restricted' => 'Restricted'];
    }

    public static function warrantyWarningDays(): int
    {
        return (int) (Yii::$app->params['device.warrantyWarningDays'] ?? 60);
    }

    /** A synced device is stale when its last check-in is older than this */
    public static function staleDays(): int
    {
        return (int) (Yii::$app->params['device.staleDays'] ?? 30);
    }

    /** ... and missing (shown in red) when older than this */
    public static function staleCriticalDays(): int
    {
        return max(self::staleDays(), (int) (Yii::$app->params['device.staleCriticalDays'] ?? 90));
    }

    public static function sourceOptions(): array
    {
        return [self::SOURCE_GOOGLE => 'Google Workspace'];
    }

    public function enumAttributes(): array
    {
        return [
            'device_type' => self::typeOptions(),
            'ownership' => self::ownershipOptions(),
            'status' => self::statusOptions(),
            'criticality' => self::criticalityOptions(),
            'data_classification' => self::classificationOptions(),
        ];
    }

    public function lookupAttributes(): array
    {
        return ['department_id', 'location_id'];
    }

    public function rules(): array
    {
        $text = ['asset_tag', 'name', 'hostname', 'manufacturer', 'model', 'serial_number', 'os', 'os_version', 'assigned_to', 'assigned_email', 'ip_addresses', 'mac_address', 'notes', 'source', 'source_id'];
        return [
            [$text, 'trim'],
            // No hostname we control (phones, BYOD): the name is required; otherwise it defaults to the hostname
            ['name', 'default', 'value' => fn() => $this->hostname ?: null],
            ['name', 'required', 'message' => 'Enter a device name, a hostname, or both.',
                'when' => fn() => !$this->hostname, // conditional, so the form doesn't mark it required
                'whenClient' => "function () { return $('#device-hostname').val().trim() === ''; }"],
            ['name', 'string', 'max' => 128],
            ['ownership', 'default', 'value' => self::OWNERSHIP_COMPANY],
            [['device_type', 'ownership', 'status', 'criticality', 'data_classification'], 'required'],
            ['asset_tag', 'string', 'max' => 50],
            ['asset_tag', 'match', 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', 'message' => 'Asset tag may contain letters, numbers, dots, dashes, slashes and underscores.'],
            ['asset_tag', 'filter', 'filter' => 'strtoupper', 'skipOnEmpty' => true],
            ['asset_tag', 'unique'],
            ['hostname', 'string', 'max' => 253],
            ['hostname', 'match', 'pattern' => '/^[A-Za-z0-9]([A-Za-z0-9_-]{0,62})(\.[A-Za-z0-9]([A-Za-z0-9_-]{0,62}))*$/', 'message' => 'Hostname may contain letters, numbers, dashes, underscores and dots.'],
            [['manufacturer', 'model', 'serial_number', 'os'], 'string', 'max' => 100],
            ['os_version', 'string', 'max' => 64],
            ['assigned_to', 'string', 'max' => 128],
            ['assigned_email', 'string', 'max' => 254],
            ['assigned_email', 'email'],
            ['assigned_email', 'filter', 'filter' => 'strtolower', 'skipOnEmpty' => true],
            ['source', 'in', 'range' => array_keys(self::sourceOptions())],
            ['source_id', 'string', 'max' => 128],
            ['source_id', 'required', 'when' => fn() => (bool) $this->source, 'message' => 'A synced device needs its source device ID.'],
            ['source_id', 'unique', 'targetAttribute' => ['source', 'source_id'], 'skipOnEmpty' => true, 'message' => 'Another device already has this source device ID.'],
            [['first_seen_at', 'last_seen_at'], 'datetime', 'format' => 'php:Y-m-d H:i:s'],
            ['serial_number', 'unique', 'skipOnEmpty' => true, 'message' => 'Another device already has this serial number.'],
            ['notes', 'string', 'max' => 20000],
            ['device_type', 'in', 'range' => array_keys(self::typeOptions())],
            ['ownership', 'in', 'range' => array_keys(self::ownershipOptions())],
            ['status', 'in', 'range' => array_keys(self::statusOptions())],
            ['criticality', 'in', 'range' => array_keys(self::criticalityOptions())],
            ['data_classification', 'in', 'range' => array_keys(self::classificationOptions())],
            ['ip_addresses', 'validateIpAddresses'],
            ['ip_addresses', 'string', 'max' => 512],
            ['mac_address', 'match', 'pattern' => '/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$|^[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}$|^[0-9A-Fa-f]{12}$/', 'message' => 'Enter a MAC address like 00:1A:2B:3C:4D:5E.'],
            ['mac_address', 'filter', 'filter' => [self::class, 'normalizeMac'], 'skipOnEmpty' => true],
            [['purchase_date', 'warranty_expiry', 'eol_date', 'last_patched_at'], 'date', 'format' => 'php:Y-m-d'],
            ['warranty_expiry', 'validateNotBefore', 'params' => ['than' => 'purchase_date']],
            ['last_patched_at', 'compare', 'compareValue' => date('Y-m-d'), 'operator' => '<=', 'type' => 'string', 'message' => 'Last patched date cannot be in the future.'],
            ['checks', 'validateChecks'],
            ['department_id', 'exist', 'targetClass' => Lookup::class, 'targetAttribute' => 'id', 'filter' => ['type' => Lookup::TYPE_DEPARTMENT]],
            ['location_id', 'exist', 'targetClass' => Lookup::class, 'targetAttribute' => 'id', 'filter' => ['type' => Lookup::TYPE_LOCATION]],
        ];
    }

    /** Only manual checks in force, with a pass / fail / empty (not assessed) result */
    public function validateChecks(string $attribute): void
    {
        if (!is_array($this->checks)) {
            $this->checks = [];
            return;
        }
        $manual = DeviceChecks::manual();
        foreach ($this->checks as $id => $result) {
            if (!isset($manual[(int) $id]) || !in_array($result, ['', DevicePosture::RESULT_PASS, DevicePosture::RESULT_FAIL], true)) {
                $this->addError($attribute, 'Invalid device check result.');
                return;
            }
        }
    }

    /** Validates and normalises a comma/space/newline separated list of IPv4/IPv6 addresses. */
    public function validateIpAddresses(string $attribute): void
    {
        $parts = self::splitIps((string) $this->$attribute);
        $validator = new IpValidator(['ipv4' => true, 'ipv6' => true, 'subnet' => false]);
        $invalid = array_filter($parts, static fn($ip) => !$validator->validate($ip));
        if ($invalid) {
            $this->addError($attribute, 'Invalid IP address: ' . implode(', ', $invalid));
            return;
        }
        $this->$attribute = $parts ? implode(', ', $parts) : null;
    }

    public static function splitIps(string $value): array
    {
        return array_values(array_unique(array_filter(preg_split('/[\s,;]+/', trim($value)) ?: [], 'strlen')));
    }

    public static function normalizeMac(string $mac): string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : $mac;
    }

    public function beforeValidate(): bool
    {
        foreach (['department_id', 'location_id', 'purchase_date', 'warranty_expiry', 'eol_date', 'last_patched_at', 'asset_tag', 'hostname', 'mac_address', 'ip_addresses', 'serial_number', 'assigned_email', 'source', 'source_id', 'first_seen_at', 'last_seen_at'] as $a) {
            if ($this->$a === '') {
                $this->$a = null;
            }
        }
        return parent::beforeValidate();
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'asset_tag' => 'Asset tag',
            'name' => 'Device name',
            'hostname' => 'Hostname',
            'device_type' => 'Device type',
            'ownership' => 'Ownership',
            'manufacturer' => 'Manufacturer',
            'model' => 'Model',
            'serial_number' => 'Serial number',
            'os' => 'Operating system',
            'os_version' => 'OS version',
            'assigned_to' => 'Assigned to',
            'assigned_email' => 'Email',
            'department_id' => 'Department',
            'location_id' => 'Location',
            'ip_addresses' => 'IP address(es)',
            'mac_address' => 'MAC address',
            'purchase_date' => 'Purchase date',
            'warranty_expiry' => 'Warranty expiry',
            'eol_date' => 'End of life',
            'status' => 'Status',
            'criticality' => 'Criticality',
            'data_classification' => 'Data classification',
            'checks' => 'Device checks',
            'last_patched_at' => 'Last patched',
            'notes' => 'Notes',
            'source' => 'Synced from',
            'source_id' => 'Source device ID',
            'first_seen_at' => 'First seen',
            'last_seen_at' => 'Last seen',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'asset_tag' => 'Leave blank to auto-generate (DEV-0001…).',
            'ip_addresses' => 'Separate multiple addresses with commas.',
            'name' => 'Friendly name, e.g. "Jane\'s iPhone". Leave blank to use the hostname.',
            'hostname' => 'Optional: phones and personal devices often have none we control.',
            'assigned_to' => 'Person using the device; for BYOD, its owner (does not need a Greywarden account).',
            'assigned_email' => 'Their work email, which links devices across imports.',
        ];
    }

    // ---- Relations ------------------------------------------------------------

    public function getDepartment()
    {
        return $this->hasOne(Lookup::class, ['id' => 'department_id']);
    }

    public function getLocation()
    {
        return $this->hasOne(Lookup::class, ['id' => 'location_id']);
    }

    public function getRisks()
    {
        return $this->hasMany(Risk::class, ['id' => 'risk_id'])->viaTable('risk_device', ['device_id' => 'id']);
    }

    public function getIncidents()
    {
        return $this->hasMany(Incident::class, ['id' => 'incident_id'])->viaTable('incident_device', ['device_id' => 'id']);
    }

    public function getControls()
    {
        return $this->hasMany(Control::class, ['id' => 'control_id'])->viaTable('device_control', ['device_id' => 'id']);
    }

    // ---- Device checks ----------------------------------------------------------

    /** @return array<int,string> recorded manual check results: control id => pass | fail */
    public function getCheckResults(): array
    {
        if ($this->checkResults === null) {
            $this->checkResults = $this->isNewRecord ? [] : array_map('strval', (new Query())->select(['result', 'control_id'])->from('device_control')
                ->where(['device_id' => $this->id])->indexBy('control_id')->column(static::getDb()));
        }
        return $this->checkResults;
    }

    /** Form pre-fill: links plus the check selects */
    public function populateLinks(): void
    {
        parent::populateLinks();
        if ($this->checks === null) {
            $results = $this->getCheckResults();
            $this->checks = [];
            foreach (array_keys(DeviceChecks::manual()) as $id) {
                $this->checks[$id] = $results[$id] ?? '';
            }
        }
    }

    public function afterSave($insert, $changedAttributes): void
    {
        if (is_array($this->checks)) {
            $this->syncChecks($this->checks);
        }
        parent::afterSave($insert, $changedAttributes);
    }

    /** @param array<int,string> $checks control id => pass | fail | '' (clears the result) */
    private function syncChecks(array $checks): void
    {
        $db = static::getDb();
        $current = $this->getCheckResults();
        foreach ($checks as $id => $result) {
            $id = (int) $id;
            $old = $current[$id] ?? '';
            if ($result === $old) {
                continue;
            }
            if ($result === '') {
                $db->createCommand()->delete('device_control', ['device_id' => $this->id, 'control_id' => $id])->execute();
            } elseif ($old === '') {
                $db->createCommand()->insert('device_control', ['device_id' => $this->id, 'control_id' => $id, 'result' => $result,
                    'checked_on' => Actor::today(), 'checked_by' => Actor::id()])->execute();
            } else {
                $db->createCommand()->update('device_control', ['result' => $result, 'checked_on' => Actor::today(), 'checked_by' => Actor::id()],
                    ['device_id' => $this->id, 'control_id' => $id])->execute();
            }
        }
        $this->checkResults = null;
    }

    /** Audit snapshot of the recorded check results, e.g. "CTL-0001 Full-disk encryption: Pass" */
    protected function auditLinkCallbacks(): array
    {
        return parent::auditLinkCallbacks() + [
            'checks' => static function (self $model) {
                if ($model->isNewRecord || !$model->id) {
                    return [];
                }
                $rows = (new Query())->select(['c.reference', 'c.title', 'dc.result'])->from(['dc' => 'device_control'])
                    ->innerJoin(['c' => 'control'], 'c.id = dc.control_id')->where(['dc.device_id' => $model->id])->all(static::getDb());
                return array_map(static fn($r) => $r['reference'] . ' ' . $r['title'] . ': ' . (DevicePosture::resultLabels()[$r['result']] ?? $r['result']), $rows);
            },
        ];
    }

    // ---- Computed ---------------------------------------------------------------

    /**
     * @return array{state:string, compliant:bool, items:array, failures:array<int,string>, unknown:array<int,string>, patchAgeDays:int|null}
     * @see DevicePosture::evaluate()
     */
    public function getPosture(?string $today = null): array
    {
        return DevicePosture::evaluate(DeviceChecks::all(), $this->device_type, $this->ownership, $this->getCheckResults(), $this->last_patched_at, $today);
    }

    public function isCompliant(): bool
    {
        return $this->getPosture()['compliant'];
    }

    public function isWarrantyExpiringSoon(?string $today = null): bool
    {
        if (!$this->warranty_expiry) {
            return false;
        }
        $today ??= date('Y-m-d');
        $limit = date('Y-m-d', strtotime($today . ' +' . self::warrantyWarningDays() . ' days'));
        return $this->warranty_expiry >= $today && $this->warranty_expiry <= $limit;
    }

    public function isWarrantyExpired(?string $today = null): bool
    {
        return $this->warranty_expiry && $this->warranty_expiry < ($today ?? date('Y-m-d'));
    }

    public function isPastEol(?string $today = null): bool
    {
        return $this->eol_date && $this->eol_date < ($today ?? date('Y-m-d'));
    }

    /** Days since the device last synced with its source; null for devices that are not synced */
    public function getLastSeenDays(?string $today = null): ?int
    {
        return $this->last_seen_at ? DevicePosture::patchAgeDays($this->last_seen_at, $today) : null;
    }

    public function isStale(?string $today = null): bool
    {
        $days = $this->getLastSeenDays($today);
        return $days !== null && $days > self::staleDays();
    }

    /** Synced devices not seen for more than Device::staleCriticalDays() */
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

    /**
     * @return array{state:string, endDate:string|null, estimated:bool, days:int|null}
     * @see OsLifecycle::evaluate()
     */
    public function getOsSupport(?string $today = null): array
    {
        return OsLifecycle::evaluate($this->os, $this->os_version, $today);
    }

    public function isOsUnsupported(?string $today = null): bool
    {
        return $this->getOsSupport($today)['state'] === OsLifecycle::UNSUPPORTED;
    }

    public function getSourceLabel(): ?string
    {
        return $this->source ? (self::sourceOptions()[$this->source] ?? $this->source) : null;
    }

    public function getTypeLabel(): string
    {
        return self::typeOptions()[$this->device_type] ?? (string) $this->device_type;
    }

    public function getOwnershipLabel(): string
    {
        return self::ownershipOptions()[$this->ownership] ?? (string) $this->ownership;
    }

    public function isByod(): bool
    {
        return $this->ownership === self::OWNERSHIP_BYOD;
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    public function getIpList(): array
    {
        return $this->ip_addresses ? self::splitIps($this->ip_addresses) : [];
    }
}
