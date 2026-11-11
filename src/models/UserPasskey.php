<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * A passkey (WebAuthn credential) a user registered: its public key, the authenticator's
 * signature counter and a name the user chose ("MacBook", "YubiKey"). components/Passkeys
 * creates and checks them.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $credential_hash
 * @property string $credential_id
 * @property string $public_key
 * @property int $sign_count
 * @property string|null $aaguid
 * @property int $backed_up
 * @property string $created_at
 * @property string|null $last_used_at
 */
class UserPasskey extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'user_passkey';
    }

    /** @return self[] */
    public static function forUser(int $userId): array
    {
        return self::find()->where(['user_id' => $userId])->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])->all();
    }
}
