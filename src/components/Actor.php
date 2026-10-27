<?php

namespace app\components;

use Yii;
use yii\web\Request as WebRequest;

/**
 * Resolves "who is acting" for blameable columns and the audit trail.
 * Console commands (e.g. the demo seeder) may override the actor.
 */
final class Actor
{
    private static ?int $overrideId = null;
    private static ?string $overrideName = null;

    public static function override(?int $id, ?string $name = null): void
    {
        self::$overrideId = $id;
        self::$overrideName = $name;
    }

    public static function id(): ?int
    {
        if (self::$overrideId !== null) {
            return self::$overrideId;
        }
        if (Yii::$app && Yii::$app->has('user') && !Yii::$app->user->isGuest) {
            return (int) Yii::$app->user->id;
        }
        return null;
    }

    public static function name(): string
    {
        if (self::$overrideName !== null) {
            return self::$overrideName;
        }
        if (Yii::$app && Yii::$app->has('user') && !Yii::$app->user->isGuest) {
            return (string) Yii::$app->user->identity->username;
        }
        return Yii::$app instanceof \yii\console\Application ? 'console' : 'system';
    }

    public static function ip(): ?string
    {
        $request = Yii::$app ? Yii::$app->getRequest() : null;
        return $request instanceof WebRequest ? $request->getUserIP() : null;
    }

    public static function userAgent(): ?string
    {
        $request = Yii::$app ? Yii::$app->getRequest() : null;
        if ($request instanceof WebRequest && $request->getUserAgent()) {
            return mb_substr($request->getUserAgent(), 0, 255);
        }
        return null;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }
}
