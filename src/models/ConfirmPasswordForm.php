<?php

namespace app\models;

use app\components\LoginThrottle;
use app\components\Mfa;
use Yii;
use yii\base\Model;

/**
 * Asks for the password again before sign-in methods change (Mfa::CONFIRM_TTL), so someone
 * at an unlocked session cannot add their own passkey or turn two-step sign-in off.
 * Wrong passwords count towards the sign-in lockout.
 */
class ConfirmPasswordForm extends Model
{
    public $password;

    public function __construct(private readonly User $user, array $config = [])
    {
        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            ['password', 'required'],
            ['password', 'string', 'max' => 4096],
        ];
    }

    public function attributeLabels(): array
    {
        return ['password' => 'Password'];
    }

    public function confirm(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $request = Yii::$app->request;
        $throttle = new LoginThrottle();
        if (($wait = $throttle->lockedFor($this->user->username, $request->getUserIP())) > 0) {
            $this->addError('password', sprintf('Too many failed attempts. Try again in %d minute(s).', (int) ceil($wait / 60)));
            return false;
        }
        if (!$this->user->validatePassword((string) $this->password)) {
            $throttle->record($this->user->username, $request->getUserIP(), false, $request->getUserAgent());
            AuditLog::record('user', $this->user->id, $this->user->getAuditLabel(), 'login_failed', ['step' => [null, 'password confirmation']]);
            $this->addError('password', 'That password is not right.');
            return false;
        }
        Mfa::markConfirmed();
        return true;
    }
}
