<?php

namespace app\modules\attack\components;

use app\modules\attack\models\AttackTactic;
use Yii;
use yii\db\Query;

/**
 * Read access to the loaded ATT&CK catalogue (release info, tactics).
 */
final class Catalogue
{
    public const CACHE_KEY = 'attack.catalogue';

    /** Latest imported release: domain, version, released_at, imported_at, counts. */
    public static function release(): ?array
    {
        return self::cached()['release'];
    }

    /** "Enterprise ATT&CK v20.0" */
    public static function label(): string
    {
        $r = self::release();
        return $r ? sprintf('Enterprise ATT&CK v%s', $r['version']) : 'ATT&CK catalogue not loaded';
    }

    public static function isLoaded(): bool
    {
        return self::release() !== null;
    }

    /** @return AttackTactic[] current tactics in matrix order */
    public static function tactics(): array
    {
        return AttackTactic::find()->where(['is_deprecated' => 0])->orderBy(['sort_order' => SORT_ASC])->all();
    }

    private static function cached(): array
    {
        $load = static fn() => [
            'release' => (new Query())->from('attack_release')->orderBy(['id' => SORT_DESC])->limit(1)->one() ?: null,
        ];
        return Yii::$app->has('cache') ? Yii::$app->cache->getOrSet(self::CACHE_KEY, $load, 3600) : $load();
    }
}
