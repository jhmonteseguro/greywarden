<?php

namespace tests\unit;

use app\components\Mfa;
use app\components\Rbac;
use app\models\AuditLog;
use app\models\Setting;
use app\models\User;
use app\models\UserPasskey;
use app\models\UserTotp;
use app\components\Totp;
use tests\DbTestCase;
use tests\support\OutputCapture;
use yii\console\ExitCode;
use yii\db\Query;

class MfaTest extends DbTestCase
{
    private function user(string $role = Rbac::ROLE_ANALYST): User
    {
        return User::createUser('mfa.' . bin2hex(random_bytes(3)), bin2hex(random_bytes(3)) . '@example.com', 'MFA Test', 'Mfa!Test-Password1', $role, false);
    }

    private function giveTotp(User $user): void
    {
        $totp = new UserTotp(['user_id' => $user->id, 'created_at' => '2026-11-09 10:00:00', 'confirmed_at' => '2026-11-09 10:00:00']);
        $totp->setPlainSecret(Totp::generateSecret());
        $totp->save(false);
    }

    private function givePasskey(User $user): void
    {
        (new UserPasskey(['user_id' => $user->id, 'name' => 'Key', 'credential_hash' => hash('sha256', random_bytes(8)), 'credential_id' => 'x',
            'public_key' => 'pem', 'created_at' => '2026-11-09 10:00:00']))->save(false);
    }

    protected function tearDown(): void
    {
        Setting::reset();
        parent::tearDown();
    }

    public function testPolicy(): void
    {
        $admin = $this->user(Rbac::ROLE_ADMIN);
        $analyst = $this->user();
        $this->assertSame(Mfa::REQUIRED_OFF, Mfa::policy());
        $this->assertFalse(Mfa::isRequiredFor($admin));
        Setting::put([Mfa::SETTING_REQUIRED => Mfa::REQUIRED_ADMINS]);
        Setting::reset();
        $this->assertTrue(Mfa::isRequiredFor($admin));
        $this->assertFalse(Mfa::isRequiredFor($analyst));
        Setting::put([Mfa::SETTING_REQUIRED => Mfa::REQUIRED_ALL]);
        Setting::reset();
        $this->assertTrue(Mfa::isRequiredFor($analyst));
        Setting::put([Mfa::SETTING_REQUIRED => 'bogus']);
        Setting::reset();
        $this->assertSame(Mfa::REQUIRED_OFF, Mfa::policy(), 'an unknown value means optional');
    }

    public function testMethodsAndSummary(): void
    {
        $user = $this->user();
        $this->assertFalse(Mfa::isEnabled($user));
        $this->assertSame('Off', Mfa::summary($user));
        (new UserTotp(['user_id' => $user->id, 'secret' => 'sb1:x', 'created_at' => '2026-11-09 10:00:00']))->save(false);
        $this->assertFalse(Mfa::hasTotp($user), 'an app being set up does not count');
        UserTotp::updateAll(['confirmed_at' => '2026-11-09 10:01:00'], ['user_id' => $user->id]);
        $this->assertSame('App', Mfa::summary($user));
        $this->givePasskey($user);
        $this->givePasskey($user);
        $this->assertSame('App + 2 passkeys', Mfa::summary($user));
        $this->assertTrue(Mfa::isEnabled($user));
    }

    public function testRecoveryCodes(): void
    {
        $user = $this->user();
        $codes = Mfa::generateRecoveryCodes($user);
        $this->assertCount(Mfa::RECOVERY_CODES, $codes);
        $this->assertCount(Mfa::RECOVERY_CODES, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{16}$/', $code);
        }
        $stored = (new Query())->select('code_hash')->from('user_recovery_code')->where(['user_id' => $user->id])->column();
        $this->assertNotContains($codes[0], $stored, 'only hashes are stored');
        $this->assertSame('abcd-efgh-jkmn-pqrs', Mfa::formatCode('abcdefghjkmnpqrs'));

        $this->assertFalse(Mfa::useRecoveryCode($user, 'short'));
        $this->assertFalse(Mfa::useRecoveryCode($user, 'aaaa-aaaa-aaaa-aaaa'));
        $this->assertTrue(Mfa::useRecoveryCode($user, Mfa::formatCode(strtoupper($codes[3]))));
        $this->assertFalse(Mfa::useRecoveryCode($user, $codes[3]), 'once only');
        $this->assertSame(9, Mfa::recoveryCodesLeft($user));
        $this->assertSame((string) $user->id, (string) AuditLog::find()->where(['action' => 'recovery_code_used'])->orderBy(['id' => SORT_DESC])->one()->entity_id);

        $other = $this->user();
        $this->assertFalse(Mfa::useRecoveryCode($other, $codes[4]), "another user's code");

        $new = Mfa::generateRecoveryCodes($user);
        $this->assertFalse(Mfa::useRecoveryCode($user, $codes[4]), 'old codes stop working');
        $this->assertTrue(Mfa::useRecoveryCode($user, $new[0]));
    }

    public function testResetAndRemovingTheLastMethod(): void
    {
        $user = $this->user();
        $this->giveTotp($user);
        $this->givePasskey($user);
        Mfa::generateRecoveryCodes($user);
        Mfa::afterMethodRemoved($user);
        $this->assertSame(10, Mfa::recoveryCodesLeft($user), 'methods left: codes stay');
        UserPasskey::deleteAll(['user_id' => $user->id]);
        UserTotp::deleteAll(['user_id' => $user->id]);
        Mfa::afterMethodRemoved($user);
        $this->assertSame(0, Mfa::recoveryCodesLeft($user));

        $this->giveTotp($user);
        $this->givePasskey($user);
        Mfa::generateRecoveryCodes($user);
        Mfa::reset($user);
        $this->assertSame('Off', Mfa::summary($user));
        $this->assertSame(0, Mfa::recoveryCodesLeft($user));
        $this->assertNotNull(AuditLog::find()->where(['action' => 'mfa_reset', 'entity_id' => $user->id])->one());
    }

    public function testConsoleReset(): void
    {
        $user = $this->user(Rbac::ROLE_ADMIN);
        $this->giveTotp($user);
        \Yii::$app->db->createCommand()->insert('login_attempt', ['username' => $user->username, 'ip' => '192.0.2.1', 'success' => 0, 'attempted_at' => date('Y-m-d H:i:s')])->execute();
        [$code, $out] = OutputCapture::run('user/reset-mfa', [$user->username]);
        $this->assertSame(ExitCode::OK, $code, $out);
        $this->assertStringContainsString('lockout cleared', $out);
        $this->assertSame('Off', Mfa::summary($user));
        $this->assertFalse((new Query())->from('login_attempt')->where(['username' => $user->username, 'success' => 0])->exists());

        Setting::put([Mfa::SETTING_REQUIRED => Mfa::REQUIRED_ALL]);
        Setting::reset();
        [, $out] = OutputCapture::run('user/reset-mfa', [$user->username]);
        $this->assertStringContainsString('must set up a new method', $out);

        [$code, $out] = OutputCapture::run('user/reset-mfa', ['nobody-here']);
        $this->assertSame(ExitCode::DATAERR, $code);
        $this->assertStringContainsString('not found', $out);

        [, $out] = OutputCapture::run('user/list');
        $this->assertStringContainsString('MFA: Off', $out);
    }
}
