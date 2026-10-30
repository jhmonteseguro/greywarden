<?php

namespace app\modules\risk\models;

use app\components\Actor;
use app\helpers\RiskMatrix;
use app\models\DomainRecord;
use app\models\Lookup;
use app\models\User;
use app\modules\attack\models\AttackTechnique;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\supplier\models\Supplier;

/**
 * Risk register entry.
 *
 * @property int $id
 * @property string $reference
 * @property string $title
 * @property string|null $description
 * @property int|null $category_id
 * @property string|null $scope
 * @property string|null $threat_source
 * @property string|null $vulnerability
 * @property int|null $owner_id
 * @property int $inherent_likelihood
 * @property int $inherent_impact
 * @property int $inherent_score
 * @property string|null $existing_controls
 * @property string|null $treatment
 * @property string|null $treatment_plan
 * @property int|null $residual_likelihood
 * @property int|null $residual_impact
 * @property int|null $residual_score
 * @property int|null $target_likelihood
 * @property int|null $target_impact
 * @property int|null $target_score
 * @property string $date_identified
 * @property string|null $target_treatment_date
 * @property string|null $next_review_date
 * @property string $status
 * @property string|null $acceptance_justification
 * @property int|null $accepted_by_id
 * @property string|null $accepted_at
 *
 * @property-read Lookup|null $category
 * @property-read User|null $owner
 * @property-read User|null $acceptedBy
 * @property-read Device[] $devices
 * @property-read Incident[] $incidents
 * @property-read Decision[] $decisions
 * @property-read AttackTechnique[] $techniques ATT&CK techniques that would realise the risk
 * @property-read Control[] $controls controls that mitigate the risk
 */
class Risk extends DomainRecord
{
    public const STATUS_IDENTIFIED = 'identified';
    public const STATUS_ASSESSED = 'assessed';
    public const STATUS_IN_TREATMENT = 'in_treatment';
    public const STATUS_TREATED = 'treated';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_CLOSED = 'closed';

    public const TREATMENT_MITIGATE = 'mitigate';
    public const TREATMENT_ACCEPT = 'accept';
    public const TREATMENT_TRANSFER = 'transfer';
    public const TREATMENT_AVOID = 'avoid';

    /** Statuses for which a passed target treatment date is "overdue" */
    public const TREATMENT_PENDING_STATUSES = [self::STATUS_IDENTIFIED, self::STATUS_ASSESSED, self::STATUS_IN_TREATMENT];

    /** Statuses whose (residual) risk has been formally accepted by someone */
    public const ACCEPTANCE_STATUSES = [self::STATUS_TREATED, self::STATUS_ACCEPTED];

    // Many-to-many link properties (null = untouched)
    /** @var int[]|string|null link ids (null = not submitted) */
    public $deviceIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $cloudAssetIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $supplierIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $incidentIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $decisionIds = null;
    /** @var int[]|string|null ATT&CK technique ids (null = not submitted) */
    public $techniqueIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $controlIds = null;

    public static function tableName(): string
    {
        return 'risk';
    }

    public static function entityType(): string
    {
        return 'risk';
    }

    public static function entityLabel(): string
    {
        return 'Risk';
    }

    public static function referencePrefix(): ?string
    {
        return 'RSK';
    }

    public static function viewRoute(): string
    {
        return '/risk/risk/view';
    }

    public static function find(): RiskQuery
    {
        return new RiskQuery(static::class);
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_IDENTIFIED => 'Identified',
            self::STATUS_ASSESSED => 'Assessed',
            self::STATUS_IN_TREATMENT => 'In Treatment',
            self::STATUS_TREATED => 'Treated',
            self::STATUS_ACCEPTED => 'Accepted',
            self::STATUS_CLOSED => 'Closed',
        ];
    }

    public static function treatmentOptions(): array
    {
        return [
            self::TREATMENT_MITIGATE => 'Mitigate',
            self::TREATMENT_ACCEPT => 'Accept',
            self::TREATMENT_TRANSFER => 'Transfer',
            self::TREATMENT_AVOID => 'Avoid',
        ];
    }

    public function linkDefinitions(): array
    {
        return [
            'deviceIds' => ['table' => 'risk_device', 'self' => 'risk_id', 'other' => 'device_id', 'class' => Device::class, 'label' => 'Affected devices'],
            'cloudAssetIds' => ['table' => 'risk_cloud_asset', 'self' => 'risk_id', 'other' => 'cloud_asset_id', 'class' => CloudAsset::class, 'label' => 'Affected cloud assets'],
            'supplierIds' => ['table' => 'risk_supplier', 'self' => 'risk_id', 'other' => 'supplier_id', 'class' => Supplier::class, 'label' => 'Related suppliers'],
            'incidentIds' => ['table' => 'risk_incident', 'self' => 'risk_id', 'other' => 'incident_id', 'class' => Incident::class, 'label' => 'Related incidents'],
            'decisionIds' => ['table' => 'risk_decision', 'self' => 'risk_id', 'other' => 'decision_id', 'class' => Decision::class, 'label' => 'Related decisions'],
            'techniqueIds' => ['table' => 'risk_attack_technique', 'self' => 'risk_id', 'other' => 'technique_id', 'class' => AttackTechnique::class, 'label' => 'ATT&CK techniques'],
            'controlIds' => ['table' => 'risk_control', 'self' => 'risk_id', 'other' => 'control_id', 'class' => Control::class, 'label' => 'Controls'],
        ];
    }

    public function enumAttributes(): array
    {
        return [
            'status' => self::statusOptions(),
            'treatment' => self::treatmentOptions(),
            'inherent_likelihood' => RiskMatrix::axisOptions('likelihood'),
            'residual_likelihood' => RiskMatrix::axisOptions('likelihood'),
            'inherent_impact' => RiskMatrix::axisOptions('impact'),
            'residual_impact' => RiskMatrix::axisOptions('impact'),
            'target_likelihood' => RiskMatrix::axisOptions('likelihood'),
            'target_impact' => RiskMatrix::axisOptions('impact'),
        ];
    }

    public function userAttributes(): array
    {
        return ['owner_id', 'accepted_by_id', 'archived_by'];
    }

    public function lookupAttributes(): array
    {
        return ['category_id'];
    }

    public function rules(): array
    {
        $text = ['title', 'description', 'scope', 'threat_source', 'vulnerability', 'existing_controls', 'treatment_plan', 'acceptance_justification'];
        return [
            [$text, 'trim'],
            [['title', 'category_id', 'owner_id', 'inherent_likelihood', 'inherent_impact', 'status', 'date_identified'], 'required'],
            ['title', 'string', 'max' => 255],
            ['scope', 'string', 'max' => 255],
            [['description', 'threat_source', 'vulnerability', 'existing_controls', 'treatment_plan', 'acceptance_justification'], 'string', 'max' => 20000],
            [['inherent_likelihood', 'inherent_impact', 'residual_likelihood', 'residual_impact', 'target_likelihood', 'target_impact'], 'integer', 'min' => RiskMatrix::MIN, 'max' => RiskMatrix::MAX],
            [['residual_likelihood'], 'required', 'when' => fn() => $this->residual_impact !== null && $this->residual_impact !== '', 'whenClient' => 'function(){return false;}', 'message' => 'Residual likelihood is required when residual impact is set.'],
            [['residual_impact'], 'required', 'when' => fn() => $this->residual_likelihood !== null && $this->residual_likelihood !== '', 'whenClient' => 'function(){return false;}', 'message' => 'Residual impact is required when residual likelihood is set.'],
            ['residual_impact', 'validateResidual'],
            [['target_likelihood'], 'required', 'when' => fn() => $this->target_impact !== null && $this->target_impact !== '', 'whenClient' => 'function(){return false;}', 'message' => 'Target likelihood is required when target impact is set.'],
            [['target_impact'], 'required', 'when' => fn() => $this->target_likelihood !== null && $this->target_likelihood !== '', 'whenClient' => 'function(){return false;}', 'message' => 'Target impact is required when target likelihood is set.'],
            ['target_impact', 'validateTarget'],
            ['status', 'in', 'range' => array_keys(self::statusOptions())],
            ['treatment', 'in', 'range' => array_keys(self::treatmentOptions())],
            ['treatment', 'required', 'when' => fn() => in_array($this->status, [self::STATUS_IN_TREATMENT, self::STATUS_TREATED, self::STATUS_ACCEPTED], true), 'whenClient' => 'function(){return false;}', 'message' => 'Choose a treatment for risks that are in treatment, treated or accepted.'],
            ['treatment', 'compare', 'compareValue' => self::TREATMENT_ACCEPT, 'operator' => '!=', 'type' => 'string', 'when' => fn() => $this->status === self::STATUS_TREATED, 'whenClient' => 'function(){return false;}', 'message' => 'A treated risk was mitigated, transferred or avoided. Use Accepted for a risk taken on without treatment.'],
            [['residual_likelihood', 'residual_impact'], 'required', 'when' => fn() => $this->status === self::STATUS_TREATED, 'whenClient' => 'function(){return false;}', 'message' => 'Record the residual rating left after treatment.'],
            [['date_identified', 'target_treatment_date', 'next_review_date', 'accepted_at'], 'date', 'format' => 'php:Y-m-d'],
            ['category_id', 'exist', 'targetClass' => Lookup::class, 'targetAttribute' => 'id', 'filter' => ['type' => Lookup::TYPE_RISK_CATEGORY]],
            [['owner_id', 'accepted_by_id'], 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            // Acceptance
            ['acceptance_justification', 'required', 'when' => fn() => $this->isAcceptance(), 'whenClient' => "function(){return ['accepted','treated'].indexOf($('#risk-status').val())>=0;}", 'message' => 'A justification is required when the risk or its residual risk is accepted.'],
            [['accepted_by_id', 'accepted_at'], 'required', 'when' => fn() => $this->isAcceptance(), 'whenClient' => 'function(){return false;}'],
            // Links
            ['deviceIds', 'each', 'rule' => ['exist', 'targetClass' => Device::class, 'targetAttribute' => 'id']],
            ['cloudAssetIds', 'each', 'rule' => ['exist', 'targetClass' => CloudAsset::class, 'targetAttribute' => 'id']],
            ['supplierIds', 'each', 'rule' => ['exist', 'targetClass' => Supplier::class, 'targetAttribute' => 'id']],
            ['incidentIds', 'each', 'rule' => ['exist', 'targetClass' => Incident::class, 'targetAttribute' => 'id']],
            ['decisionIds', 'each', 'rule' => ['exist', 'targetClass' => Decision::class, 'targetAttribute' => 'id']],
            ['techniqueIds', 'each', 'rule' => ['exist', 'targetClass' => AttackTechnique::class, 'targetAttribute' => 'id']],
            ['controlIds', 'each', 'rule' => ['exist', 'targetClass' => Control::class, 'targetAttribute' => 'id']],
        ];
    }

    /** Residual score must not exceed inherent score. */
    public function validateResidual(string $attribute): void
    {
        $inherent = RiskMatrix::score($this->inherent_likelihood, $this->inherent_impact);
        $residual = RiskMatrix::score($this->residual_likelihood, $this->residual_impact);
        if ($inherent !== null && $residual !== null && $residual > $inherent) {
            $this->addError($attribute, sprintf('Residual score (%d) cannot exceed the inherent score (%d).', $residual, $inherent));
        }
    }

    /** Target score must not exceed the current score (residual if assessed, otherwise inherent). */
    public function validateTarget(string $attribute): void
    {
        $target = RiskMatrix::score($this->target_likelihood, $this->target_impact);
        $residual = RiskMatrix::score($this->residual_likelihood, $this->residual_impact);
        $current = $residual ?? RiskMatrix::score($this->inherent_likelihood, $this->inherent_impact);
        if ($target !== null && $current !== null && $target > $current) {
            $this->addError($attribute, sprintf('Target score (%d) cannot exceed the %s score (%d).', $target, $residual !== null ? 'residual' : 'inherent', $current));
        }
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'reference' => 'Reference',
            'title' => 'Title',
            'description' => 'Description',
            'category_id' => 'Category',
            'scope' => 'Asset / scope affected',
            'threat_source' => 'Threat source',
            'vulnerability' => 'Vulnerability',
            'owner_id' => 'Owner',
            'inherent_likelihood' => 'Inherent likelihood',
            'inherent_impact' => 'Inherent impact',
            'inherent_score' => 'Inherent score',
            'existing_controls' => 'Existing controls',
            'treatment' => 'Treatment',
            'treatment_plan' => 'Treatment plan / planned controls',
            'residual_likelihood' => 'Residual likelihood',
            'residual_impact' => 'Residual impact',
            'residual_score' => 'Residual score',
            'target_likelihood' => 'Target likelihood',
            'target_impact' => 'Target impact',
            'target_score' => 'Target score',
            'date_identified' => 'Date identified',
            'target_treatment_date' => 'Target treatment date',
            'next_review_date' => 'Next review date',
            'status' => 'Status',
            'acceptance_justification' => 'Acceptance justification',
            'accepted_by_id' => 'Accepted by',
            'accepted_at' => 'Accepted on',
        ];
    }

    public function beforeValidate(): bool
    {
        foreach (['residual_likelihood', 'residual_impact', 'target_likelihood', 'target_impact', 'treatment', 'accepted_by_id', 'accepted_at', 'target_treatment_date', 'next_review_date'] as $a) {
            if ($this->$a === '') {
                $this->$a = null;
            }
        }
        if ($this->isAcceptance()) {
            $this->accepted_by_id = $this->accepted_by_id ?: Actor::id();
            $this->accepted_at = $this->accepted_at ?: Actor::today();
        }
        if ($this->status === self::STATUS_ACCEPTED) {
            $this->treatment = $this->treatment ?: self::TREATMENT_ACCEPT;
        }
        return parent::beforeValidate();
    }

    public function beforeSave($insert): bool
    {
        $this->inherent_score = RiskMatrix::score($this->inherent_likelihood, $this->inherent_impact);
        $this->residual_score = RiskMatrix::score($this->residual_likelihood, $this->residual_impact);
        $this->target_score = RiskMatrix::score($this->target_likelihood, $this->target_impact);
        return parent::beforeSave($insert);
    }

    // ---- Relations ------------------------------------------------------

    public function getCategory()
    {
        return $this->hasOne(Lookup::class, ['id' => 'category_id']);
    }

    public function getOwner()
    {
        return $this->hasOne(User::class, ['id' => 'owner_id']);
    }

    public function getAcceptedBy()
    {
        return $this->hasOne(User::class, ['id' => 'accepted_by_id']);
    }

    public function getDevices()
    {
        return $this->hasMany(Device::class, ['id' => 'device_id'])->viaTable('risk_device', ['risk_id' => 'id']);
    }

    public function getCloudAssets()
    {
        return $this->hasMany(CloudAsset::class, ['id' => 'cloud_asset_id'])->viaTable('risk_cloud_asset', ['risk_id' => 'id']);
    }

    public function getSuppliers()
    {
        return $this->hasMany(Supplier::class, ['id' => 'supplier_id'])->viaTable('risk_supplier', ['risk_id' => 'id'])
            ->orderBy(['supplier.reference' => SORT_ASC]);
    }

    public function getIncidents()
    {
        return $this->hasMany(Incident::class, ['id' => 'incident_id'])->viaTable('risk_incident', ['risk_id' => 'id']);
    }

    public function getDecisions()
    {
        return $this->hasMany(Decision::class, ['id' => 'decision_id'])->viaTable('risk_decision', ['risk_id' => 'id']);
    }

    public function getControls()
    {
        return $this->hasMany(Control::class, ['id' => 'control_id'])->viaTable('risk_control', ['risk_id' => 'id'])
            ->orderBy(['control.reference' => SORT_ASC]);
    }

    public function getTechniques()
    {
        return $this->hasMany(AttackTechnique::class, ['id' => 'technique_id'])->viaTable('risk_attack_technique', ['risk_id' => 'id'])
            ->with(['parent', 'tactics'])->orderBy(['attack_technique.external_id' => SORT_ASC]);
    }

    // ---- Computed -------------------------------------------------------

    public function getInherentScoreValue(): ?int
    {
        return RiskMatrix::score($this->inherent_likelihood, $this->inherent_impact);
    }

    public function getResidualScoreValue(): ?int
    {
        return RiskMatrix::score($this->residual_likelihood, $this->residual_impact);
    }

    public function getTargetScoreValue(): ?int
    {
        return RiskMatrix::score($this->target_likelihood, $this->target_impact);
    }

    public function getInherentRating(): ?string
    {
        return RiskMatrix::rating($this->getInherentScoreValue());
    }

    public function getResidualRating(): ?string
    {
        return RiskMatrix::rating($this->getResidualScoreValue());
    }

    public function getTargetRating(): ?string
    {
        return RiskMatrix::rating($this->getTargetScoreValue());
    }

    /** Residual if assessed, otherwise inherent. */
    public function getCurrentScore(): ?int
    {
        return $this->getResidualScoreValue() ?? $this->getInherentScoreValue();
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }

    /** Accepted without treatment, or treated with the residual risk accepted */
    public function isAcceptance(): bool
    {
        return in_array($this->status, self::ACCEPTANCE_STATUSES, true);
    }

    /** Open risks are reviewed periodically */
    public function isReviewable(): bool
    {
        return $this->isOpen() && !$this->isArchived;
    }

    public function isReviewOverdue(?string $today = null): bool
    {
        $today ??= date('Y-m-d');
        return $this->isReviewable() && $this->next_review_date && $this->next_review_date < $today;
    }

    public function isTreatmentOverdue(?string $today = null): bool
    {
        $today ??= date('Y-m-d');
        return in_array($this->status, self::TREATMENT_PENDING_STATUSES, true)
            && !$this->isArchived && $this->target_treatment_date && $this->target_treatment_date < $today;
    }

    /**
     * Linked controls the risk should not rely on, with the reasons.
     * @return array<int,array{control:Control, reasons:string[]}> control id => issue
     */
    public function getControlIssues(?string $today = null): array
    {
        if (!$this->isOpen()) {
            return [];
        }
        $out = [];
        foreach ($this->controls as $control) {
            if ($reasons = $control->weaknesses($today)) {
                $out[$control->id] = ['control' => $control, 'reasons' => $reasons];
            }
        }
        return $out;
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    public function getTreatmentLabel(): ?string
    {
        return $this->treatment ? (self::treatmentOptions()[$this->treatment] ?? $this->treatment) : null;
    }
}
