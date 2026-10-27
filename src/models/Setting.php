<?php

namespace app\models;

use app\components\Actor;
use Yii;
use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * Instance setting (name => value), edited in Admin › Settings. Read through
 * components/Branding rather than directly.
 *
 * All rows are loaded once per request. A missing table (an install that has
 * not run its migrations yet) reads as "nothing set", so pages still render
 * with the defaults.
 *
 * @property string $name
 * @property string|null $value
 * @property string $updated_at
 * @property int|null $updated_by
 */
class Setting extends ActiveRecord
{
    /** @var array<string,string|null>|null name => value, for this request */
    private static ?array $values = null;

    public static function tableName(): string
    {
        return 'setting';
    }

    /** @return array<string,string|null> every stored setting */
    public static function values(): array
    {
        if (self::$values === null) {
            try {
                self::$values = (new Query())->select(['value', 'name'])->from(self::tableName())->indexBy('name')->column();
            } catch (\yii\db\Exception $e) {
                Yii::warning('Settings unavailable, using defaults: ' . $e->getMessage(), __METHOD__);
                self::$values = [];
            }
        }
        return self::$values;
    }

    /** Stored value, or $default when the setting was never saved */
    public static function get(string $name, ?string $default = null): ?string
    {
        $values = self::values();
        return array_key_exists($name, $values) ? $values[$name] : $default;
    }

    /**
     * Saves several settings at once (null stores "explicitly empty").
     * @param array<string,string|null> $values
     */
    public static function put(array $values): void
    {
        $db = Yii::$app->db;
        foreach ($values as $name => $value) {
            $db->createCommand()->upsert(self::tableName(),
                ['name' => $name, 'value' => $value, 'updated_at' => Actor::now(), 'updated_by' => Actor::id()],
                ['value' => $value, 'updated_at' => Actor::now(), 'updated_by' => Actor::id()],
            )->execute();
        }
        self::reset();
    }

    /** Forget the values loaded for this request */
    public static function reset(): void
    {
        self::$values = null;
    }
}
