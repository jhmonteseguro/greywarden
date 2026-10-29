<?php

namespace app\models;

use app\behaviors\AuditBehavior;
use app\behaviors\ReferenceBehavior;
use app\components\Actor;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * Base class for Risk, Decision, Incident and Device.
 *
 * Provides: timestamps + blameable, audit trail, reference generation,
 * soft delete (archive/restore), many-to-many link syncing and helpers used
 * to render audit values in a human readable form.
 *
 * @property int $id
 * @property string|null $archived_at
 * @property int|null $archived_by
 * @property string $created_at
 * @property string $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property-read bool $isArchived
 * @property-read User|null $creator
 * @property-read User|null $updater
 * @property-read User|null $archiver
 */
abstract class DomainRecord extends ActiveRecord
{
    /** Short type stored in audit_log.entity_type */
    abstract public static function entityType(): string;

    /** Human singular name, e.g. "Risk" */
    abstract public static function entityLabel(): string;

    /** Reference prefix e.g. "RSK"; null disables auto references */
    public static function referencePrefix(): ?string
    {
        return null;
    }

    public static function referenceAttribute(): string
    {
        return 'reference';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    /** Route of the view action, e.g. '/risk/risk/view' */
    abstract public static function viewRoute(): string;

    /**
     * Many-to-many link definitions: property => [table, self, other, class, label, where?]
     * The property must be declared public (?array, default null).
     * Optional `where` (column => value) scopes the link to some rows of a shared
     * table, e.g. ['role' => 'failed'], and is written on insert.
     */
    public function linkDefinitions(): array
    {
        return [];
    }

    /** attribute => [value => label] */
    public function enumAttributes(): array
    {
        return [];
    }

    /** attributes holding user ids */
    public function userAttributes(): array
    {
        return ['archived_by'];
    }

    /** attributes holding lookup ids */
    public function lookupAttributes(): array
    {
        return [];
    }

    public function booleanAttributes(): array
    {
        return [];
    }

    /** datetime attributes posted from <input type="datetime-local"> */
    public function dateTimeAttributes(): array
    {
        return [];
    }

    public static function find(): DomainQuery
    {
        return new DomainQuery(static::class);
    }

    public function behaviors(): array
    {
        $behaviors = [
            'timestamp' => ['class' => TimestampBehavior::class, 'value' => static fn() => Actor::now()],
            'blameable' => ['class' => BlameableBehavior::class, 'value' => static fn() => Actor::id()],
            'audit' => [
                'class' => AuditBehavior::class,
                'entityType' => static::entityType(),
                'virtualAttributes' => $this->auditLinkCallbacks(),
            ],
        ];
        if (static::referencePrefix() !== null) {
            $behaviors['reference'] = [
                'class' => ReferenceBehavior::class,
                'attribute' => static::referenceAttribute(),
                'prefix' => static::referencePrefix(),
            ];
        }
        return $behaviors;
    }

    public function attributeLabels(): array
    {
        return [
            'archived_at' => 'Archived',
            'archived_by' => 'Archived by',
            'created_at' => 'Created',
            'updated_at' => 'Last updated',
            'created_by' => 'Created by',
            'updated_by' => 'Updated by',
        ];
    }

    // ---- Identity / display ---------------------------------------------

    public function getReferenceValue(): string
    {
        return (string) $this->getAttribute(static::referenceAttribute());
    }

    public function getTitleValue(): string
    {
        return (string) $this->getAttribute(static::titleAttribute());
    }

    public function getAuditLabel(): string
    {
        return trim($this->getReferenceValue() . ' · ' . $this->getTitleValue(), ' ·');
    }

    public function getViewUrl(): array
    {
        return [static::viewRoute(), 'id' => $this->id];
    }

    // ---- Relations ------------------------------------------------------

    public function getCreator()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getUpdater()
    {
        return $this->hasOne(User::class, ['id' => 'updated_by']);
    }

    public function getArchiver()
    {
        return $this->hasOne(User::class, ['id' => 'archived_by']);
    }

    /** Whether the record is due periodic reviews (see RecordReview); off unless a record type says so */
    public function isReviewable(): bool
    {
        return false;
    }

    /** Interval to the next review when it depends on the record (e.g. "+24 months"); null = RecordReview's default for the type */
    public function reviewInterval(): ?string
    {
        return null;
    }

    // ---- Soft delete ------------------------------------------------------

    public function getIsArchived(): bool
    {
        return !empty($this->archived_at);
    }

    public function archive(): bool
    {
        if ($this->getIsArchived()) {
            return true;
        }
        $this->archived_at = Actor::now();
        $this->archived_by = Actor::id();
        return $this->save(false);
    }

    public function restore(): bool
    {
        if (!$this->getIsArchived()) {
            return true;
        }
        $this->archived_at = null;
        $this->archived_by = null;
        return $this->save(false);
    }

    // ---- Validation helpers -----------------------------------------------

    public function beforeValidate(): bool
    {
        foreach ($this->dateTimeAttributes() as $attribute) {
            $value = $this->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                $value = str_replace('T', ' ', trim($value));
                if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
                    $value .= ':00';
                }
                $this->setAttribute($attribute, $value);
            }
        }
        foreach (array_keys($this->linkDefinitions()) as $property) {
            if ($this->$property !== null) {
                $ids = is_array($this->$property) ? $this->$property : [];
                $this->$property = array_values(array_unique(array_map('intval', array_filter($ids, static fn($v) => $v !== '' && $v !== null))));
            }
        }
        return parent::beforeValidate();
    }

    /** Inline validator: $attribute must be >= $params['than'] (both date/datetime strings) */
    public function validateNotBefore(string $attribute, mixed $params): void
    {
        $other = $params['than'] ?? null;
        if (!$other || !$this->$other || !$this->$attribute || $this->hasErrors($attribute)) {
            return;
        }
        if (strtotime((string) $this->$attribute) < strtotime((string) $this->$other)) {
            $this->addError($attribute, sprintf('%s cannot be before %s.', $this->getAttributeLabel($attribute), $this->getAttributeLabel($other)));
        }
    }

    /** Store empty strings as NULL in nullable columns (the trim validator turns NULL into ''). */
    public function beforeSave($insert): bool
    {
        foreach (static::getTableSchema()->columns as $name => $column) {
            if ($column->allowNull && $this->getAttribute($name) === '') {
                $this->setAttribute($name, null);
            }
        }
        return parent::beforeSave($insert);
    }

    // ---- Many-to-many links -------------------------------------------------

    /** Fill link properties from the DB (for forms). */
    public function populateLinks(): void
    {
        foreach ($this->linkDefinitions() as $property => $def) {
            if ($this->$property === null) {
                $this->$property = $this->isNewRecord ? [] : $this->currentLinkIds($def);
            }
        }
    }

    protected function currentLinkIds(array $def): array
    {
        return array_map('intval', (new Query())->select($def['other'])->from($def['table'])
            ->where([$def['self'] => $this->id] + ($def['where'] ?? []))->column(static::getDb()));
    }

    public function afterSave($insert, $changedAttributes): void
    {
        foreach ($this->linkDefinitions() as $property => $def) {
            if ($this->$property !== null) {
                $this->syncLink($def, $this->$property);
            }
        }
        parent::afterSave($insert, $changedAttributes);
    }

    /** Hard delete: notes, reviews and attached files go with the record. */
    public function afterDelete(): void
    {
        parent::afterDelete();
        if (RecordNote::takesNotes(static::entityType())) {
            RecordNote::deleteAllFor(static::entityType(), $this->id);
        }
        if (RecordReview::takesReviews(static::entityType())) {
            RecordReview::deleteAllFor(static::entityType(), $this->id);
        }
        if (in_array(static::entityType(), Attachment::ENTITY_TYPES, true)) {
            Attachment::deleteAllFor(static::entityType(), $this->id);
        }
    }

    protected function syncLink(array $def, array $ids): void
    {
        $db = static::getDb();
        $current = $this->currentLinkIds($def);
        $ids = array_map('intval', $ids);
        $remove = array_diff($current, $ids);
        $add = array_diff($ids, $current);
        if ($remove) {
            $db->createCommand()->delete($def['table'], [$def['self'] => $this->id, $def['other'] => array_values($remove)] + ($def['where'] ?? []))->execute();
        }
        if ($add) {
            $now = Actor::now();
            $actor = Actor::id();
            $scope = $def['where'] ?? [];
            $rows = array_map(fn($id) => array_merge([$this->id, $id, $now, $actor], array_values($scope)), array_values($add));
            $db->createCommand()->batchInsert($def['table'], array_merge([$def['self'], $def['other'], 'created_at', 'created_by'], array_keys($scope)), $rows)->execute();
        }
    }

    /** Callbacks used by AuditBehavior to snapshot linked references. */
    protected function auditLinkCallbacks(): array
    {
        $callbacks = [];
        foreach ($this->linkDefinitions() as $property => $def) {
            $callbacks[$property] = function (self $model) use ($def) {
                if ($model->isNewRecord || !$model->id) {
                    return [];
                }
                /** @var DomainRecord $class */
                $class = $def['class'];
                return $class::find()
                    ->select($class::referenceAttribute())
                    ->innerJoin($def['table'] . ' l', 'l.' . $def['other'] . ' = ' . $class::tableName() . '.id')
                    ->where(['l.' . $def['self'] => $model->id])
                    ->andWhere(array_combine(array_map(static fn($c) => 'l.' . $c, array_keys($def['where'] ?? [])), $def['where'] ?? []))
                    ->column();
            };
        }
        return $callbacks;
    }

    /** Render a raw audit value for humans. */
    public function formatAuditValue(string $attribute, mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        $enums = $this->enumAttributes();
        if (isset($enums[$attribute])) {
            return (string) ($enums[$attribute][$value] ?? $value);
        }
        if (in_array($attribute, $this->userAttributes(), true)) {
            return User::nameOf($value) ?? ('#' . $value);
        }
        if (in_array($attribute, $this->lookupAttributes(), true)) {
            return Lookup::nameOf($value) ?? ('#' . $value);
        }
        if (in_array($attribute, $this->booleanAttributes(), true)) {
            return $value ? 'Yes' : 'No';
        }
        return (string) $value;
    }

    public function attributeLabelFor(string $attribute): string
    {
        $defs = $this->linkDefinitions();
        return isset($defs[$attribute]) ? $defs[$attribute]['label'] : $this->getAttributeLabel($attribute);
    }

    public function getAttributeLabel($attribute): string
    {
        $defs = $this->linkDefinitions();
        if (isset($defs[$attribute])) {
            return $defs[$attribute]['label'];
        }
        return parent::getAttributeLabel($attribute);
    }

    /**
     * Options for link dropdowns: non-archived records plus already linked ones.
     */
    public static function selectOptions(array $includeIds = []): array
    {
        $ref = static::referenceAttribute();
        $title = static::titleAttribute();
        $rows = static::find()
            ->select(['id', $ref, $title])
            ->where(['archived_at' => null])
            ->orFilterWhere(['id' => $includeIds ?: null])
            ->orderBy([$ref => SORT_ASC])
            ->asArray()->all();
        $options = [];
        foreach ($rows as $row) {
            $options[$row['id']] = $row[$ref] . ' · ' . $row[$title];
        }
        return $options;
    }
}
