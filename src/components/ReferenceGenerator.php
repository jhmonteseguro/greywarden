<?php

namespace app\components;

use yii\db\Connection;
use yii\db\Query;

/**
 * Generates human readable references such as RSK-0001, INC-0042, DEV-0007.
 *
 * Numbers come from a per-prefix counter (reference_sequence), so a number is never handed out
 * twice, even after the record that had it is hard-deleted. User-supplied custom tags
 * (e.g. "LAPTOP-JS") are ignored.
 */
final class ReferenceGenerator
{
    public static function format(string $prefix, int $number, int $padding = 4): string
    {
        return sprintf('%s-%0' . $padding . 'd', strtoupper($prefix), $number);
    }

    /** Returns the numeric part of a reference, or null if it does not follow PREFIX-<digits>. */
    public static function parse(string $prefix, ?string $reference): ?int
    {
        if ($reference === null) {
            return null;
        }
        if (preg_match('/^' . preg_quote(strtoupper($prefix), '/') . '-(\d+)$/i', trim($reference), $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** @param iterable<string|null> $existing */
    public static function nextNumber(string $prefix, iterable $existing): int
    {
        $max = 0;
        foreach ($existing as $reference) {
            $n = self::parse($prefix, $reference);
            if ($n !== null && $n > $max) {
                $max = $n;
            }
        }
        return $max + 1;
    }

    /**
     * The next reference for $table, never handing out a number twice.
     *
     * The number is one more than the higher of the last number handed out for this prefix
     * (kept in reference_sequence, so hard-deleting the newest record does not free its number)
     * and the highest reference already in the table. The counter update is a single atomic
     * statement whose row lock is held until the caller's transaction ends, so two records
     * saved at the same time never get the same number.
     */
    public static function next(string $table, string $attribute, string $prefix, int $padding = 4, ?Connection $db = null): string
    {
        $db ??= \Yii::$app->db;
        $prefix = strtoupper($prefix);
        $floor = self::nextNumber($prefix, self::existing($table, $attribute, $prefix, $db)) - 1;
        $db->createCommand(
            'INSERT INTO {{%reference_sequence}} ([[prefix]], [[last_number]]) VALUES (:prefix, LAST_INSERT_ID(:first))'
            . ' ON DUPLICATE KEY UPDATE [[last_number]] = LAST_INSERT_ID(GREATEST([[last_number]], :floor) + 1)',
            [':prefix' => $prefix, ':first' => $floor + 1, ':floor' => $floor]
        )->execute();
        $number = (int) $db->createCommand('SELECT LAST_INSERT_ID()')->queryScalar();
        return self::format($prefix, $number, $padding);
    }

    /**
     * The next reference from the table alone, without the counter. Only for migrations that ran
     * before reference_sequence existed.
     */
    public static function nextUnsequenced(string $table, string $attribute, string $prefix, int $padding = 4, ?Connection $db = null): string
    {
        return self::format($prefix, self::nextNumber($prefix, self::existing($table, $attribute, $prefix, $db)), $padding);
    }

    /** @return list<string|null> */
    private static function existing(string $table, string $attribute, string $prefix, ?Connection $db): array
    {
        return (new Query())
            ->select($attribute)
            ->from($table)
            ->where(['like', $attribute, strtoupper($prefix) . '-%', false])
            ->column($db);
    }
}
