<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * An organisation whose data Greywarden holds. There is one today; this class is the
 * single place that decides which one a request or job works for, so that per-organisation
 * data (the threat watchlist and triage so far) only needs this changing to serve several.
 *
 * @property int $id
 * @property string $name
 * @property string $created_at
 * @property string $updated_at
 */
class Organisation extends ActiveRecord
{
    public const DEFAULT_ID = 1;

    private static ?int $current = null;

    public static function tableName(): string
    {
        return 'organisation';
    }

    /** The organisation the current request or job is working for */
    public static function currentId(): int
    {
        return self::$current ?? self::DEFAULT_ID;
    }

    /** Works for another organisation until called with null (jobs looping over organisations, tests) */
    public static function switchTo(?int $id): void
    {
        self::$current = $id;
    }

    /** @return int[] every organisation, for jobs that run for each of them */
    public static function allIds(): array
    {
        return array_map('intval', self::find()->select('id')->orderBy(['id' => SORT_ASC])->column());
    }
}
