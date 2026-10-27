<?php

namespace app\modules\admin\models;

use app\models\AuditLog;
use app\models\PasswordPolicy;
use app\models\User;
use yii\base\Model;

/**
 * Admin sets a temporary password for a user.
 */
class ResetPasswordForm extends Model
{
    public $password;
    public $must_change_password = true;

    public function rules(): array
    {
        return [
            ['password', 'required'],
            ['password', PasswordPolicy::class],
            ['must_change_password', 'boolean'],
        ];
    }

    public function attributeLabels(): array
    {
        return ['password' => 'New temporary password', 'must_change_password' => 'Require change at next sign-in'];
    }

    public function attributeHints(): array
    {
        return ['password' => PasswordPolicy::describe()];
    }

    public function apply(User $user): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $user->setPassword($this->password);
        $user->generateAuthKey();
        $user->must_change_password = (bool) $this->must_change_password;
        $user->save(false);
        AuditLog::record('user', $user->id, $user->getAuditLabel(), 'password_reset');
        return true;
    }
}
