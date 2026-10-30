<?php

namespace app\modules\report\components;

use app\models\AuditLog;
use yii\db\Query;
use yii\helpers\Json;

/**
 * Records as they stood at a moment in the past, rebuilt from the audit trail.
 *
 * The audit log keeps every field change as [old, new], so undoing the changes
 * made after the moment, newest first, gives the values the record had then.
 * Records created after the moment are left out. Archived records are kept
 * (with archived_at as it was then), so callers decide what counts.
 *
 * Changes written outside the audit trail (none in normal use) cannot be undone
 * and show at their current value.
 */
final class History
{
    /** Audit actions that carry no field edits to undo */
    private const SKIP_ACTIONS = ['create', 'delete', 'export'];

    /**
     * @param string $at moment, 'Y-m-d H:i:s'
     * @return array<int,array<string,mixed>> id => row as at $at
     */
    public static function asAt(string $table, string $entityType, string $at): array
    {
        $rows = (new Query())->from($table)->where(['<=', 'created_at', $at])->indexBy('id')->all();
        if (!$rows) {
            return [];
        }
        $changes = (new Query())->select(['entity_id', 'changes'])->from(AuditLog::tableName())
            ->where(['entity_type' => $entityType])->andWhere(['>', 'created_at', $at])
            ->andWhere(['not in', 'action', self::SKIP_ACTIONS])->andWhere(['not', ['changes' => null]])
            ->orderBy(['id' => SORT_DESC])->all();
        foreach ($changes as $entry) {
            $id = (int) $entry['entity_id'];
            if (!isset($rows[$id])) {
                continue;
            }
            try {
                $fields = Json::decode($entry['changes']);
            } catch (\Throwable) {
                continue;
            }
            foreach ((array) $fields as $field => $pair) {
                if (array_key_exists($field, $rows[$id]) && is_array($pair) && count($pair) === 2) {
                    $rows[$id][$field] = array_values($pair)[0];
                }
            }
        }
        return $rows;
    }
}
