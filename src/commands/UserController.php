<?php

namespace app\commands;

use app\components\Mfa;
use app\components\Rbac;
use app\models\PasswordPolicy;
use app\models\User;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * User administration from the command line.
 *
 *   php yii user/create-admin [username] [email] [password]   (defaults from ADMIN_* env)
 *   php yii user/reset-password <username> <password>
 *   php yii user/list
 */
class UserController extends Controller
{
    public function actionCreateAdmin(?string $username = null, ?string $email = null, ?string $password = null): int
    {
        $username ??= gw_env('ADMIN_USERNAME', 'admin');
        $email ??= gw_env('ADMIN_EMAIL', 'admin@example.com');
        $password ??= gw_env('ADMIN_PASSWORD');

        if ($existing = User::findByUsername($username)) {
            $this->stdout("User '{$username}' already exists (role: {$existing->getRoleLabel()}).\n", Console::FG_YELLOW);
            if ($this->interactive && $this->confirm('Reset its password, enable it and make it an admin?')) {
                $password = $password ?: $this->prompt('New password:', ['required' => true]);
                if ($err = PasswordPolicy::check($password)) {
                    $this->stderr($err . "\n", Console::FG_RED);
                    return ExitCode::DATAERR;
                }
                $existing->setPassword($password);
                $existing->generateAuthKey();
                $existing->status = User::STATUS_ACTIVE;
                $existing->must_change_password = true;
                $existing->save(false);
                $existing->assignRole(Rbac::ROLE_ADMIN);
                $this->stdout("Updated '{$username}'. Password change required at next sign-in.\n", Console::FG_GREEN);
            }
            return ExitCode::OK;
        }

        if (!$password) {
            $password = $this->prompt('Password for the new admin:', ['required' => true]);
        }
        if ($err = PasswordPolicy::check($password)) {
            $this->stderr($err . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $user = User::createUser($username, $email, gw_env('ADMIN_FULL_NAME', 'System Administrator'), $password, Rbac::ROLE_ADMIN, true);
        $this->stdout("Created admin '{$user->username}' <{$user->email}>. Password change required at first sign-in.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    public function actionResetPassword(string $username, string $password): int
    {
        $user = User::findByUsername($username);
        if (!$user) {
            $this->stderr("User '{$username}' not found.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        if ($err = PasswordPolicy::check($password)) {
            $this->stderr($err . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $user->setPassword($password);
        $user->generateAuthKey();
        $user->must_change_password = true;
        $user->save(false);
        \Yii::$app->db->createCommand()->delete('login_attempt', ['username' => [$user->username, $user->email], 'success' => 0])->execute();
        $this->stdout("Password reset for '{$username}' (change required at next sign-in, lockout cleared).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /** Removes a user's authenticator app, passkeys and recovery codes, for an admin locked out with no other admin to help */
    public function actionResetMfa(string $username): int
    {
        $user = User::findByUsername($username);
        if (!$user) {
            $this->stderr("User '{$username}' not found.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        Mfa::reset($user);
        \Yii::$app->db->createCommand()->delete('login_attempt', ['username' => [$user->username, $user->email], 'success' => 0])->execute();
        $this->stdout("Two-step sign-in reset for '{$username}' (lockout cleared). They sign in with their password"
            . (Mfa::isRequiredFor($user) ? ' and must set up a new method straight away' : '') . ".\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    public function actionList(): int
    {
        foreach (User::find()->orderBy('username')->all() as $u) {
            $this->stdout(sprintf("%-16s %-10s %-9s %-16s %s\n", $u->username, $u->getRoleLabel(), $u->isActive() ? 'active' : 'disabled', 'MFA: ' . Mfa::summary($u), $u->email));
        }
        return ExitCode::OK;
    }
}
