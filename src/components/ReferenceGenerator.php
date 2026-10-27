<?php

namespace app\components;

use yii\db\Connection;
use yii\db\Query;

/**
 * Generates human readable references such as RSK-0001, INC-0042, DEV-0007.
 *
 * The next number is max(existing numeric suffix) + 1, so gaps are never
 * reused and user-supplied custom tags (e.g. "LAPTOP-JS") are ignored.
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

    public static function next(string $table, string $attribute, string $prefix, int $padding = 4, ?Connection $db = null): string
    {
        $existing = (new Query())
            ->select($attribute)
            ->from($table)
            ->where(['like', $attribute, strtoupper($prefix) . '-%', false])
            ->column($db);
        return self::format($prefix, self::nextNumber($prefix, $existing), $padding);
    }
}
