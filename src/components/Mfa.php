<?php

namespace app\components;

use app\models\AuditLog;
use app\models\Setting;
use app\models\User;
use app\models\UserPasskey;
use app\models\UserTotp;
use Yii;
use yii\db\Query;

/**
 * Two-step sign-in: who must use it, which methods a user has, recovery codes, and the sign-in
 * that waits for the second step.
 *
 * Methods: an authenticator app (UserTotp) and passkeys (UserPasskey, components/Passkeys). A
 * passkey also signs in on its own, without the password. Recovery codes are made when a user
 * sets up their first method; each works once.
 *
 * Admin › Settings › Sign-in security can require it of administrators or of everyone
 * (setting auth.mfa_required); MfaGuard then sends users without a method to set one up.
 */
final class Mfa
{
    public const SETTING_REQUIRED = 'auth.mfa_required';
    public const REQUIRED_OFF = '';
    public const REQUIRED_ADMINS = 'admins';
    public const REQUIRED_ALL = 'all';

    public const RECOVERY_CODES = 10;
    /** Seconds the password step stays valid while the second step is done */
    public const PENDING_TTL = 300;
    /** Seconds after signing in or confirming the password in which sign-in methods can be changed */
    public const CONFIRM_TTL = 900;

    private const SESSION_PENDING = 'mfa.pending';
    private const SESSION_CONFIRMED = 'mfa.confirmedAt';
    private const SESSION_NEW_CODES = 'mfa.newCodes';
    /** Recovery code characters: lower case letters and digits without the lookalikes i, l, o, 0 and 1 (31, so 16 make ~79 bits) */
    private const CODE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function requiredOptions(): array
    {
        return [
            self::REQUIRED_OFF => 'Optional: each user chooses',
            self::REQUIRED_ADMINS => 'Required for administrators',
            self::REQUIRED_ALL => 'Required for everyone',
        ];
    }

    public static function policy(): string
    {
        $value = (string) Setting::get(self::SETTING_REQUIRED, '');
        return array_key_exists($value, self::requiredOptions()) ? $value : self::REQUIRED_OFF;
    }

    public static function isRequiredFor(User $user): bool
    {
        return match (self::policy()) {
            self::REQUIRED_ALL => true,
            self::REQUIRED_ADMINS => $user->getRoleName() === Rbac::ROLE_ADMIN,
            default => false,
        };
    }

    public static function hasTotp(User $user): bool
    {
        return UserTotp::find()->where(['user_id' => $user->id])->andWhere(['not', ['confirmed_at' => null]])->exists();
    }

    public static function passkeyCount(User $user): int
    {
        return (int) UserPasskey::find()->where(['user_id' => $user->id])->count();
    }

    public static function isEnabled(User $user): bool
    {
        return self::hasTotp($user) || self::passkeyCount($user) > 0;
    }

    public static function recoveryCodesLeft(User $user): int
    {
        return (int) (new Query())->from('user_recovery_code')->where(['user_id' => $user->id, 'used_at' => null])->count();
    }

    /** Short status for lists: "App + 2 passkeys", "Off" */
    public static function summary(User $user): string
    {
        $parts = [];
        if (self::hasTotp($user)) {
            $parts[] = 'App';
        }
        $passkeys = self::passkeyCount($user);
        if ($passkeys > 0) {
            $parts[] = $passkeys === 1 ? '1 passkey' : "$passkeys passkeys";
        }
        return $parts ? implode(' + ', $parts) : 'Off';
    }

    /**
     * Replaces the user's recovery codes with new ones and returns them in plain text: the only
     * time they are seen. Stored as SHA-256 hashes; 80 random bits each, so a hash cannot be reversed.
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(User $user): array
    {
        $db = Yii::$app->db;
        $codes = [];
        $now = Actor::now();
        $db->createCommand()->delete('user_recovery_code', ['user_id' => $user->id])->execute();
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $code = self::randomCode();
            $codes[] = $code;
            $db->createCommand()->insert('user_recovery_code', [
                'user_id' => $user->id, 'code_hash' => self::hashCode($code), 'created_at' => $now,
            ])->execute();
        }
        AuditLog::record('user', $user->id, $user->getAuditLabel(), 'recovery_codes');
        return $codes;
    }

    /** Uses up a recovery code; false when it is not one of the user's unused codes */
    public static function useRecoveryCode(User $user, string $code): bool
    {
        $normalised = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($normalised) !== 16) {
            return false;
        }
        // One statement, so only one sign-in can use the code even if two arrive at once
        $used = Yii::$app->db->createCommand()->update('user_recovery_code', ['used_at' => Actor::now()],
            ['user_id' => $user->id, 'code_hash' => self::hashCode($normalised), 'used_at' => null])->execute();
        if ($used < 1) {
            return false;
        }
        AuditLog::record('user', $user->id, $user->getAuditLabel(), 'recovery_code_used', [], $user->id, $user->username);
        return true;
    }

    /** Creates recovery codes when the user has none left, and keeps them for the next page */
    public static function ensureRecoveryCodes(User $user): void
    {
        if (self::recoveryCodesLeft($user) === 0) {
            self::keepNewCodes(self::generateRecoveryCodes($user));
        }
    }

    public static function keepNewCodes(array $codes): void
    {
        Yii::$app->session->set(self::SESSION_NEW_CODES, $codes);
    }

    /** The codes made by the last request, once: they are removed from the session as they are read */
    public static function takeNewCodes(): ?array
    {
        $codes = Yii::$app->session->get(self::SESSION_NEW_CODES);
        Yii::$app->session->remove(self::SESSION_NEW_CODES);
        return is_array($codes) ? $codes : null;
    }

    /** Removes every method and recovery code (an admin, or the console, for a user who lost theirs) */
    public static function reset(User $user): void
    {
        $db = Yii::$app->db;
        $db->createCommand()->delete('user_totp', ['user_id' => $user->id])->execute();
        $db->createCommand()->delete('user_passkey', ['user_id' => $user->id])->execute();
        $db->createCommand()->delete('user_recovery_code', ['user_id' => $user->id])->execute();
        AuditLog::record('user', $user->id, $user->getAuditLabel(), 'mfa_reset');
    }

    /** After a method is removed: with none left, the recovery codes go too */
    public static function afterMethodRemoved(User $user): void
    {
        if (!self::isEnabled($user)) {
            Yii::$app->db->createCommand()->delete('user_recovery_code', ['user_id' => $user->id])->execute();
        }
    }

    // ---- the sign-in that waits for its second step ---------------------------------

    /** The password was right: remember who is signing in until the second step */
    public static function startPending(User $user): void
    {
        $session = Yii::$app->session;
        $session->regenerateID(true);
        $session->set(self::SESSION_PENDING, ['id' => $user->id, 'at' => time(), 'key' => $user->auth_key]);
    }

    /** The user whose password was accepted in the last PENDING_TTL seconds, if any */
    public static function pendingUser(): ?User
    {
        $pending = Yii::$app->session->get(self::SESSION_PENDING);
        if (!is_array($pending) || time() - (int) ($pending['at'] ?? 0) > self::PENDING_TTL) {
            return null;
        }
        $user = User::findOne((int) $pending['id']);
        // A password change or reset since then (new auth key) or a disabled account ends it
        if ($user === null || !$user->isActive() || !hash_equals($user->auth_key, (string) ($pending['key'] ?? ''))) {
            return null;
        }
        return $user;
    }

    public static function clearPending(): void
    {
        Yii::$app->session->remove(self::SESSION_PENDING);
    }

    /**
     * Signs the user in once every step is done: records the success for the lockout, the
     * last sign-in and the audit entry with the method used (password, app, recovery code, passkey).
     */
    public static function completeLogin(User $user, string $method): void
    {
        $request = Yii::$app->request;
        $ip = $request->getUserIP();
        self::clearPending();
        (new LoginThrottle())->record($user->username, $ip, true, $request->getUserAgent());
        $user->updateAttributes(['last_login_at' => Actor::now(), 'last_login_ip' => $ip]);
        Yii::$app->user->login($user);
        Yii::$app->session->set(self::SESSION_CONFIRMED, time());
        AuditLog::record('user', $user->id, $user->getAuditLabel(), 'login', ['method' => [null, $method]]);
    }

    /** A failed second step counts towards the lockout like a wrong password */
    public static function recordFailure(User $user, string $what): void
    {
        $request = Yii::$app->request;
        (new LoginThrottle())->record($user->username, $request->getUserIP(), false, $request->getUserAgent());
        AuditLog::record('user', $user->id, $user->getAuditLabel(), 'login_failed', ['step' => [null, $what]], $user->id, $user->username);
    }

    /** Signed in or confirmed the password in the last CONFIRM_TTL seconds */
    public static function isRecentlyConfirmed(): bool
    {
        return time() - (int) Yii::$app->session->get(self::SESSION_CONFIRMED, 0) <= self::CONFIRM_TTL;
    }

    public static function markConfirmed(): void
    {
        Yii::$app->session->set(self::SESSION_CONFIRMED, time());
    }

    private static function randomCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < 16; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }
        return $code;
    }

    /** "abcd-efgh-jkmn-pqrs" */
    public static function formatCode(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    private static function hashCode(string $normalised): string
    {
        return hash('sha256', $normalised);
    }
}
