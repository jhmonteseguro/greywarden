<?php

namespace app\modules\intel\models;

use app\components\Actor;
use app\models\AuditLog;
use app\models\User;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\Report;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * An investigated IP address or ASN: lookup history, the last automated
 * score, the analyst's verdict and notes. Created on first investigation.
 *
 * @property int $id
 * @property string $type ip | asn
 * @property string $value
 * @property string|null $verdict
 * @property string|null $verdict_at
 * @property int|null $verdict_by
 * @property int|null $last_score
 * @property string|null $last_rating
 * @property string|null $summary
 * @property string|null $country
 * @property int|null $asn
 * @property int $lookup_count
 * @property string|null $last_checked_at
 * @property int|null $last_checked_by
 * @property string $created_at
 * @property string $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property-read IntelNote[] $notes
 * @property-read User|null $verdictBy
 * @property-read User|null $lastCheckedBy
 */
class IntelIndicator extends ActiveRecord
{
    public const VERDICT_MALICIOUS = 'malicious';
    public const VERDICT_SUSPICIOUS = 'suspicious';
    public const VERDICT_BENIGN = 'benign';

    public static function tableName(): string
    {
        return 'intel_indicator';
    }

    public static function verdictOptions(): array
    {
        return [
            self::VERDICT_MALICIOUS => 'Malicious',
            self::VERDICT_SUSPICIOUS => 'Suspicious',
            self::VERDICT_BENIGN => 'Benign',
        ];
    }

    public function behaviors(): array
    {
        return [
            'timestamp' => ['class' => TimestampBehavior::class, 'value' => static fn() => Actor::now()],
            'blameable' => ['class' => BlameableBehavior::class, 'value' => static fn() => Actor::id()],
        ];
    }

    public function rules(): array
    {
        return [
            ['verdict', 'in', 'range' => array_keys(self::verdictOptions()), 'skipOnEmpty' => true],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'value' => 'Indicator',
            'verdict' => 'Analyst verdict',
            'last_score' => 'Score',
            'last_rating' => 'Assessment',
            'lookup_count' => 'Lookups',
            'last_checked_at' => 'Last investigated',
        ];
    }

    public function getNotes()
    {
        return $this->hasMany(IntelNote::class, ['indicator_id' => 'id'])->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]);
    }

    public function getVerdictBy()
    {
        return $this->hasOne(User::class, ['id' => 'verdict_by']);
    }

    public function getLastCheckedBy()
    {
        return $this->hasOne(User::class, ['id' => 'last_checked_by']);
    }

    public function getIndicator(): Indicator
    {
        return $this->type === Indicator::TYPE_ASN ? Indicator::asn((int) substr($this->value, 2)) : Indicator::ip($this->value);
    }

    public function getViewUrl(): array
    {
        return $this->getIndicator()->url();
    }

    public static function findFor(Indicator $indicator): ?self
    {
        return self::findOne(['type' => $indicator->type, 'value' => $indicator->value]);
    }

    public static function findOrCreate(Indicator $indicator): self
    {
        return self::findFor($indicator) ?? new self([
            'type' => $indicator->type,
            'value' => $indicator->value,
            'asn' => $indicator->asn,
            'lookup_count' => 0,
        ]);
    }

    /** Store the outcome of an investigation (score, owner line) and count the lookup. */
    public static function recordLookup(Report $report): self
    {
        $model = self::findOrCreate($report->indicator);
        $assessment = $report->assessment();
        $f = $report->facts;
        $asn = $report->indicator->isAsn() ? $report->indicator->asn : ($f['asn'] ?? null);
        $owner = $f['as_name'] ?? $f['org'] ?? null;
        $model->last_score = $assessment->score;
        $model->last_rating = $assessment->rating;
        $model->summary = $owner !== null ? mb_substr(($asn && $report->indicator->isIp() ? 'AS' . $asn . ' · ' : '') . $owner, 0, 255) : ($report->indicator->specialPurpose() ?? $model->summary);
        $model->country = isset($f['country']) && strlen($f['country']) === 2 ? strtoupper($f['country']) : ($f['registered_country'] ?? $model->country);
        $model->asn = $asn !== null ? (int) $asn : $model->asn;
        $model->lookup_count = (int) $model->lookup_count + 1;
        $model->last_checked_at = Actor::now();
        $model->last_checked_by = Actor::id();
        $model->save(false);
        return $model;
    }

    /** Set (or clear) the analyst verdict; the change is written to the audit log. */
    public function setVerdict(?string $verdict): bool
    {
        $verdict = $verdict === '' ? null : $verdict;
        if ($verdict !== null && !isset(self::verdictOptions()[$verdict])) {
            return false;
        }
        $old = $this->verdict;
        if ($old === $verdict) {
            return true;
        }
        $this->verdict = $verdict;
        $this->verdict_at = $verdict ? Actor::now() : null;
        $this->verdict_by = $verdict ? Actor::id() : null;
        if (!$this->save(false)) {
            return false;
        }
        AuditLog::record('indicator', $this->id, $this->getAuditLabel(), 'update', ['verdict' => [$old, $verdict]]);
        return true;
    }

    public function getAuditLabel(): string
    {
        return $this->value . ($this->summary ? ' · ' . $this->summary : '');
    }

    public function getReferenceValue(): string
    {
        return $this->value;
    }

    public function getTitleValue(): string
    {
        return (string) $this->summary;
    }

    /** Used by AuditLog to render diffs */
    public function formatAuditValue(string $attribute, mixed $value): string
    {
        return $attribute === 'verdict' ? (self::verdictOptions()[$value] ?? (string) $value) : (string) $value;
    }
}
