<?php

namespace app\modules\supplier\models;

use app\components\Actor;
use app\models\DomainRecord;
use app\models\Lookup;
use app\models\RecordsChecks;
use app\models\User;
use app\modules\cloud\models\CloudAccount;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\modules\supplier\components\SupplierChecks;
use app\modules\supplier\components\SupplierPosture;
use DateTimeImmutable;
use Yii;

/**
 * Supplier Register entry: a third party the organisation relies on, what it
 * provides, the data and access it has, the security-relevant terms of its
 * contract and the assurance it has given (SupplierAssurance).
 *
 * The tier (1 = critical) is derived from criticality, data classification,
 * personal data and access level on every save (see deriveTier()); it sets the
 * review interval and scopes supplier checks. A supplier's cloud accounts point
 * at it (CloudAccount::$supplier_id); risks, incidents, decisions and controls
 * link to it from their side.
 *
 * @property int $id
 * @property string $reference
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $company_number
 * @property string|null $website
 * @property int|null $category_id
 * @property string|null $services
 * @property int|null $owner_id
 * @property string $status
 * @property string $criticality
 * @property string $data_classification
 * @property bool $personal_data
 * @property string $access_level
 * @property int $tier
 * @property string|null $data_locations
 * @property string|null $contract_start
 * @property string|null $contract_end
 * @property int|null $notice_days
 * @property string|null $dpa_status
 * @property int|null $breach_notice_hours
 * @property string|null $right_to_audit
 * @property string|null $security_contact
 * @property string|null $status_page_url
 * @property string|null $exit_plan
 * @property string|null $next_review_date
 * @property string|null $notes
 *
 * @property-read User|null $owner
 * @property-read Lookup|null $category
 * @property-read SupplierAssurance[] $assurances latest expiry first
 * @property-read CloudAccount[] $cloudAccounts
 * @property-read Risk[] $risks
 * @property-read Incident[] $incidents
 * @property-read Decision[] $decisions
 * @property-read Control[] $controls controls that rely on the supplier
 */
class Supplier extends DomainRecord
{
    use RecordsChecks;

    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_DUE_DILIGENCE = 'due_diligence';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_OFFBOARDING = 'offboarding';
    public const STATUS_TERMINATED = 'terminated';

    /** Statuses in which the supplier provides a service: reviewed, renewals and failing checks flagged */
    public const IN_SERVICE_STATUSES = [self::STATUS_ACTIVE, self::STATUS_OFFBOARDING];
    /** Statuses before the supplier is engaged */
    public const ONBOARDING_STATUSES = [self::STATUS_PROPOSED, self::STATUS_DUE_DILIGENCE];

    public const ACCESS_NONE = 'none';
    public const ACCESS_USER = 'user';
    public const ACCESS_PRIVILEGED = 'privileged';

    public const TIERS = [1, 2, 3];

    /** @var array<int,string>|string|null manual check results from the form: control id => pass | fail | '' (null = not submitted) */
    public $checks = null;

    public static function tableName(): string
    {
        return 'supplier';
    }

    public static function entityType(): string
    {
        return 'supplier';
    }

    public static function entityLabel(): string
    {
        return 'Supplier';
    }

    public static function referencePrefix(): ?string
    {
        return 'SUP';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function viewRoute(): string
    {
        return '/supplier/supplier/view';
    }

    public static function find(): SupplierQuery
    {
        return new SupplierQuery(static::class);
    }

    public static function checkTable(): string
    {
        return 'supplier_control';
    }

    public static function checkColumn(): string
    {
        return 'supplier_id';
    }

    public static function manualChecks(): array
    {
        return SupplierChecks::manual();
    }

    // ---- Options ------------------------------------------------------------

    public static function statusOptions(): array
    {
        return [
            self::STATUS_PROPOSED => 'Proposed',
            self::STATUS_DUE_DILIGENCE => 'Due diligence',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_OFFBOARDING => 'Offboarding',
            self::STATUS_TERMINATED => 'Terminated',
        ];
    }

    public static function accessOptions(): array
    {
        return [
            self::ACCESS_NONE => 'No access to our systems',
            self::ACCESS_USER => 'User access to our systems',
            self::ACCESS_PRIVILEGED => 'Privileged / admin access',
        ];
    }

    public static function dpaOptions(): array
    {
        return ['signed' => 'Signed', 'not_signed' => 'Not signed'];
    }

    public static function yesNoOptions(): array
    {
        return ['yes' => 'Yes', 'no' => 'No'];
    }

    public static function tierOptions(): array
    {
        return [1 => 'Tier 1 · Critical', 2 => 'Tier 2 · Important', 3 => 'Tier 3 · Standard'];
    }

    public static function tierShortLabel(int|string|null $tier): string
    {
        return $tier ? 'Tier ' . (int) $tier : '';
    }

    public function enumAttributes(): array
    {
        return [
            'status' => self::statusOptions(),
            'criticality' => Device::criticalityOptions(),
            'data_classification' => Device::classificationOptions(),
            'access_level' => self::accessOptions(),
            'tier' => self::tierOptions(),
            'dpa_status' => self::dpaOptions(),
            'right_to_audit' => self::yesNoOptions(),
        ];
    }

    public function userAttributes(): array
    {
        return ['owner_id', 'archived_by'];
    }

    public function lookupAttributes(): array
    {
        return ['category_id'];
    }

    public function booleanAttributes(): array
    {
        return ['personal_data'];
    }

    public function rules(): array
    {
        $text = ['name', 'legal_name', 'company_number', 'website', 'services', 'data_locations', 'security_contact', 'status_page_url', 'exit_plan', 'notes'];
        return [
            [$text, 'trim'],
            [['name', 'status', 'criticality', 'data_classification', 'access_level'], 'required'],
            ['name', 'string', 'max' => 128],
            ['name', 'unique', 'filter' => ['archived_at' => null], 'when' => fn() => !$this->getIsArchived(),
                'message' => 'Another supplier already has this name.'],
            [['legal_name', 'data_locations', 'security_contact'], 'string', 'max' => 255],
            ['company_number', 'string', 'max' => 64],
            [['website', 'status_page_url'], 'url', 'defaultScheme' => 'https', 'validSchemes' => ['http', 'https']],
            [['website', 'status_page_url'], 'string', 'max' => 255],
            [['services', 'exit_plan', 'notes'], 'string', 'max' => 20000],
            ['category_id', 'exist', 'targetClass' => Lookup::class, 'targetAttribute' => 'id', 'filter' => ['type' => Lookup::TYPE_SUPPLIER_CATEGORY]],
            ['owner_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            ['status', 'in', 'range' => array_keys(self::statusOptions())],
            ['criticality', 'in', 'range' => array_keys(Device::criticalityOptions())],
            ['data_classification', 'in', 'range' => array_keys(Device::classificationOptions())],
            ['access_level', 'in', 'range' => array_keys(self::accessOptions())],
            ['personal_data', 'boolean'],
            ['personal_data', 'default', 'value' => false],
            ['dpa_status', 'in', 'range' => array_keys(self::dpaOptions())],
            ['right_to_audit', 'in', 'range' => array_keys(self::yesNoOptions())],
            [['contract_start', 'contract_end', 'next_review_date'], 'date', 'format' => 'php:Y-m-d'],
            ['contract_end', 'validateNotBefore', 'params' => ['than' => 'contract_start']],
            ['notice_days', 'integer', 'min' => 0, 'max' => 3650],
            ['breach_notice_hours', 'integer', 'min' => 0, 'max' => 8760],
            ['checks', 'validateChecks'],
        ];
    }

    public function beforeValidate(): bool
    {
        foreach (['category_id', 'owner_id', 'dpa_status', 'right_to_audit', 'contract_start', 'contract_end', 'notice_days', 'breach_notice_hours', 'next_review_date'] as $a) {
            if ($this->$a === '') {
                $this->$a = null;
            }
        }
        return parent::beforeValidate();
    }

    public function beforeSave($insert): bool
    {
        $this->tier = self::deriveTier($this->criticality, $this->data_classification, $this->access_level, (bool) $this->personal_data)['tier'];
        // A supplier coming into service is due its first review one interval from now
        if ($this->isReviewable() && !$this->next_review_date) {
            $this->next_review_date = date('Y-m-d', strtotime($this->reviewInterval(), strtotime(Actor::today())));
        }
        return parent::beforeSave($insert);
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'reference' => 'Reference',
            'name' => 'Supplier name',
            'legal_name' => 'Legal entity',
            'company_number' => 'Company number',
            'website' => 'Website',
            'category_id' => 'Category',
            'services' => 'What they provide',
            'owner_id' => 'Business owner',
            'status' => 'Status',
            'criticality' => 'Criticality',
            'data_classification' => 'Most sensitive data they hold',
            'personal_data' => 'Processes personal data',
            'access_level' => 'Access to our systems',
            'tier' => 'Tier',
            'data_locations' => 'Data locations',
            'contract_start' => 'Contract start',
            'contract_end' => 'Renewal / end date',
            'notice_days' => 'Notice period (days)',
            'dpa_status' => 'Data processing agreement',
            'breach_notice_hours' => 'Breach notification (hours)',
            'right_to_audit' => 'Right to audit',
            'security_contact' => 'Security contact',
            'status_page_url' => 'Status page',
            'exit_plan' => 'Exit plan',
            'next_review_date' => 'Next review',
            'notes' => 'Notes',
            'checks' => 'Supplier checks',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'legal_name' => 'The contracting entity, e.g. "Amazon Web Services EMEA SARL".',
            'criticality' => 'How badly the business is hurt if they fail or stop.',
            'data_classification' => 'The most sensitive data of ours they store or process. Choose Public if they hold none.',
            'access_level' => 'An MSP with domain admin, or a contractor with VPN admin access, is privileged.',
            'data_locations' => 'Countries / regions where they store or process our data, e.g. "UK, EU (Ireland)".',
            'contract_end' => 'When the contract ends or next renews.',
            'notice_days' => 'Days of notice needed to end or change it before the renewal date.',
            'dpa_status' => 'Needed when they process personal data on our behalf (UK / EU GDPR Article 28).',
            'breach_notice_hours' => 'How quickly the contract says they must tell us about a breach affecting our data.',
            'security_contact' => 'Who to call or email about a security incident.',
            'exit_plan' => 'How we would leave: data return / deletion, alternatives, how long it would take.',
            'next_review_date' => sprintf('Set automatically when it goes into service: %s.', implode(', ', array_map(
                static fn($t) => self::tierShortLabel($t) . ' every ' . self::reviewMonths($t) . ' months', self::TIERS))),
        ];
    }

    // ---- Tier ---------------------------------------------------------------

    /**
     * The supplier's tier and what puts it there: the highest (lowest number)
     * tier any factor calls for. Criticality high / critical, restricted data or
     * privileged access make a supplier Tier 1; medium criticality, confidential
     * or personal data, or user access make it at least Tier 2.
     *
     * @return array{tier:int, reasons:string[]}
     */
    public static function deriveTier(?string $criticality, ?string $classification, ?string $access, bool $personalData): array
    {
        $factors = [
            match ($criticality) {
                'critical' => [1, 'critical to operations'],
                'high' => [1, 'high criticality'],
                'medium' => [2, 'medium criticality'],
                default => [3, null],
            },
            match ($classification) {
                'restricted' => [1, 'holds restricted data'],
                'confidential' => [2, 'holds confidential data'],
                default => [3, null],
            },
            match ($access) {
                self::ACCESS_PRIVILEGED => [1, 'privileged access to our systems'],
                self::ACCESS_USER => [2, 'user access to our systems'],
                default => [3, null],
            },
            $personalData ? [2, 'processes personal data'] : [3, null],
        ];
        $tier = min(array_column($factors, 0));
        $reasons = array_values(array_filter(array_map(static fn($f) => $f[0] === $tier ? $f[1] : null, $factors)));
        return ['tier' => $tier, 'reasons' => $reasons];
    }

    /** "Tier 1: privileged access to our systems, holds restricted data" */
    public function getTierExplanation(): string
    {
        $derived = self::deriveTier($this->criticality, $this->data_classification, $this->access_level, (bool) $this->personal_data);
        return self::tierShortLabel($derived['tier']) . ': ' . ($derived['reasons'] ? implode(', ', $derived['reasons']) : 'nothing raises it above Tier 3');
    }

    public function getTierLabel(): string
    {
        return self::tierOptions()[$this->tier] ?? (string) $this->tier;
    }

    // ---- Reviews ------------------------------------------------------------

    /** months between reviews of a supplier of $tier (params supplier.reviewMonths) */
    public static function reviewMonths(int|string|null $tier): int
    {
        $months = Yii::$app->params['supplier.reviewMonths'] ?? [1 => 12, 2 => 24, 3 => 36];
        return (int) ($months[(int) $tier] ?? 12);
    }

    public function reviewInterval(): ?string
    {
        $tier = self::deriveTier($this->criticality, $this->data_classification, $this->access_level, (bool) $this->personal_data)['tier'];
        return '+' . self::reviewMonths($tier) . ' months';
    }

    /** Suppliers in service are reviewed periodically */
    public function isReviewable(): bool
    {
        return !$this->getIsArchived() && in_array($this->status, self::IN_SERVICE_STATUSES, true);
    }

    public function isReviewOverdue(?string $today = null): bool
    {
        return $this->isReviewable() && $this->next_review_date && $this->next_review_date < ($today ?? date('Y-m-d'));
    }

    // ---- Contract -----------------------------------------------------------

    /** Last day to give notice before the renewal / end date (the date itself when no notice period is known) */
    public function getNoticeDeadline(): ?string
    {
        if (!$this->contract_end) {
            return null;
        }
        return (new DateTimeImmutable($this->contract_end))->modify('-' . (int) $this->notice_days . ' days')->format('Y-m-d');
    }

    public static function renewalWarningDays(): int
    {
        return (int) (Yii::$app->params['supplier.renewalWarningDays'] ?? 60);
    }

    /** In service, the contract has not ended and its notice deadline is within the warning window (or has passed) */
    public function isRenewalDue(?string $today = null): bool
    {
        $today ??= date('Y-m-d');
        $deadline = $this->getNoticeDeadline();
        return $deadline !== null && !$this->getIsArchived() && in_array($this->status, self::IN_SERVICE_STATUSES, true)
            && $this->contract_end >= $today
            && $deadline <= (new DateTimeImmutable($today))->modify('+' . self::renewalWarningDays() . ' days')->format('Y-m-d');
    }

    // ---- Posture ------------------------------------------------------------

    /**
     * @return array{state:string, compliant:bool, items:array, failures:array<int,string>, unknown:array<int,string>}
     * @see SupplierPosture::evaluate()
     */
    public function getPosture(?string $today = null): array
    {
        $assurance = array_map(static fn(SupplierAssurance $a) => ['label' => $a->getTypeLabel(), 'expires_on' => $a->expires_on], $this->assurances);
        return SupplierPosture::evaluate(SupplierChecks::all(), (int) $this->tier, (bool) $this->personal_data, $this->dpa_status,
            $assurance, $this->getCheckResults(), $today);
    }

    /**
     * The assurance to act on: per type, the entry lasting longest, when it has
     * expired or expires within the warning window.
     * @return SupplierAssurance[]
     */
    public function getAssuranceDue(?string $today = null): array
    {
        $latest = [];
        foreach ($this->assurances as $a) {
            $key = $a->type;
            if (!isset($latest[$key]) || $a->outlasts($latest[$key])) {
                $latest[$key] = $a;
            }
        }
        return array_values(array_filter($latest, static fn(SupplierAssurance $a) => $a->isExpired($today) || $a->expiresSoon($today)));
    }

    public function isInService(): bool
    {
        return in_array($this->status, self::IN_SERVICE_STATUSES, true);
    }

    // ---- Relations ----------------------------------------------------------

    public function getOwner()
    {
        return $this->hasOne(User::class, ['id' => 'owner_id']);
    }

    public function getCategory()
    {
        return $this->hasOne(Lookup::class, ['id' => 'category_id']);
    }

    public function getAssurances()
    {
        return $this->hasMany(SupplierAssurance::class, ['supplier_id' => 'id'])
            ->orderBy([new \yii\db\Expression('expires_on IS NULL DESC'), 'expires_on' => SORT_DESC, 'id' => SORT_DESC]);
    }

    public function getCloudAccounts()
    {
        return $this->hasMany(CloudAccount::class, ['supplier_id' => 'id'])->orderBy(['cloud_account.reference' => SORT_ASC]);
    }

    public function getRisks()
    {
        return $this->hasMany(Risk::class, ['id' => 'risk_id'])->viaTable('risk_supplier', ['supplier_id' => 'id']);
    }

    public function getIncidents()
    {
        return $this->hasMany(Incident::class, ['id' => 'incident_id'])->viaTable('incident_supplier', ['supplier_id' => 'id'])
            ->orderBy(['incident.detected_at' => SORT_DESC]);
    }

    public function getDecisions()
    {
        return $this->hasMany(Decision::class, ['id' => 'decision_id'])->viaTable('decision_supplier', ['supplier_id' => 'id']);
    }

    public function getControls()
    {
        return $this->hasMany(Control::class, ['id' => 'control_id'])->viaTable('control_supplier', ['supplier_id' => 'id']);
    }

    // ---- Labels -------------------------------------------------------------

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    public function getAccessLabel(): string
    {
        return self::accessOptions()[$this->access_level] ?? (string) $this->access_level;
    }
}
