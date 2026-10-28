<?php

namespace app\modules\incident\models;

use app\components\Actor;
use app\models\DomainRecord;
use app\models\Lookup;
use app\models\User;
use app\modules\attack\models\AttackTechnique;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\components\IncidentMetrics;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use Yii;

/**
 * Security incident.
 *
 * @property int $id
 * @property string $reference
 * @property string $title
 * @property string|null $description
 * @property string|null $management_summary plain-English summary that opens the PDF report
 * @property int|null $category_id
 * @property bool $impact_confidentiality
 * @property bool $impact_integrity
 * @property bool $impact_availability
 * @property string $severity
 * @property string $status
 * @property string|null $occurred_at
 * @property string $detected_at
 * @property string|null $reported_at
 * @property string|null $reported_by
 * @property int|null $assigned_to_id
 * @property bool $personal_data_involved
 * @property bool $regulator_notification_required
 * @property string|null $regulator_notified_at
 * @property string|null $subjects_notified_at
 * @property string|null $regulator_reference
 * @property string|null $containment_actions
 * @property string|null $recovery_actions
 * @property string|null $root_cause
 * @property string|null $lessons_learned
 * @property string|null $contained_at
 * @property string|null $closed_at
 *
 * @property-read Lookup|null $category
 * @property-read User|null $assignedTo
 * @property-read IncidentTimeline[] $timeline
 * @property-read Device[] $devices
 * @property-read Risk[] $risks
 * @property-read Decision[] $decisions
 * @property-read AttackTechnique[] $techniques ATT&CK techniques observed
 * @property-read array<string,Control[]> $controlsByRole role => controls (see controlRoleOptions())
 */
class Incident extends DomainRecord
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    public const STATUS_NEW = 'new';
    public const STATUS_TRIAGE = 'triage';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_CONTAINED = 'contained';
    public const STATUS_ERADICATED = 'eradicated';
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_CLOSED = 'closed';

    /** Statuses implying the incident has been contained */
    public const CONTAINED_STATUSES = [self::STATUS_CONTAINED, self::STATUS_ERADICATED, self::STATUS_RECOVERED, self::STATUS_CLOSED];

    /** @var int[]|string|null link ids (null = not submitted) */
    public $deviceIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $cloudAssetIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $riskIds = null;
    /** @var int[]|string|null ATT&CK technique ids (null = not submitted) */
    public $techniqueIds = null;
    /** @var int[]|string|null controls that detected or contained the incident (null = not submitted) */
    public $detectedByControlIds = null;
    /** @var int[]|string|null controls that failed or were bypassed (null = not submitted) */
    public $failedControlIds = null;
    /** @var int[]|string|null controls added or improved in response (null = not submitted) */
    public $responseControlIds = null;

    public const CONTROL_DETECTED = 'detected';
    public const CONTROL_FAILED = 'failed';
    public const CONTROL_RESPONSE = 'response';

    /** CIA impact flag attribute => property label */
    public const IMPACT_ATTRIBUTES = [
        'impact_confidentiality' => 'Confidentiality',
        'impact_integrity' => 'Integrity',
        'impact_availability' => 'Availability',
    ];

    /** Set false to suppress automatic timeline entries (e.g. when seeding history). */
    public bool $autoTimeline = true;

    public static function tableName(): string
    {
        return 'incident';
    }

    public static function entityType(): string
    {
        return 'incident';
    }

    public static function entityLabel(): string
    {
        return 'Incident';
    }

    public static function referencePrefix(): ?string
    {
        return 'INC';
    }

    public static function viewRoute(): string
    {
        return '/incident/incident/view';
    }

    public static function find(): IncidentQuery
    {
        return new IncidentQuery(static::class);
    }

    public static function severityOptions(): array
    {
        return [
            self::SEVERITY_CRITICAL => 'Critical',
            self::SEVERITY_HIGH => 'High',
            self::SEVERITY_MEDIUM => 'Medium',
            self::SEVERITY_LOW => 'Low',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'New',
            self::STATUS_TRIAGE => 'Triage',
            self::STATUS_INVESTIGATING => 'Investigating',
            self::STATUS_CONTAINED => 'Contained',
            self::STATUS_ERADICATED => 'Eradicated',
            self::STATUS_RECOVERED => 'Recovered',
            self::STATUS_CLOSED => 'Closed',
        ];
    }

    public static function deadlineHours(): int
    {
        return (int) (Yii::$app->params['incident.regulatorDeadlineHours'] ?? 72);
    }

    public function linkDefinitions(): array
    {
        return [
            'deviceIds' => ['table' => 'incident_device', 'self' => 'incident_id', 'other' => 'device_id', 'class' => Device::class, 'label' => 'Affected devices'],
            'cloudAssetIds' => ['table' => 'incident_cloud_asset', 'self' => 'incident_id', 'other' => 'cloud_asset_id', 'class' => CloudAsset::class, 'label' => 'Affected cloud assets'],
            'riskIds' => ['table' => 'risk_incident', 'self' => 'incident_id', 'other' => 'risk_id', 'class' => Risk::class, 'label' => 'Related risks'],
            'techniqueIds' => ['table' => 'incident_attack_technique', 'self' => 'incident_id', 'other' => 'technique_id', 'class' => AttackTechnique::class, 'label' => 'ATT&CK techniques'],
            'detectedByControlIds' => ['table' => 'incident_control', 'self' => 'incident_id', 'other' => 'control_id', 'class' => Control::class,
                'label' => 'Controls that detected or contained it', 'where' => ['role' => self::CONTROL_DETECTED]],
            'failedControlIds' => ['table' => 'incident_control', 'self' => 'incident_id', 'other' => 'control_id', 'class' => Control::class,
                'label' => 'Controls that failed or were bypassed', 'where' => ['role' => self::CONTROL_FAILED]],
            'responseControlIds' => ['table' => 'incident_control', 'self' => 'incident_id', 'other' => 'control_id', 'class' => Control::class,
                'label' => 'Controls added or improved in response', 'where' => ['role' => self::CONTROL_RESPONSE]],
        ];
    }

    /** Role of a control in an incident => label */
    public static function controlRoleOptions(): array
    {
        return [
            self::CONTROL_DETECTED => 'Detected or contained it',
            self::CONTROL_FAILED => 'Failed or was bypassed',
            self::CONTROL_RESPONSE => 'Added or improved in response',
        ];
    }

    /** Link property per control role */
    public static function controlRoleProperties(): array
    {
        return [self::CONTROL_DETECTED => 'detectedByControlIds', self::CONTROL_FAILED => 'failedControlIds', self::CONTROL_RESPONSE => 'responseControlIds'];
    }

    public function enumAttributes(): array
    {
        return ['severity' => self::severityOptions(), 'status' => self::statusOptions()];
    }

    public function userAttributes(): array
    {
        return ['assigned_to_id', 'archived_by'];
    }

    public function lookupAttributes(): array
    {
        return ['category_id'];
    }

    public function booleanAttributes(): array
    {
        return array_merge(['personal_data_involved', 'regulator_notification_required'], array_keys(self::IMPACT_ATTRIBUTES));
    }

    public function dateTimeAttributes(): array
    {
        return ['occurred_at', 'detected_at', 'reported_at', 'regulator_notified_at', 'subjects_notified_at', 'contained_at', 'closed_at'];
    }

    public function rules(): array
    {
        $text = ['title', 'description', 'management_summary', 'reported_by', 'regulator_reference', 'containment_actions', 'recovery_actions', 'root_cause', 'lessons_learned'];
        return [
            [$text, 'trim'],
            [['title', 'category_id', 'severity', 'status', 'detected_at'], 'required'],
            ['title', 'string', 'max' => 255],
            ['reported_by', 'string', 'max' => 128],
            ['regulator_reference', 'string', 'max' => 64],
            [['description', 'management_summary', 'containment_actions', 'recovery_actions', 'root_cause', 'lessons_learned'], 'string', 'max' => 20000],
            ['severity', 'in', 'range' => array_keys(self::severityOptions())],
            ['status', 'in', 'range' => array_keys(self::statusOptions())],
            [$this->dateTimeAttributes(), 'datetime', 'format' => 'php:Y-m-d H:i:s'],
            ['detected_at', 'validateNotBefore', 'params' => ['than' => 'occurred_at']],
            ['reported_at', 'validateNotBefore', 'params' => ['than' => 'occurred_at']],
            ['contained_at', 'validateNotBefore', 'params' => ['than' => 'detected_at']],
            ['closed_at', 'validateNotBefore', 'params' => ['than' => 'detected_at']],
            ['closed_at', 'validateNotBefore', 'params' => ['than' => 'contained_at']],
            ['regulator_notified_at', 'validateNotBefore', 'params' => ['than' => 'detected_at']],
            [$this->booleanAttributes(), 'boolean'],
            [$this->booleanAttributes(), 'default', 'value' => false],
            ['category_id', 'exist', 'targetClass' => Lookup::class, 'targetAttribute' => 'id', 'filter' => ['type' => Lookup::TYPE_INCIDENT_CATEGORY]],
            ['assigned_to_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            ['root_cause', 'required', 'when' => fn() => $this->status === self::STATUS_CLOSED && in_array($this->severity, [self::SEVERITY_CRITICAL, self::SEVERITY_HIGH], true), 'whenClient' => 'function(){return false;}', 'message' => 'Record the root cause before closing a high or critical incident.'],
            ['deviceIds', 'each', 'rule' => ['exist', 'targetClass' => Device::class, 'targetAttribute' => 'id']],
            ['cloudAssetIds', 'each', 'rule' => ['exist', 'targetClass' => CloudAsset::class, 'targetAttribute' => 'id']],
            ['riskIds', 'each', 'rule' => ['exist', 'targetClass' => Risk::class, 'targetAttribute' => 'id']],
            ['techniqueIds', 'each', 'rule' => ['exist', 'targetClass' => AttackTechnique::class, 'targetAttribute' => 'id']],
            [['detectedByControlIds', 'failedControlIds', 'responseControlIds'], 'each', 'rule' => ['exist', 'targetClass' => Control::class, 'targetAttribute' => 'id']],
        ];
    }

    public function beforeValidate(): bool
    {
        foreach (array_merge($this->dateTimeAttributes(), ['assigned_to_id', 'category_id']) as $a) {
            if ($this->$a === '') {
                $this->$a = null;
            }
        }
        // Regulator fields only apply when personal data is involved
        if (!$this->personal_data_involved) {
            $this->regulator_notification_required = false;
        }
        if (!$this->regulator_notification_required) {
            $this->regulator_notified_at = null;
            $this->regulator_reference = null;
        }
        // Auto-stamp lifecycle timestamps
        $now = Actor::now();
        if (in_array($this->status, self::CONTAINED_STATUSES, true) && empty($this->contained_at)) {
            $this->contained_at = max($now, (string) $this->detected_at);
        }
        if ($this->status === self::STATUS_CLOSED && empty($this->closed_at)) {
            $this->closed_at = max($now, (string) $this->detected_at);
        }
        if ($this->status !== self::STATUS_CLOSED && $this->isAttributeChanged('status') && $this->getOldAttribute('status') === self::STATUS_CLOSED) {
            $this->closed_at = null; // re-opened
        }
        return parent::beforeValidate();
    }

    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);
        if (!$this->autoTimeline) {
            return;
        }
        if ($insert) {
            IncidentTimeline::add($this->id, IncidentTimeline::TYPE_STATUS, sprintf('Incident logged with status %s, severity %s.', $this->getStatusLabel(), $this->getSeverityLabel()));
            return;
        }
        if (array_key_exists('status', $changedAttributes) && $changedAttributes['status'] !== $this->status) {
            $from = self::statusOptions()[$changedAttributes['status']] ?? $changedAttributes['status'];
            IncidentTimeline::add($this->id, IncidentTimeline::TYPE_STATUS, sprintf('Status changed from %s to %s.', $from, $this->getStatusLabel()));
        }
        if (array_key_exists('severity', $changedAttributes) && $changedAttributes['severity'] !== $this->severity) {
            $from = self::severityOptions()[$changedAttributes['severity']] ?? $changedAttributes['severity'];
            IncidentTimeline::add($this->id, IncidentTimeline::TYPE_STATUS, sprintf('Severity changed from %s to %s.', $from, $this->getSeverityLabel()));
        }
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'reference' => 'Reference',
            'title' => 'Title',
            'description' => 'Description',
            'management_summary' => 'Management summary',
            'category_id' => 'Category',
            'impact_confidentiality' => 'Confidentiality',
            'impact_integrity' => 'Integrity',
            'impact_availability' => 'Availability',
            'severity' => 'Severity',
            'status' => 'Status',
            'occurred_at' => 'Occurred at',
            'detected_at' => 'Detected at',
            'reported_at' => 'Reported at',
            'reported_by' => 'Reported by',
            'assigned_to_id' => 'Assigned to',
            'personal_data_involved' => 'Personal data involved',
            'regulator_notification_required' => 'Regulator notification required',
            'regulator_notified_at' => 'Regulator notified at',
            'subjects_notified_at' => 'Data subjects notified at',
            'regulator_reference' => 'Regulator reference',
            'containment_actions' => 'Containment actions',
            'recovery_actions' => 'Eradication / recovery actions',
            'root_cause' => 'Root cause',
            'lessons_learned' => 'Lessons learned',
            'contained_at' => 'Contained at',
            'closed_at' => 'Closed at',
        ];
    }

    // ---- Relations --------------------------------------------------------

    public function getCategory()
    {
        return $this->hasOne(Lookup::class, ['id' => 'category_id']);
    }

    public function getAssignedTo()
    {
        return $this->hasOne(User::class, ['id' => 'assigned_to_id']);
    }

    public function getTimeline()
    {
        return $this->hasMany(IncidentTimeline::class, ['incident_id' => 'id'])
            ->orderBy(['entry_at' => SORT_DESC, 'id' => SORT_DESC]);
    }

    public function getDevices()
    {
        return $this->hasMany(Device::class, ['id' => 'device_id'])->viaTable('incident_device', ['incident_id' => 'id']);
    }

    public function getCloudAssets()
    {
        return $this->hasMany(CloudAsset::class, ['id' => 'cloud_asset_id'])->viaTable('incident_cloud_asset', ['incident_id' => 'id']);
    }

    public function getRisks()
    {
        return $this->hasMany(Risk::class, ['id' => 'risk_id'])->viaTable('risk_incident', ['incident_id' => 'id']);
    }

    public function getDecisions()
    {
        return $this->hasMany(Decision::class, ['id' => 'decision_id'])->viaTable('decision_incident', ['incident_id' => 'id']);
    }

    /** @return array<string,Control[]> role => controls */
    public function getControlsByRole(): array
    {
        $out = array_fill_keys(array_keys(self::controlRoleOptions()), []);
        $pairs = (new \yii\db\Query())->select(['control_id', 'role'])->from('incident_control')->where(['incident_id' => $this->id])->all();
        $controls = $pairs ? Control::find()->where(['id' => array_column($pairs, 'control_id')])->orderBy('reference')->indexBy('id')->all() : [];
        foreach ($controls as $id => $control) {
            foreach ($pairs as $p) {
                if ((int) $p['control_id'] === $id && isset($out[$p['role']])) {
                    $out[$p['role']][] = $control;
                }
            }
        }
        return $out;
    }

    public function getTechniques()
    {
        return $this->hasMany(AttackTechnique::class, ['id' => 'technique_id'])->viaTable('incident_attack_technique', ['incident_id' => 'id'])
            ->with(['parent', 'tactics'])->orderBy(['attack_technique.external_id' => SORT_ASC]);
    }

    // ---- Computed -----------------------------------------------------------

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    public function getSeverityLabel(): string
    {
        return self::severityOptions()[$this->severity] ?? (string) $this->severity;
    }

    /** @return array<string,string> affected CIA properties, attribute => label */
    public function getImpacts(): array
    {
        return array_filter(self::IMPACT_ATTRIBUTES, fn(string $attr) => (bool) $this->$attr, ARRAY_FILTER_USE_KEY);
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }

    public function getRegulatorDeadline(): ?\DateTimeImmutable
    {
        return $this->regulator_notification_required ? IncidentMetrics::deadline($this->detected_at, self::deadlineHours()) : null;
    }

    public function getRegulatorDeadlineState(?string $now = null): string
    {
        return IncidentMetrics::deadlineState((bool) $this->regulator_notification_required, $this->detected_at, $this->regulator_notified_at, $now, self::deadlineHours());
    }

    public function getRegulatorSecondsRemaining(?string $now = null): ?int
    {
        return $this->regulator_notification_required
            ? IncidentMetrics::secondsRemaining($this->detected_at, $now, self::deadlineHours())
            : null;
    }

    public function getTimeToDetect(): ?int
    {
        return IncidentMetrics::timeToDetect($this->occurred_at, $this->detected_at);
    }

    public function getTimeToContain(): ?int
    {
        return IncidentMetrics::timeToContain($this->detected_at, $this->contained_at);
    }

    public function getTimeToClose(): ?int
    {
        return IncidentMetrics::timeToClose($this->detected_at, $this->closed_at);
    }
}
