<?php

namespace app\models;

use app\components\Actor;
use app\components\LoginThrottle;
use Yii;
use yii\base\Model;

/**
 * Login form with rate limiting / temporary lockout.
 */
class LoginForm extends Model
{
    public $username;
    public $password;

    /** Used to equalise timing when the user does not exist */
    private const DUMMY_HASH = '$2y$12$dTDAw0rl/..8ND4y9GOzneeiGu5M5i5FKvYPGZRFqVR9g.huka2Ua';

    public function rules(): array
    {
        return [
            [['username', 'password'], 'required'],
            ['username', 'trim'],
            ['username', 'string', 'max' => 190],
            ['password', 'string', 'max' => 4096],
        ];
    }

    public function attributeLabels(): array
    {
        return ['username' => 'Username or email', 'password' => 'Password'];
    }

    public function login(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $request = Yii::$app->request;
        $ip = $request->getUserIP();
        $throttle = new LoginThrottle();

        if (($wait = $throttle->lockedFor($this->username, $ip)) > 0) {
            AuditLog::record('user', null, $this->username, 'login_blocked', [], null, mb_substr($this->username, 0, 64));
            $this->addError('password', sprintf('Too many failed sign-in attempts. Try again in %d minute(s).', (int) ceil($wait / 60)));
            return false;
        }

        $user = User::findByLogin($this->username);
        $valid = $user !== null
            ? $user->validatePassword($this->password)
            : (Yii::$app->security->validatePassword($this->password, self::DUMMY_HASH) && false);

        if (!$user || !$valid || !$user->isActive()) {
            $throttle->record($this->username, $ip, false, $request->getUserAgent());
            AuditLog::record('user', $user?->id, $this->username, 'login_failed', [], null, mb_substr($this->username, 0, 64));
            $this->addError('password', 'Incorrect username or password, or the account is disabled.');
            return false;
        }

        $throttle->record($this->username, $ip, true, $request->getUserAgent());
        $user->updateAttributes(['last_login_at' => Actor::now(), 'last_login_ip' => $ip]);
        Yii::$app->user->login($user);
        AuditLog::record('user', $user->id, $user->getAuditLabel(), 'login');
        return true;
    }
}
