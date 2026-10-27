<?php

namespace app\models;

use app\behaviors\AuditBehavior;
use app\components\Actor;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * Admin-managed lookup values (categories, departments, locations).
 *
 * @property int $id
 * @property string $type
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property string $created_at
 * @property string $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Lookup extends ActiveRecord
{
    public const TYPE_RISK_CATEGORY = 'risk_category';
    public const TYPE_DECISION_CATEGORY = 'decision_category';
    public const TYPE_INCIDENT_CATEGORY = 'incident_category';
    public const TYPE_DEPARTMENT = 'department';
    public const TYPE_LOCATION = 'location';

    /** type => [label, [table, column] usages] */
    public static function types(): array
    {
        return [
            self::TYPE_RISK_CATEGORY => 'Risk categories',
            self::TYPE_DECISION_CATEGORY => 'Decision categories',
            self::TYPE_INCIDENT_CATEGORY => 'Incident categories',
            self::TYPE_DEPARTMENT => 'Departments',
            self::TYPE_LOCATION => 'Locations',
        ];
    }

    public static function usages(): array
    {
        return [
            self::TYPE_RISK_CATEGORY => [['risk', 'category_id']],
            self::TYPE_DECISION_CATEGORY => [['decision', 'category_id']],
            self::TYPE_INCIDENT_CATEGORY => [['incident', 'category_id']],
            self::TYPE_DEPARTMENT => [['device', 'department_id']],
            self::TYPE_LOCATION => [['device', 'location_id']],
        ];
    }

    public static function tableName(): string
    {
        return 'lookup';
    }

    public function behaviors(): array
    {
        return [
            'timestamp' => ['class' => TimestampBehavior::class, 'value' => static fn() => Actor::now()],
            'blameable' => ['class' => BlameableBehavior::class, 'value' => static fn() => Actor::id()],
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'lookup'],
        ];
    }

    public function rules(): array
    {
        return [
            [['type', 'name'], 'required'],
            [['name', 'description'], 'trim'],
            ['type', 'in', 'range' => array_keys(self::types())],
            ['name', 'string', 'max' => 128],
            ['description', 'string', 'max' => 255],
            ['sort_order', 'integer', 'min' => 0, 'max' => 100000],
            ['sort_order', 'default', 'value' => 0],
            ['is_active', 'boolean'],
            ['is_active', 'default', 'value' => true],
            ['name', 'unique', 'targetAttribute' => ['type', 'name'], 'message' => 'This name already exists for this list.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'type' => 'List',
            'name' => 'Name',
            'description' => 'Description',
            'sort_order' => 'Sort order',
            'is_active' => 'Active',
        ];
    }

    public function getAuditLabel(): string
    {
        return (self::types()[$this->type] ?? $this->type) . ': ' . $this->name;
    }

    public function formatAuditValue(string $attribute, mixed $value): string
    {
        return match ($attribute) {
            'type' => self::types()[$value] ?? (string) $value,
            'is_active' => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }

    /** Number of domain records referencing this value. */
    public function getUsageCount(): int
    {
        $count = 0;
        foreach (self::usages()[$this->type] ?? [] as [$table, $column]) {
            $count += (int) (new \yii\db\Query())->from($table)->where([$column => $this->id])->count('*', static::getDb());
        }
        return $count;
    }

    /**
     * Dropdown options for a list: active values plus the currently selected one.
     */
    public static function options(string $type, int|string|null $includeId = null): array
    {
        $query = static::find()
            ->where(['type' => $type])
            ->andWhere($includeId ? ['or', ['is_active' => 1], ['id' => $includeId]] : ['is_active' => 1])
            ->orderBy(['sort_order' => SORT_ASC, 'name' => SORT_ASC]);
        $options = [];
        foreach ($query->all() as $lookup) {
            $options[$lookup->id] = $lookup->name . ($lookup->is_active ? '' : ' (inactive)');
        }
        return $options;
    }

    /** All values of a type (for grid filters), including inactive. */
    public static function allOptions(string $type): array
    {
        return static::find()->select(['name', 'id'])->where(['type' => $type])
            ->orderBy(['sort_order' => SORT_ASC, 'name' => SORT_ASC])->indexBy('id')->column();
    }

    public static function nameOf(int|string|null $id): ?string
    {
        if (!$id) {
            return null;
        }
        static $cache = [];
        return $cache[$id] ??= static::find()->select('name')->where(['id' => $id])->scalar() ?: null;
    }
}
