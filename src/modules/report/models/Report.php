<?php

namespace app\modules\report\models;

use app\components\Actor;
use app\models\AuditLog;
use app\models\DomainRecord;
use app\models\User;
use app\modules\report\components\ReportBuilder;
use app\modules\report\components\ReportPeriod;
use yii\helpers\Json;

/**
 * A management report: the security posture over a period (month, quarter,
 * year to date or custom dates).
 *
 * The figures are worked out by ReportBuilder when the report is created and
 * frozen in `data`, so a report never changes because a record was edited
 * afterwards. While it is a draft, analysts write the management summary, set
 * the overall status (RAG) and may regenerate the figures; once approved it is
 * read-only. An administrator can withdraw the approval.
 *
 * @property int $id
 * @property string $reference
 * @property string $title
 * @property string $period_type
 * @property string $period_start
 * @property string $period_end
 * @property string $status draft | approved
 * @property string|null $rag green | amber | red
 * @property string|null $management_summary
 * @property string|null $data JSON figures (ReportBuilder)
 * @property int $data_version
 * @property string|null $generated_at
 * @property int|null $generated_by
 * @property string|null $approved_at
 * @property int|null $approved_by_id
 * @property string|null $approval_note
 *
 * @property-read User|null $generator
 * @property-read User|null $approvedBy
 */
class Report extends DomainRecord
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';

    public const SUMMARY_MAX = 10000;
    public const NOTE_MAX = 2000;

    public const SCENARIO_CREATE = 'create';

    /** Create form: one of ReportPeriod::presets(), or "custom" for the dates entered */
    public $preset;

    private ?array $figures = null;

    public static function tableName(): string
    {
        return 'report';
    }

    public static function entityType(): string
    {
        return 'report';
    }

    public static function entityLabel(): string
    {
        return 'Report';
    }

    public static function referencePrefix(): ?string
    {
        return 'RPT';
    }

    public static function viewRoute(): string
    {
        return '/report/report/view';
    }

    public static function statusOptions(): array
    {
        return [self::STATUS_DRAFT => 'Draft', self::STATUS_APPROVED => 'Approved'];
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        // The figures are frozen JSON; their regeneration is logged on its own
        $behaviors['audit']['ignored'] = ['created_at', 'updated_at', 'created_by', 'updated_by', 'data', 'data_version'];
        return $behaviors;
    }

    public function scenarios(): array
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_CREATE] = ['preset', 'title', 'period_type', 'period_start', 'period_end'];
        $scenarios[self::SCENARIO_DEFAULT] = ['title', 'rag', 'management_summary'];
        return $scenarios;
    }

    public function enumAttributes(): array
    {
        return [
            'status' => self::statusOptions(),
            'rag' => ReportBuilder::ragOptions(),
            'period_type' => ReportPeriod::typeOptions(),
        ];
    }

    public function userAttributes(): array
    {
        return array_merge(parent::userAttributes(), ['generated_by', 'approved_by_id']);
    }

    public function rules(): array
    {
        return [
            [['title', 'management_summary', 'preset'], 'trim'],
            ['preset', 'in', 'range' => array_merge(array_keys(ReportPeriod::presets()), [ReportPeriod::CUSTOM]), 'on' => self::SCENARIO_CREATE],
            [['period_type', 'period_start', 'period_end'], 'required', 'on' => self::SCENARIO_CREATE],
            ['period_type', 'in', 'range' => array_keys(ReportPeriod::typeOptions())],
            [['period_start', 'period_end'], 'date', 'format' => 'php:Y-m-d'],
            ['period_end', 'compare', 'compareAttribute' => 'period_start', 'operator' => '>=', 'type' => 'string',
                'message' => 'The period cannot end before it starts.'],
            ['period_end', 'compare', 'compareValue' => Actor::today(), 'operator' => '<=', 'type' => 'string',
                'message' => 'The period cannot end in the future: a report covers what has happened.'],
            ['period_end', 'validateLength'],
            ['title', 'string', 'max' => 255],
            ['title', 'required', 'except' => self::SCENARIO_CREATE],
            ['rag', 'in', 'range' => array_keys(ReportBuilder::ragOptions())],
            ['rag', 'default', 'value' => null],
            ['management_summary', 'string', 'max' => self::SUMMARY_MAX],
        ];
    }

    public function validateLength(string $attribute): void
    {
        if (!$this->hasErrors() && $this->period_start && ReportPeriod::days($this->period_start, $this->period_end) > ReportPeriod::MAX_DAYS) {
            $this->addError($attribute, sprintf('A report covers at most %d days.', ReportPeriod::MAX_DAYS));
        }
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'reference' => 'Reference',
            'title' => 'Title',
            'preset' => 'Period',
            'period_type' => 'Kind of period',
            'period_start' => 'From',
            'period_end' => 'To',
            'status' => 'Status',
            'rag' => 'Overall status',
            'management_summary' => 'Management summary',
            'generated_at' => 'Figures generated',
            'generated_by' => 'Generated by',
            'approved_at' => 'Approved',
            'approved_by_id' => 'Approved by',
            'approval_note' => 'Approval note',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'title' => 'Leave blank for the usual title, e.g. "Security posture report · October 2026".',
            'rag' => 'Your overall judgement for the period. The report suggests one from its figures; you decide.',
            'management_summary' => 'Three to five plain-English lines for readers who stop at the first page: the overall position, what changed and what you need from them.',
        ];
    }

    /** Fills the period from the chosen preset (create form) and the title when left blank */
    public function beforeValidate(): bool
    {
        if ($this->scenario === self::SCENARIO_CREATE && $this->preset !== null && $this->preset !== ReportPeriod::CUSTOM) {
            $preset = ReportPeriod::presets()[$this->preset] ?? null;
            if ($preset !== null) {
                [$this->period_type, $this->period_start, $this->period_end] = [$preset['type'], $preset['start'], $preset['end']];
            }
        }
        if ($this->isNewRecord && ($this->title === null || $this->title === '') && $this->period_type && $this->period_start && $this->period_end
            && strtotime((string) $this->period_start) && strtotime((string) $this->period_end)) {
            $this->title = ReportPeriod::title($this->period_type, $this->period_start, $this->period_end);
        }
        return parent::beforeValidate();
    }

    // ---- Relations -------------------------------------------------------------

    public function getGenerator()
    {
        return $this->hasOne(User::class, ['id' => 'generated_by']);
    }

    public function getApprovedBy()
    {
        return $this->hasOne(User::class, ['id' => 'approved_by_id']);
    }

    // ---- Figures --------------------------------------------------------------------

    /** The frozen figures (ReportBuilder::build()), or [] before the first generation */
    public function getFigures(): array
    {
        if ($this->figures === null) {
            try {
                $this->figures = $this->data ? (array) Json::decode($this->data) : [];
            } catch (\Throwable) {
                $this->figures = [];
            }
        }
        return $this->figures;
    }

    /** Whether this build of the app can show the stored figures */
    public function hasReadableFigures(): bool
    {
        return ($this->getFigures()['version'] ?? null) === ReportBuilder::VERSION;
    }

    /** The latest earlier report, to compare figures that have no history: the same kind of period first */
    public function findPrevious(): ?self
    {
        $query = self::find()->active()->andWhere(['<', 'period_end', $this->period_start])->andFilterWhere(['<>', 'id', $this->id])
            ->orderBy(['period_end' => SORT_DESC, 'id' => SORT_DESC]);
        return (clone $query)->andWhere(['period_type' => $this->period_type])->one() ?? $query->one();
    }

    /** Works out the figures for the period (not saved) */
    public function generate(): void
    {
        $previous = $this->findPrevious();
        $figures = (new ReportBuilder($this->period_type, $this->period_start, $this->period_end,
            $previous ? $previous->getFigures() + ['reference' => $previous->reference] : null))->build();
        $this->data = Json::encode($figures);
        $this->data_version = ReportBuilder::VERSION;
        $this->generated_at = Actor::now();
        $this->generated_by = Actor::id();
        $this->figures = $figures;
    }

    /** Saves a new report with its figures and the suggested overall status as a starting point; false when invalid */
    public function create(): bool
    {
        $this->loadDefaultValues();
        if (!$this->validate()) {
            return false;
        }
        $this->generate();
        $this->rag = $this->getFigures()['suggested_rag'] ?? null;
        return $this->save(false);
    }

    /** Draft only: works the figures out again and logs it in the history */
    public function regenerate(): bool
    {
        if (!$this->isDraft() || $this->getIsArchived()) {
            return false;
        }
        $before = $this->generated_at;
        $this->generate();
        $this->updateAttributes(['data' => $this->data, 'data_version' => $this->data_version, 'generated_at' => $this->generated_at,
            'generated_by' => $this->generated_by, 'updated_at' => Actor::now(), 'updated_by' => Actor::id()]);
        AuditLog::record(self::entityType(), $this->id, $this->getAuditLabel(), 'regenerate', ['generated_at' => [$before, $this->generated_at]]);
        return true;
    }

    // ---- Sign-off ---------------------------------------------------------------------

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** What still stops a draft from being approved (empty when it can be) */
    public function approvalBlockers(): array
    {
        $out = [];
        if ($this->getIsArchived()) {
            $out[] = 'The report is archived.';
        }
        if (!$this->isDraft()) {
            $out[] = 'The report is already approved.';
        }
        if (!$this->rag) {
            $out[] = 'Set the overall status.';
        }
        if (trim((string) $this->management_summary) === '') {
            $out[] = 'Write the management summary.';
        }
        if (!$this->hasReadableFigures()) {
            $out[] = 'Regenerate the figures.';
        }
        return $out;
    }

    /** Signs the report off as the current user; refused (false) while approvalBlockers() lists anything */
    public function approve(?string $note = null): bool
    {
        if ($this->approvalBlockers()) {
            return false;
        }
        $note = trim((string) $note);
        $note = $note === '' ? null : mb_substr($note, 0, self::NOTE_MAX);
        $this->updateAttributes(['status' => self::STATUS_APPROVED, 'approved_at' => Actor::now(), 'approved_by_id' => Actor::id(),
            'approval_note' => $note, 'updated_at' => Actor::now(), 'updated_by' => Actor::id()]);
        AuditLog::record(self::entityType(), $this->id, $this->getAuditLabel(), 'approve',
            array_filter(['status' => [self::STATUS_DRAFT, self::STATUS_APPROVED], 'approval_note' => $note !== null ? [null, $note] : null]));
        return true;
    }

    /** Back to draft (administrators): the approval is cleared, the history keeps it */
    public function withdrawApproval(): bool
    {
        if (!$this->isApproved() || $this->getIsArchived()) {
            return false;
        }
        $was = [$this->approved_by_id, $this->approved_at];
        $this->updateAttributes(['status' => self::STATUS_DRAFT, 'approved_at' => null, 'approved_by_id' => null, 'approval_note' => null,
            'updated_at' => Actor::now(), 'updated_by' => Actor::id()]);
        AuditLog::record(self::entityType(), $this->id, $this->getAuditLabel(), 'approval_withdraw',
            ['status' => [self::STATUS_APPROVED, self::STATUS_DRAFT], 'approved_by_id' => [$was[0], null], 'approved_at' => [$was[1], null]]);
        return true;
    }

    // ---- Display -----------------------------------------------------------------------

    public function getPeriodName(): string
    {
        return ReportPeriod::name($this->period_type, $this->period_start, $this->period_end);
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? $this->status;
    }

    public function getRagLabel(): ?string
    {
        return $this->rag ? (ReportBuilder::ragOptions()[$this->rag] ?? $this->rag) : null;
    }
}
