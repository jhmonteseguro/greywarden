<?php

namespace app\models;

use app\components\SecretBox;
use yii\db\ActiveRecord;

/**
 * A user's authenticator app (TOTP). The secret is stored encrypted (components/SecretBox);
 * confirmed_at stays null until the user enters a first code from the app.
 *
 * @property int $user_id
 * @property string $secret
 * @property string|null $confirmed_at
 * @property int|null $last_counter
 * @property string $created_at
 */
class UserTotp extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'user_totp';
    }

    public function getPlainSecret(): ?string
    {
        return SecretBox::decrypt((string) $this->secret);
    }

    public function setPlainSecret(string $secret): void
    {
        $this->secret = SecretBox::encrypt($secret);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
