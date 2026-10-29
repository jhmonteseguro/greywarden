<?php

namespace app\modules\decision\models;

use app\models\DomainRecord;
use app\models\Lookup;
use app\modules\attack\models\AttackMitigation;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;

/**
 * Decision log entry.
 *
 * @property int $id
 * @property string $reference
 * @property string $title
 * @property string $decision_date
 * @property string|null $context
 * @property string|null $options_considered
 * @property string|null $decision_text
 * @property string|null $rationale
 * @property string|null $decision_makers
 * @property string|null $stakeholders
 * @property int|null $category_id
 * @property string $status
 * @property int|null $superseded_by_id
 * @property string|null $impact
 * @property string|null $review_date
 *
 * @property-read Lookup|null $category
 * @property-read Decision|null $supersededBy
 * @property-read Decision[] $supersedes
 * @property-read Risk[] $risks
 * @property-read Incident[] $incidents
 * @property-read AttackMitigation[] $mitigations ATT&CK mitigations this decision adopts
 * @property-read Control[] $controls controls this decision introduces or changes
 */
class Decision extends DomainRecord
{
    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_IMPLEMENTED = 'implemented';
    public const STATUS_SUPERSEDED = 'superseded';

    /** Statuses for which the review date matters */
    public const REVIEWABLE_STATUSES = [self::STATUS_PROPOSED, self::STATUS_APPROVED, self::STATUS_IMPLEMENTED];

    /** @var int[]|string|null link ids (null = not submitted) */
    public $riskIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $incidentIds = null;
    /** @var int[]|string|null ATT&CK mitigation ids (null = not submitted) */
    public $mitigationIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $controlIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $cloudAssetIds = null;
    /** @var int[]|string|null link ids (null = not submitted) */
    public $supplierIds = null;

    public static function tableName(): string
    {
        return 'decision';
    }

    public static function entityType(): string
    {
        return 'decision';
    }

    public static function entityLabel(): string
    {
        return 'Decision';
    }

    public static function referencePrefix(): ?string
    {
        return 'DEC';
    }

    public static function viewRoute(): string
    {
        return '/decision/decision/view';
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_PROPOSED => 'Proposed',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_IMPLEMENTED => 'Implemented',
            self::STATUS_SUPERSEDED => 'Superseded',
        ];
    }

    public function linkDefinitions(): array
    {
        return [
            'riskIds' => ['table' => 'risk_decision', 'self' => 'decision_id', 'other' => 'risk_id', 'class' => Risk::class, 'label' => 'Related risks'],
            'incidentIds' => ['table' => 'decision_incident', 'self' => 'decision_id', 'other' => 'incident_id', 'class' => Incident::class, 'label' => 'Related incidents'],
            'cloudAssetIds' => ['table' => 'decision_cloud_asset', 'self' => 'decision_id', 'other' => 'cloud_asset_id', 'class' => CloudAsset::class, 'label' => 'Related cloud assets'],
            'supplierIds' => ['table' => 'decision_supplier', 'self' => 'decision_id', 'other' => 'supplier_id', 'class' => Supplier::class, 'label' => 'Related suppliers'],
            'mitigationIds' => ['table' => 'decision_attack_mitigation', 'self' => 'decision_id', 'other' => 'mitigation_id', 'class' => AttackMitigation::class, 'label' => 'ATT&CK mitigations'],
            'controlIds' => ['table' => 'decision_control', 'self' => 'decision_id', 'other' => 'control_id', 'class' => Control::class, 'label' => 'Controls'],
        ];
    }

    public function enumAttributes(): array
    {
        return ['status' => self::statusOptions()];
    }

    public function lookupAttributes(): array
    {
        return ['category_id'];
    }

    public function formatAuditValue(string $attribute, mixed $value): string
    {
        if ($attribute === 'superseded_by_id' && $value) {
            return (string) (static::find()->select('reference')->where(['id' => $value])->scalar() ?: '#' . $value);
        }
        return parent::formatAuditValue($attribute, $value);
    }

    public function rules(): array
    {
        $text = ['title', 'context', 'options_considered', 'decision_text', 'rationale', 'decision_makers', 'stakeholders', 'impact'];
        return [
            [$text, 'trim'],
            [['title', 'decision_date', 'decision_text', 'decision_makers', 'status', 'category_id'], 'required'],
            ['title', 'string', 'max' => 255],
            ['decision_makers', 'string', 'max' => 255],
            [['context', 'options_considered', 'decision_text', 'rationale', 'stakeholders', 'impact'], 'string', 'max' => 20000],
            [['decision_date', 'review_date'], 'date', 'format' => 'php:Y-m-d'],
            ['review_date', 'validateNotBefore', 'params' => ['than' => 'decision_date']],
            ['status', 'in', 'range' => array_keys(self::statusOptions())],
            ['category_id', 'exist', 'targetClass' => Lookup::class, 'targetAttribute' => 'id', 'filter' => ['type' => Lookup::TYPE_DECISION_CATEGORY]],
            ['superseded_by_id', 'integer'],
            ['superseded_by_id', 'exist', 'targetClass' => self::class, 'targetAttribute' => 'id'],
            ['superseded_by_id', 'required', 'when' => fn() => $this->status === self::STATUS_SUPERSEDED, 'whenClient' => "function(){return $('#decision-status').val()==='superseded';}", 'message' => 'Select the decision that supersedes this one.'],
            ['superseded_by_id', 'compare', 'compareAttribute' => 'id', 'operator' => '!=', 'when' => fn() => !$this->isNewRecord, 'message' => 'A decision cannot supersede itself.'],
            ['riskIds', 'each', 'rule' => ['exist', 'targetClass' => Risk::class, 'targetAttribute' => 'id']],
            ['incidentIds', 'each', 'rule' => ['exist', 'targetClass' => Incident::class, 'targetAttribute' => 'id']],
            ['cloudAssetIds', 'each', 'rule' => ['exist', 'targetClass' => CloudAsset::class, 'targetAttribute' => 'id']],
            ['supplierIds', 'each', 'rule' => ['exist', 'targetClass' => Supplier::class, 'targetAttribute' => 'id']],
            ['mitigationIds', 'each', 'rule' => ['exist', 'targetClass' => AttackMitigation::class, 'targetAttribute' => 'id']],
            ['controlIds', 'each', 'rule' => ['exist', 'targetClass' => Control::class, 'targetAttribute' => 'id']],
        ];
    }

    public function beforeValidate(): bool
    {
        foreach (['superseded_by_id', 'review_date', 'category_id'] as $a) {
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
            'title' => 'Title',
            'decision_date' => 'Decision date',
            'context' => 'Context / problem statement',
            'options_considered' => 'Options considered',
            'decision_text' => 'Decision',
            'rationale' => 'Rationale',
            'decision_makers' => 'Decision maker(s)',
            'stakeholders' => 'Stakeholders consulted',
            'category_id' => 'Category',
            'status' => 'Status',
            'superseded_by_id' => 'Superseded by',
            'impact' => 'Impact / consequences',
            'review_date' => 'Review date',
        ];
    }

    public function getCategory()
    {
        return $this->hasOne(Lookup::class, ['id' => 'category_id']);
    }

    public function getSupersededBy()
    {
        return $this->hasOne(self::class, ['id' => 'superseded_by_id']);
    }

    public function getSupersedes()
    {
        return $this->hasMany(self::class, ['superseded_by_id' => 'id']);
    }

    public function getRisks()
    {
        return $this->hasMany(Risk::class, ['id' => 'risk_id'])->viaTable('risk_decision', ['decision_id' => 'id']);
    }

    public function getIncidents()
    {
        return $this->hasMany(Incident::class, ['id' => 'incident_id'])->viaTable('decision_incident', ['decision_id' => 'id']);
    }

    public function getCloudAssets()
    {
        return $this->hasMany(CloudAsset::class, ['id' => 'cloud_asset_id'])->viaTable('decision_cloud_asset', ['decision_id' => 'id']);
    }

    public function getSuppliers()
    {
        return $this->hasMany(Supplier::class, ['id' => 'supplier_id'])->viaTable('decision_supplier', ['decision_id' => 'id'])
            ->orderBy(['supplier.reference' => SORT_ASC]);
    }

    public function getControls()
    {
        return $this->hasMany(Control::class, ['id' => 'control_id'])->viaTable('decision_control', ['decision_id' => 'id'])
            ->orderBy(['control.reference' => SORT_ASC]);
    }

    public function getMitigations()
    {
        return $this->hasMany(AttackMitigation::class, ['id' => 'mitigation_id'])->viaTable('decision_attack_mitigation', ['decision_id' => 'id'])
            ->orderBy(['attack_mitigation.external_id' => SORT_ASC]);
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    /** Decisions still in force (or proposed) are reviewed periodically */
    public function isReviewable(): bool
    {
        return !$this->isArchived && in_array($this->status, self::REVIEWABLE_STATUSES, true);
    }

    public function isReviewOverdue(?string $today = null): bool
    {
        $today ??= date('Y-m-d');
        return $this->isReviewable() && $this->review_date && $this->review_date < $today;
    }

    /** Options for the "superseded by" dropdown (excludes itself). */
    public function supersededByOptions(): array
    {
        $options = self::selectOptions($this->superseded_by_id ? [$this->superseded_by_id] : []);
        unset($options[$this->id]);
        return $options;
    }
}
