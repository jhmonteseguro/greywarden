<?php

namespace app\models;

use Yii;
use yii\validators\Validator;

/**
 * Password strength validator: minimum length (params auth.passwordMinLength),
 * at least one letter and one digit or symbol, and not equal to the username.
 */
class PasswordPolicy extends Validator
{
    public static function minLength(): int
    {
        return (int) (Yii::$app->params['auth.passwordMinLength'] ?? 10);
    }

    public static function describe(): string
    {
        return sprintf('At least %d characters, including a letter and a number or symbol.', self::minLength());
    }

    public static function check(string $password): ?string
    {
        if (mb_strlen($password) < self::minLength()) {
            return sprintf('Password must be at least %d characters.', self::minLength());
        }
        if (mb_strlen($password) > 128) {
            return 'Password must be at most 128 characters.';
        }
        if (!preg_match('/\pL/u', $password) || !preg_match('/[\d\W_]/u', $password)) {
            return 'Password must include a letter and a number or symbol.';
        }
        return null;
    }

    public function validateAttribute($model, $attribute): void
    {
        $error = self::check((string) $model->$attribute);
        if ($error !== null) {
            $this->addError($model, $attribute, $error);
        }
    }
}
