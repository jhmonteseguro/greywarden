<?php

namespace app\behaviors;

use app\models\AuditLog;
use yii\base\Behavior;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;

/**
 * Records create / update / archive / restore / delete events with a
 * per-field old -> new diff into the audit_log table.
 *
 * Attach to any ActiveRecord:
 *   'audit' => ['class' => AuditBehavior::class, 'entityType' => 'risk']
 *
 * "Virtual" attributes (e.g. many-to-many links) are supported through
 * callbacks that return a scalar/array snapshot before and after saving.
 */
class AuditBehavior extends Behavior
{
    /** Short entity name stored in audit_log.entity_type */
    public string $entityType;

    /** Attributes never recorded */
    public array $ignored = ['created_at', 'updated_at', 'created_by', 'updated_by'];

    /** Attributes recorded only as "changed" with masked values */
    public array $masked = ['password_hash', 'auth_key'];

    /** name => callable(ActiveRecord $owner): mixed */
    public array $virtualAttributes = [];

    /** Globally switch auditing off (e.g. bulk maintenance). */
    public static bool $enabled = true;

    private array $virtualBefore = [];

    public function events(): array
    {
        return [
            BaseActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            BaseActiveRecord::EVENT_BEFORE_UPDATE => 'beforeUpdate',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            BaseActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }

    public function afterInsert(AfterSaveEvent $event): void
    {
        if (!self::$enabled) {
            return;
        }
        $changes = [];
        foreach ($this->owner->getAttributes() as $name => $value) {
            if (in_array($name, $this->ignored, true) || $name === 'id' || self::isEmpty($value)) {
                continue;
            }
            $changes[$name] = [null, $this->mask($name, $value)];
        }
        foreach ($this->virtualAttributes as $name => $callback) {
            $value = self::normalizeVirtual($callback($this->owner));
            if (!self::isEmpty($value)) {
                $changes[$name] = [null, $value];
            }
        }
        $this->write('create', $changes);
    }

    public function beforeUpdate(): void
    {
        $this->virtualBefore = [];
        foreach ($this->virtualAttributes as $name => $callback) {
            $this->virtualBefore[$name] = self::normalizeVirtual($callback($this->owner));
        }
    }

    public function afterUpdate(AfterSaveEvent $event): void
    {
        if (!self::$enabled) {
            return;
        }
        $changes = [];
        foreach ($event->changedAttributes as $name => $old) {
            if (in_array($name, $this->ignored, true)) {
                continue;
            }
            $new = $this->owner->getAttribute($name);
            if (self::same($old, $new)) {
                continue;
            }
            $changes[$name] = [$this->mask($name, $old), $this->mask($name, $new)];
        }
        foreach ($this->virtualAttributes as $name => $callback) {
            $old = $this->virtualBefore[$name] ?? null;
            $new = self::normalizeVirtual($callback($this->owner));
            if (!self::same($old, $new)) {
                $changes[$name] = [$old, $new];
            }
        }
        if ($changes === []) {
            return;
        }

        $action = 'update';
        if (array_key_exists('archived_at', $changes)) {
            [$old, $new] = $changes['archived_at'];
            $action = self::isEmpty($old) ? 'archive' : (self::isEmpty($new) ? 'restore' : 'update');
        }
        $this->write($action, $changes);
    }

    public function afterDelete(): void
    {
        if (!self::$enabled) {
            return;
        }
        $changes = [];
        foreach ($this->owner->getOldAttributes() as $name => $value) {
            if (in_array($name, $this->ignored, true) || self::isEmpty($value)) {
                continue;
            }
            $changes[$name] = [$this->mask($name, $value), null];
        }
        $this->write('delete', $changes);
    }

    private function write(string $action, array $changes): void
    {
        $owner = $this->owner;
        $label = method_exists($owner, 'getAuditLabel') ? $owner->getAuditLabel() : null;
        $pk = $owner->getPrimaryKey();
        if ($pk === null && $action === 'delete') {
            $pk = $owner->getOldPrimaryKey();
        }
        AuditLog::record($this->entityType, is_array($pk) ? null : (int) $pk, $label, $action, $changes);
    }

    private function mask(string $name, mixed $value): mixed
    {
        if (in_array($name, $this->masked, true)) {
            return self::isEmpty($value) ? null : '********';
        }
        return $value;
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** Loose comparison that treats "3" and 3, null and '' as equal. */
    public static function same(mixed $a, mixed $b): bool
    {
        if (self::isEmpty($a) && self::isEmpty($b)) {
            return true;
        }
        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }
        if (is_bool($a) || is_bool($b)) {
            return (int) $a === (int) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (string) (0 + $a) === (string) (0 + $b);
        }
        return (string) $a === (string) $b;
    }

    private static function normalizeVirtual(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = array_values(array_map('strval', $value));
            sort($value, SORT_NATURAL);
        }
        return $value;
    }
}
