<?php

namespace app\modules\control\models;

use app\components\Actor;
use app\models\DomainRecord;
use app\models\User;
use app\modules\attack\models\AttackMitigation;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use DateTimeImmutable;
use Yii;

/**
 * Control Register entry: a safeguard, its implementation status and its
 * tested effectiveness.
 *
 * last_tested_on / effectiveness / next_test_due are derived from the
 * control's tests (ControlTest) and its test frequency, never edited directly.
 *
 * A control with a `device_check` is checked on every device it applies to
 * (device types + ownership): "manual" checks are recorded per device in
 * device_control, "patch_age" is measured from the device's last patch date.
 *
 * @property int $id
 * @property string $reference
 * @property string $title
 * @property string|null $description
 * @property string|null $implementation
 * @property string|null $evidence
 * @property int|null $owner_id
 * @property string $control_type
 * @property string $nature
 * @property string $status
 * @property string|null $target_date
 * @property string|null $implemented_on
 * @property int|null $test_frequency_months
 * @property string|null $last_tested_on
 * @property string $effectiveness
 * @property string|null $next_test_due
 * @property string|null $device_check
 * @property string|null $device_types
 * @property string $device_ownership
 * @property int|null $check_max_age_days
 *
 * @property-read User|null $owner
 * @property-read Risk[] $risks
 * @property-read Decision[] $decisions
 * @property-read AttackMitigation[] $mitigations
 * @property-read FrameworkRequirement[] $requirements
 * @property-read ControlTest[] $tests newest first
 */
class Control extends DomainRecord
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_IMPLEMENTING = 'implementing';
    public const STATUS_IMPLEMENTED = 'implemented';
    public const STATUS_RETIRED = 'retired';

    /** Statuses in which a control is operating (device checks apply) */
    public const IN_FORCE_STATUSES = [self::STATUS_IMPLEMENTING, self::STATUS_IMPLEMENTED];
    /** Statuses with a target date to meet */
    public const PENDING_STATUSES = [self::STATUS_PLANNED, self::STATUS_IMPLEMENTING];

    public const EFFECTIVENESS_NOT_TESTED = 'not_tested';
    public const EFFECTIVENESS_EFFECTIVE = 'effective';
    public const EFFECTIVENESS_PARTIAL = 'partial';
    public const EFFECTIVENESS_INEFFECTIVE = 'ineffective';
    /** Test results that make a control "weak" */
    public const WEAK_RESULTS = [self::EFFECTIVENESS_PARTIAL, self::EFFECTIVENESS_INEFFECTIVE];

    public const CHECK_MANUAL = 'manual';
    public const CHECK_PATCH_AGE = 'patch_age';

    public const OWNERSHIP_ALL = 'all';

    /** Framework roll-up contribution of a single control (see frameworkState()) */
    public const FRAMEWORK_MET = 'met';
    public const FRAMEWORK_PARTIAL = 'partial';
    public const FRAMEWORK_PLANNED = 'planned';

    /** @var int[]|string|null link ids (null = not submitted) */
    public $riskIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $decisionIds = null;
    /** @var int[]|string|null ATT&CK mitigation ids (null = not submitted) */
    public $mitigationIds = null;
    /** @var int[]|string|null framework requirement ids (null = not submitted; out-of-scope links are kept, see syncLink()) */
    public $requirementIds = null;
    /** @var string[]|string|null device types from the form (null = not submitted); stored in device_types */
    public $deviceTypeList = null;

    public static function tableName(): string
    {
        return 'control';
    }

    public static function entityType(): string
    {
        return 'control';
    }

    public static function entityLabel(): string
    {
        return 'Control';
    }

    public static function referencePrefix(): ?string
    {
        return 'CTL';
    }

    public static function viewRoute(): string
    {
        return '/control/control/view';
    }

    public static function find(): ControlQuery
    {
        return new ControlQuery(static::class);
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_PLANNED => 'Planned',
            self::STATUS_IMPLEMENTING => 'Implementing',
            self::STATUS_IMPLEMENTED => 'Implemented',
            self::STATUS_RETIRED => 'Retired',
        ];
    }

    public static function typeOptions(): array
    {
        return ['preventive' => 'Preventive', 'detective' => 'Detective', 'corrective' => 'Corrective'];
    }

    public static function natureOptions(): array
    {
        return ['technical' => 'Technical', 'administrative' => 'Administrative', 'physical' => 'Physical'];
    }

    public static function effectivenessOptions(): array
    {
        return [
            self::EFFECTIVENESS_NOT_TESTED => 'Not tested',
            self::EFFECTIVENESS_EFFECTIVE => 'Effective',
            self::EFFECTIVENESS_PARTIAL => 'Partially effective',
            self::EFFECTIVENESS_INEFFECTIVE => 'Ineffective',
        ];
    }

    /** months => label */
    public static function frequencyOptions(): array
    {
        return [1 => 'Monthly', 3 => 'Quarterly', 6 => 'Every 6 months', 12 => 'Annually', 24 => 'Every 2 years'];
    }

    public static function deviceCheckOptions(): array
    {
        return [self::CHECK_MANUAL => 'Checked on each device (pass / fail)', self::CHECK_PATCH_AGE => 'Patch age (from each device\'s last patched date)'];
    }

    public static function deviceOwnershipOptions(): array
    {
        return [self::OWNERSHIP_ALL => 'All devices'] + Device::ownershipOptions();
    }

    public function linkDefinitions(): array
    {
        return [
            'riskIds' => ['table' => 'risk_control', 'self' => 'control_id', 'other' => 'risk_id', 'class' => Risk::class, 'label' => 'Risks it mitigates'],
            'decisionIds' => ['table' => 'decision_control', 'self' => 'control_id', 'other' => 'decision_id', 'class' => Decision::class, 'label' => 'Related decisions'],
            'mitigationIds' => ['table' => 'control_attack_mitigation', 'self' => 'control_id', 'other' => 'mitigation_id', 'class' => AttackMitigation::class, 'label' => 'ATT&CK mitigations'],
            'requirementIds' => ['table' => 'control_framework_requirement', 'self' => 'control_id', 'other' => 'requirement_id', 'class' => FrameworkRequirement::class, 'label' => 'Framework requirements'],
        ];
    }

    public function enumAttributes(): array
    {
        return [
            'status' => self::statusOptions(),
            'control_type' => self::typeOptions(),
            'nature' => self::natureOptions(),
            'effectiveness' => self::effectivenessOptions(),
            'test_frequency_months' => self::frequencyOptions(),
            'device_check' => self::deviceCheckOptions(),
            'device_ownership' => self::deviceOwnershipOptions(),
        ];
    }

    public function userAttributes(): array
    {
        return ['owner_id', 'archived_by'];
    }

    public function rules(): array
    {
        $text = ['title', 'description', 'implementation', 'evidence'];
        return [
            [$text, 'trim'],
            [['title', 'status', 'control_type', 'nature'], 'required'],
            ['title', 'string', 'max' => 255],
            [['description', 'implementation', 'evidence'], 'string', 'max' => 20000],
            ['status', 'in', 'range' => array_keys(self::statusOptions())],
            ['control_type', 'in', 'range' => array_keys(self::typeOptions())],
            ['nature', 'in', 'range' => array_keys(self::natureOptions())],
            ['owner_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            [['target_date', 'implemented_on'], 'date', 'format' => 'php:Y-m-d'],
            ['implemented_on', 'compare', 'compareValue' => date('Y-m-d'), 'operator' => '<=', 'type' => 'string', 'message' => 'Implemented on cannot be in the future.'],
            ['test_frequency_months', 'in', 'range' => array_keys(self::frequencyOptions())],
            ['device_check', 'in', 'range' => array_keys(self::deviceCheckOptions())],
            ['device_ownership', 'default', 'value' => self::OWNERSHIP_ALL],
            ['device_ownership', 'in', 'range' => array_keys(self::deviceOwnershipOptions())],
            ['deviceTypeList', 'each', 'rule' => ['in', 'range' => array_keys(Device::typeOptions())]],
            ['check_max_age_days', 'integer', 'min' => 1, 'max' => 365],
            ['check_max_age_days', 'required', 'when' => fn() => $this->device_check === self::CHECK_PATCH_AGE,
                'whenClient' => "function(){return $('#control-device_check').val()==='patch_age';}", 'message' => 'Set the maximum patch age in days.'],
            ['riskIds', 'each', 'rule' => ['exist', 'targetClass' => Risk::class, 'targetAttribute' => 'id']],
            ['decisionIds', 'each', 'rule' => ['exist', 'targetClass' => Decision::class, 'targetAttribute' => 'id']],
            ['mitigationIds', 'each', 'rule' => ['exist', 'targetClass' => AttackMitigation::class, 'targetAttribute' => 'id']],
            ['requirementIds', 'each', 'rule' => ['exist', 'targetClass' => FrameworkRequirement::class, 'targetAttribute' => 'id']],
        ];
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'reference' => 'Reference',
            'title' => 'Title',
            'description' => 'Description',
            'implementation' => 'Implementation',
            'evidence' => 'Evidence',
            'owner_id' => 'Owner',
            'control_type' => 'Type',
            'nature' => 'Nature',
            'status' => 'Status',
            'target_date' => 'Target date',
            'implemented_on' => 'Implemented on',
            'test_frequency_months' => 'Test frequency',
            'last_tested_on' => 'Last tested',
            'effectiveness' => 'Effectiveness',
            'next_test_due' => 'Next test due',
            'device_check' => 'Device check',
            'device_types' => 'Device types',
            'deviceTypeList' => 'Applies to device types',
            'device_ownership' => 'Applies to',
            'check_max_age_days' => 'Maximum patch age (days)',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'description' => 'What the control does and which threat it addresses.',
            'implementation' => 'How it is implemented: tools, configuration, process.',
            'evidence' => 'Where an auditor finds the evidence: policy documents, reports, screenshots, tickets.',
            'status' => 'Planned: agreed but not started. Implementing: partly in place; device checks apply and the target date still counts. '
                . 'Implemented: fully in place; starts the test schedule. Retired: no longer in use; linked risks are flagged.',
            'target_date' => 'When a planned control should be in place.',
            'test_frequency_months' => 'How often its effectiveness is tested. Leave empty for no schedule.',
            'device_check' => 'Makes this control a check on every device it applies to, while it is implementing or implemented.',
            'deviceTypeList' => 'Leave all unticked to apply it to every device type.',
        ];
    }

    public function formatAuditValue(string $attribute, mixed $value): string
    {
        if ($attribute === 'device_types') {
            return implode(', ', array_map(static fn($t) => Device::typeOptions()[$t] ?? $t, explode(',', (string) $value)));
        }
        return parent::formatAuditValue($attribute, $value);
    }

    public function beforeValidate(): bool
    {
        foreach (['owner_id', 'target_date', 'implemented_on', 'test_frequency_months', 'device_check', 'check_max_age_days'] as $a) {
            if ($this->$a === '') {
                $this->$a = null;
            }
        }
        if ($this->deviceTypeList !== null && !is_array($this->deviceTypeList)) {
            $this->deviceTypeList = [];
        }
        return parent::beforeValidate();
    }

    public function beforeSave($insert): bool
    {
        if ($this->deviceTypeList !== null) {
            $types = array_values(array_intersect(array_keys(Device::typeOptions()), $this->deviceTypeList));
            // None or all ticked: every type
            $this->device_types = $types && count($types) < count(Device::typeOptions()) ? implode(',', $types) : null;
        }
        if (!$this->device_check) {
            $this->device_types = null;
            $this->device_ownership = self::OWNERSHIP_ALL;
            $this->check_max_age_days = null;
        } elseif ($this->device_check !== self::CHECK_PATCH_AGE) {
            $this->check_max_age_days = null;
        }
        $this->effectiveness ??= self::EFFECTIVENESS_NOT_TESTED;
        if ($this->status === self::STATUS_IMPLEMENTED && !$this->implemented_on) {
            $this->implemented_on = Actor::today();
        }
        $this->next_test_due = $this->computeNextTestDue();
        return parent::beforeSave($insert);
    }

    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);
        DeviceChecks::reset();
    }

    public function afterDelete(): void
    {
        parent::afterDelete();
        DeviceChecks::reset();
    }

    // ---- Relations ------------------------------------------------------

    public function getOwner()
    {
        return $this->hasOne(User::class, ['id' => 'owner_id']);
    }

    public function getRisks()
    {
        return $this->hasMany(Risk::class, ['id' => 'risk_id'])->viaTable('risk_control', ['control_id' => 'id']);
    }

    public function getDecisions()
    {
        return $this->hasMany(Decision::class, ['id' => 'decision_id'])->viaTable('decision_control', ['control_id' => 'id']);
    }

    public function getMitigations()
    {
        return $this->hasMany(AttackMitigation::class, ['id' => 'mitigation_id'])->viaTable('control_attack_mitigation', ['control_id' => 'id'])
            ->orderBy(['attack_mitigation.external_id' => SORT_ASC]);
    }

    public function getRequirements()
    {
        return $this->hasMany(FrameworkRequirement::class, ['id' => 'requirement_id'])->viaTable('control_framework_requirement', ['control_id' => 'id'])
            ->orderBy(['framework_requirement.framework' => SORT_DESC, 'framework_requirement.sort_order' => SORT_ASC]);
    }

    public function getTests()
    {
        return $this->hasMany(ControlTest::class, ['control_id' => 'id'])->orderBy(['tested_on' => SORT_DESC, 'id' => SORT_DESC]);
    }

    /** @return array<string,Incident[]> role => incidents (see Incident::controlRoleOptions()) */
    public function getIncidentsByRole(): array
    {
        $out = [];
        foreach (array_keys(Incident::controlRoleOptions()) as $role) {
            $out[$role] = Incident::find()->innerJoin(['l' => 'incident_control'], 'l.incident_id = incident.id')
                ->where(['l.control_id' => $this->id, 'l.role' => $role])->orderBy(['incident.detected_at' => SORT_DESC])->all();
        }
        return $out;
    }

    /** @return FrameworkRequirement[] the linked requirements of one framework that are in scope (see FrameworkCatalogue) */
    public function requirementsOf(string $framework): array
    {
        return array_values(array_filter($this->requirements, static fn(FrameworkRequirement $r) => $r->framework === $framework && $r->getIsInScope()));
    }

    /**
     * The form only offers requirements in scope, so links to requirements of
     * a framework that is switched off (or a SOC 2 category not chosen) are
     * kept rather than dropped.
     */
    protected function syncLink(array $def, array $ids): void
    {
        if ($def['table'] === 'control_framework_requirement') {
            $ids = array_merge($ids, FrameworkRequirement::idsOutOfScope($this->currentLinkIds($def)));
        }
        parent::syncLink($def, $ids);
    }

    // ---- Testing --------------------------------------------------------

    /** Re-derive last_tested_on / effectiveness / next_test_due from the tests. */
    public function refreshTesting(): void
    {
        $latest = ControlTest::find()->where(['control_id' => $this->id])->orderBy(['tested_on' => SORT_DESC, 'id' => SORT_DESC])->one();
        $this->last_tested_on = $latest?->tested_on;
        $this->effectiveness = $latest?->result ?? self::EFFECTIVENESS_NOT_TESTED;
        $this->next_test_due = $this->computeNextTestDue();
    }

    /**
     * Next test date for an implemented control on a schedule: the last test +
     * the frequency; a control never tested is due from the day it was implemented.
     */
    public function computeNextTestDue(): ?string
    {
        if (!$this->test_frequency_months || $this->status !== self::STATUS_IMPLEMENTED) {
            return null;
        }
        if (!$this->last_tested_on) {
            return $this->implemented_on ?: Actor::today();
        }
        return (new DateTimeImmutable($this->last_tested_on))->modify('+' . (int) $this->test_frequency_months . ' months')->format('Y-m-d');
    }

    // ---- State ------------------------------------------------------------

    public function isInForce(): bool
    {
        return !$this->getIsArchived() && in_array($this->status, self::IN_FORCE_STATUSES, true);
    }

    public function isTestOverdue(?string $today = null): bool
    {
        return !$this->getIsArchived() && $this->status === self::STATUS_IMPLEMENTED
            && $this->next_test_due && $this->next_test_due < ($today ?? date('Y-m-d'));
    }

    /** Next test falls within the warning window (Control::testDueSoonDays()), not yet overdue */
    public function isTestDueSoon(?string $today = null): bool
    {
        $today ??= date('Y-m-d');
        return !$this->getIsArchived() && $this->status === self::STATUS_IMPLEMENTED && $this->next_test_due
            && $this->next_test_due >= $today && $this->next_test_due <= self::testDueSoonCutoff($today);
    }

    /** Days until the next test (negative = overdue); null when not on a schedule */
    public function getDaysUntilTest(?string $today = null): ?int
    {
        if (!$this->next_test_due) {
            return null;
        }
        return (int) (new DateTimeImmutable($today ?? 'today'))->diff(new DateTimeImmutable($this->next_test_due))->format('%r%a');
    }

    public static function testDueSoonDays(): int
    {
        return (int) (Yii::$app->params['control.testDueSoonDays'] ?? 14);
    }

    /** Last date that still counts as "due soon" */
    public static function testDueSoonCutoff(?string $today = null): string
    {
        return (new DateTimeImmutable($today ?? 'today'))->modify('+' . self::testDueSoonDays() . ' days')->format('Y-m-d');
    }

    /** Due-soon / overdue flag for list and view pages ('' when neither) */
    public function testFlag(?string $today = null): string
    {
        if ($this->isTestOverdue($today)) {
            return \app\helpers\Badge::flag('overdue', 'Test overdue', 'Was due ' . Yii::$app->formatter->asDate($this->next_test_due));
        }
        if ($this->isTestDueSoon($today)) {
            $days = $this->getDaysUntilTest($today);
            return \app\helpers\Badge::flag('due-soon', $days === 0 ? 'Test due today' : sprintf('Test due in %dd', $days),
                'Due ' . Yii::$app->formatter->asDate($this->next_test_due));
        }
        return '';
    }

    public function isTargetOverdue(?string $today = null): bool
    {
        return !$this->getIsArchived() && in_array($this->status, self::PENDING_STATUSES, true)
            && $this->target_date && $this->target_date < ($today ?? date('Y-m-d'));
    }

    /**
     * Why a risk should not rely on this control (empty when it is sound).
     * @return string[]
     */
    public function weaknesses(?string $today = null): array
    {
        $out = [];
        if ($this->getIsArchived()) {
            $out[] = 'archived';
        } elseif ($this->status === self::STATUS_RETIRED) {
            $out[] = 'retired';
        }
        if (in_array($this->effectiveness, self::WEAK_RESULTS, true)) {
            $out[] = strtolower(self::effectivenessOptions()[$this->effectiveness]);
        }
        if ($this->isTestOverdue($today)) {
            $out[] = 'test overdue';
        }
        if ($this->isTargetOverdue($today)) {
            $out[] = 'past target date';
        }
        return $out;
    }

    /**
     * This control's contribution to a framework requirement: met (implemented
     * and not found wanting), partial (being implemented, or tested partially
     * effective / ineffective), planned, or null (retired / archived).
     */
    public function frameworkState(): ?string
    {
        if ($this->getIsArchived()) {
            return null;
        }
        return match ($this->status) {
            self::STATUS_IMPLEMENTED => in_array($this->effectiveness, self::WEAK_RESULTS, true) ? self::FRAMEWORK_PARTIAL : self::FRAMEWORK_MET,
            self::STATUS_IMPLEMENTING => self::FRAMEWORK_PARTIAL,
            self::STATUS_PLANNED => self::FRAMEWORK_PLANNED,
            default => null,
        };
    }

    // ---- Device checks ------------------------------------------------------

    /** @return string[]|null device types the check applies to; null = all */
    public function getDeviceTypes(): ?array
    {
        return $this->device_types ? explode(',', $this->device_types) : null;
    }

    public function getDeviceScopeLabel(): string
    {
        $types = $this->getDeviceTypes();
        $label = $types ? implode(', ', array_map(static fn($t) => Device::typeOptions()[$t] ?? $t, $types)) : 'All device types';
        if ($this->device_ownership !== self::OWNERSHIP_ALL) {
            $label .= ' · ' . (Device::ownershipOptions()[$this->device_ownership] ?? $this->device_ownership);
        }
        return $label;
    }

    /** Form pre-fill: links plus the device type checkboxes */
    public function populateLinks(): void
    {
        parent::populateLinks();
        $this->deviceTypeList ??= $this->getDeviceTypes() ?? [];
    }

    public static function defaultPatchMaxAgeDays(): int
    {
        return (int) (Yii::$app->params['device.patchMaxAgeDays'] ?? 30);
    }

    // ---- Labels -----------------------------------------------------------

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    public function getEffectivenessLabel(): string
    {
        return self::effectivenessOptions()[$this->effectiveness] ?? (string) $this->effectiveness;
    }

    public function getTypeLabel(): string
    {
        return self::typeOptions()[$this->control_type] ?? (string) $this->control_type;
    }

    public function getNatureLabel(): string
    {
        return self::natureOptions()[$this->nature] ?? (string) $this->nature;
    }

    public function getFrequencyLabel(): ?string
    {
        return $this->test_frequency_months ? (self::frequencyOptions()[$this->test_frequency_months] ?? $this->test_frequency_months . ' months') : null;
    }

    /** "ISO 27001: A.8.7, A.8.8" style summary per framework */
    public function frameworkSummary(): array
    {
        $out = [];
        foreach (FrameworkCatalogue::enabled() as $framework) {
            $ids = array_map(static fn($r) => $r->external_id, $this->requirementsOf($framework));
            if ($ids) {
                $out[$framework] = $ids;
            }
        }
        return $out;
    }
}
