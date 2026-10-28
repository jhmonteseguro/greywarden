<?php

namespace app\modules\control\models;

use app\behaviors\AuditBehavior;
use app\components\Actor;
use app\modules\control\components\FrameworkCatalogue;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * An ISO 27001 Annex A control, a Cyber Essentials requirement or a SOC 2
 * criterion, with its Statement of Applicability entry (applicable + justification).
 *
 * Mirrors the DomainRecord identity API (reference / title / view URL) so it
 * can be used in link definitions, audit snapshots and search results.
 *
 * @property int $id
 * @property string $framework
 * @property string $external_id
 * @property string $group_code
 * @property string $group_name
 * @property string $title
 * @property string|null $description
 * @property int $sort_order
 * @property bool $applicable
 * @property string|null $justification
 * @property bool $partial_coverage linked controls cover only part of the requirement
 * @property string|null $coverage_gap what they do not cover
 * @property string|null $updated_at
 * @property int|null $updated_by
 *
 * @property-read Control[] $controls
 */
class FrameworkRequirement extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'framework_requirement';
    }

    public static function referenceAttribute(): string
    {
        return 'external_id';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public function behaviors(): array
    {
        return [
            'timestamp' => ['class' => TimestampBehavior::class, 'createdAtAttribute' => false, 'value' => static fn() => Actor::now()],
            'blameable' => ['class' => BlameableBehavior::class, 'createdByAttribute' => false, 'value' => static fn() => Actor::id()],
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'requirement'],
        ];
    }

    public function rules(): array
    {
        return [
            [['justification', 'coverage_gap'], 'trim'],
            [['applicable', 'partial_coverage'], 'boolean'],
            [['justification', 'coverage_gap'], 'string', 'max' => 20000],
            ['justification', 'required', 'when' => fn() => !$this->applicable, 'whenClient' => "function(){return !$('#frameworkrequirement-applicable').is(':checked');}",
                'message' => 'Say why this requirement does not apply.'],
            ['coverage_gap', 'required', 'when' => fn() => (bool) $this->partial_coverage, 'whenClient' => "function(){return $('#frameworkrequirement-partial_coverage').is(':checked');}",
                'message' => 'Say what the linked controls do not cover.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'external_id' => 'ID',
            'title' => 'Requirement',
            'group_name' => 'Theme',
            'applicable' => 'Applicable',
            'justification' => 'Justification',
            'partial_coverage' => 'Controls cover only part of this requirement',
            'coverage_gap' => 'Coverage gap',
        ];
    }

    public function formatAuditValue(string $attribute, mixed $value): string
    {
        return in_array($attribute, ['applicable', 'partial_coverage'], true) ? ($value ? 'Yes' : 'No') : (string) $value;
    }

    public function getReferenceValue(): string
    {
        return (string) $this->external_id;
    }

    public function getTitleValue(): string
    {
        return (string) $this->title;
    }

    public function getAuditLabel(): string
    {
        return $this->external_id . ' · ' . $this->title;
    }

    public function getIsArchived(): bool
    {
        return false;
    }

    public function getViewUrl(): array
    {
        return ['/control/framework/requirement', 'id' => $this->id];
    }

    public function getFrameworkLabel(): string
    {
        return FrameworkCatalogue::shortLabel($this->framework);
    }

    /** Shown at all: its framework is switched on (and, for SOC 2, its category is in scope) */
    public function getIsInScope(): bool
    {
        return FrameworkCatalogue::inScope($this->framework, $this->group_code);
    }

    public function getControls()
    {
        return $this->hasMany(Control::class, ['id' => 'control_id'])->viaTable('control_framework_requirement', ['requirement_id' => 'id'])
            ->orderBy(['control.reference' => SORT_ASC]);
    }

    /** Options for a framework's requirement picker, grouped by theme (requirements in scope only) */
    public static function selectOptions(string $framework): array
    {
        $options = [];
        foreach (static::find()->where(['framework' => $framework])->andWhere(FrameworkCatalogue::scopeCondition())->orderBy('sort_order')->all() as $r) {
            $options[$r->group_name][$r->id] = $r->external_id . ' · ' . $r->title . ($r->applicable ? '' : ' (not applicable)');
        }
        return $options;
    }

    /** Ids of in-scope requirements whose id or title matches, e.g. "A.8.7" or "malware" */
    public static function idsMatching(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        return array_map('intval', (new Query())->select('id')->from(static::tableName())
            ->where(['or', ['external_id' => $term], ['like', 'external_id', $term . '%', false], ['like', 'title', $term]])
            ->andWhere(FrameworkCatalogue::scopeCondition())->column());
    }

    /**
     * The given requirement ids that are out of scope (framework switched off,
     * or a SOC 2 category not chosen).
     * @param int[] $ids
     * @return int[]
     */
    public static function idsOutOfScope(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return array_map('intval', (new Query())->select('id')->from(static::tableName())
            ->where(['id' => $ids])->andWhere(['not', FrameworkCatalogue::scopeCondition()])->column());
    }
}
