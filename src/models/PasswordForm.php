<?php

namespace app\models;

use Yii;
use yii\base\Model;

/**
 * Change own password.
 */
class PasswordForm extends Model
{
    public $current_password;
    public $new_password;
    public $new_password_repeat;

    private User $user;

    public function __construct(User $user, $config = [])
    {
        $this->user = $user;
        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            [['current_password', 'new_password', 'new_password_repeat'], 'required'],
            ['current_password', 'validateCurrent'],
            ['new_password', PasswordPolicy::class],
            ['new_password', 'compare', 'compareAttribute' => 'current_password', 'operator' => '!=', 'message' => 'The new password must differ from the current one.'],
            ['new_password_repeat', 'compare', 'compareAttribute' => 'new_password', 'message' => 'Passwords do not match.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'current_password' => 'Current password',
            'new_password' => 'New password',
            'new_password_repeat' => 'Confirm new password',
        ];
    }

    public function attributeHints(): array
    {
        return ['new_password' => PasswordPolicy::describe()];
    }

    public function validateCurrent(string $attribute): void
    {
        if (!$this->user->validatePassword((string) $this->$attribute)) {
            $this->addError($attribute, 'Current password is incorrect.');
        }
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $this->user->setPassword($this->new_password);
        $this->user->must_change_password = false;
        $this->user->save(false);
        AuditLog::record('user', $this->user->id, $this->user->getAuditLabel(), 'password_change');
        Yii::$app->session->regenerateID(true);
        return true;
    }
}
