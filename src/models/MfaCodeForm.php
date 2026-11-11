<?php

namespace app\models;

use app\components\LoginThrottle;
use app\components\Mfa;
use app\components\Totp;
use Yii;
use yii\base\Model;

/**
 * The second step of signing in: a code from the authenticator app, or a recovery code.
 * Wrong codes count towards the sign-in lockout like wrong passwords.
 */
class MfaCodeForm extends Model
{
    public $code;

    /** 'totp' or 'recovery', once verify() has passed */
    public ?string $method = null;

    public function __construct(private readonly User $user, array $config = [])
    {
        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            ['code', 'required', 'message' => 'Enter the code.'],
            ['code', 'trim'],
            ['code', 'string', 'max' => 32],
        ];
    }

    public function attributeLabels(): array
    {
        return ['code' => 'Code'];
    }

    public function verify(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $request = Yii::$app->request;
        if (($wait = (new LoginThrottle())->lockedFor($this->user->username, $request->getUserIP())) > 0) {
            Mfa::clearPending();
            AuditLog::record('user', $this->user->id, $this->user->getAuditLabel(), 'login_blocked', [], $this->user->id, $this->user->username);
            $this->addError('code', sprintf('Too many failed sign-in attempts. Try again in %d minute(s).', (int) ceil($wait / 60)));
            return false;
        }

        $digits = preg_replace('/\s+/', '', (string) $this->code);
        if (preg_match('/^\d{' . Totp::DIGITS . '}$/', $digits)) {
            $totp = UserTotp::findOne(['user_id' => $this->user->id]);
            $secret = $totp?->isConfirmed() ? $totp->getPlainSecret() : null;
            $counter = $secret !== null ? Totp::verify($secret, $digits, time(), $totp->last_counter === null ? null : (int) $totp->last_counter) : null;
            // Only one sign-in can use a time step, even if two arrive at once
            if ($counter !== null && UserTotp::updateAll(['last_counter' => $counter], ['and', ['user_id' => $this->user->id],
                    ['or', ['last_counter' => null], ['<', 'last_counter', $counter]]]) === 1) {
                $this->method = 'totp';
                return true;
            }
            Mfa::recordFailure($this->user, 'authenticator code');
        } elseif (Mfa::useRecoveryCode($this->user, (string) $this->code)) {
            $this->method = 'recovery';
            return true;
        } else {
            Mfa::recordFailure($this->user, 'recovery code');
        }
        $this->addError('code', 'That code is not right, or it has already been used.');
        return false;
    }
}
