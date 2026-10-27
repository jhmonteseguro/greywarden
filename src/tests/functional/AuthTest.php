<?php

namespace tests\functional;

use app\models\AuditLog;
use app\models\User;
use tests\FunctionalTestCase;
use yii\db\Query;

/**
 * Sign-in, lockout, sign-out, the forced password change and self-service
 * account pages.
 */
class AuthTest extends FunctionalTestCase
{
    private function signIn(string $username, string $password): void
    {
        $this->post('/login', ['LoginForm' => ['username' => $username, 'password' => $password]]);
    }

    private function audit(string $action, ?int $userId = null): array
    {
        return AuditLog::find()->where(['entity_type' => 'user', 'action' => $action] + ($userId ? ['entity_id' => $userId] : []))->all();
    }

    public function testSignInWithUsernameOrEmail(): void
    {
        $this->get('/login');
        $this->assertOk();

        $this->signIn('fn.analyst', self::PASSWORD);
        $this->assertRedirect('/');
        $id = $this->userIdFor('analyst');
        $this->assertSame($id, $_SESSION['__id']);
        $user = User::findOne($id);
        $this->assertSame('127.0.0.1', $user->last_login_ip);
        $this->assertNotEmpty($this->audit('login', $id));
        $this->assertTrue((new Query())->from('login_attempt')->where(['username' => 'fn.analyst', 'success' => 1])->exists());

        // Signed in: the dashboard renders, /login goes home
        $this->get('/');
        $this->assertOk();
        $this->get('/login');
        $this->assertRedirect();

        $this->logout();
        $this->signIn('FN.ANALYST@example.com', self::PASSWORD);
        $this->assertRedirect();
    }

    public function testWrongPasswordIsRejectedAndAudited(): void
    {
        $this->signIn('fn.viewer', 'wrong-password');
        $this->assertOk();
        $this->assertSee('Incorrect username or password');
        $this->assertArrayNotHasKey('__id', $_SESSION);
        $this->assertNotEmpty($this->audit('login_failed', $this->userIdFor('viewer')));

        // Unknown users get the same message (no account enumeration)
        $this->signIn('nobody-here', 'wrong-password');
        $this->assertSee('Incorrect username or password');
        $this->signIn('', '');
        $this->assertSee('cannot be blank');
    }

    public function testLockoutAfterRepeatedFailures(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->signIn('fn.viewer', "wrong-$i");
        }
        $this->signIn('fn.viewer', self::PASSWORD);
        $this->assertOk();
        $this->assertSee('Too many failed sign-in attempts');
        $this->assertNotEmpty($this->audit('login_blocked'));

        // An admin can clear the lockout
        $this->loginAs('admin');
        $this->get('/admin/user/' . $this->userIdFor('viewer'));
        $this->assertSee('Locked');
        $this->post('/admin/user/' . $this->userIdFor('viewer') . '/unlock');
        $this->assertRedirect();
        $this->logout();
        $this->signIn('fn.viewer', self::PASSWORD);
        $this->assertRedirect();
    }

    public function testDisabledAccountCannotSignInAndIsSignedOut(): void
    {
        $this->loginAs('viewer');
        $this->get('/risks');
        $this->assertOk();

        User::findOne($this->userIdFor('viewer'))->updateAttributes(['status' => User::STATUS_DISABLED]);
        $this->get('/risks');
        $this->assertRedirect('/login');

        $this->signIn('fn.viewer', self::PASSWORD);
        $this->assertSee('Incorrect username or password, or the account is disabled');
    }

    public function testRotatedAuthKeyEndsExistingSessions(): void
    {
        $this->loginAs('analyst');
        $user = User::findOne($this->userIdFor('analyst'));
        $user->generateAuthKey();
        $user->save(false);
        $this->get('/');
        $this->assertRedirect('/login');
    }

    public function testSignOut(): void
    {
        $this->loginAs('analyst');
        $this->post('/logout');
        $this->assertRedirect('/login');
        $this->assertNotEmpty($this->audit('logout', $this->userIdFor('analyst')));
        $this->get('/');
        $this->assertRedirect('/login');

        // Guests cannot "sign out"
        $this->post('/logout');
        $this->assertRedirect();
    }

    public function testForcedPasswordChange(): void
    {
        $user = User::findOne($this->userIdFor('analyst'));
        $user->updateAttributes(['must_change_password' => 1]);
        $this->loginAs('analyst');

        $this->get('/risks');
        $this->assertRedirect('/account/password');
        $this->assertStringContainsString('must set a new password', (string) $this->flash('warning'));

        $this->get('/account/password');
        $this->assertOk();

        // Wrong current password, policy failure, mismatch, re-use
        $this->post('/account/password', ['PasswordForm' => ['current_password' => 'nope', 'new_password' => 'New!Password-123', 'new_password_repeat' => 'New!Password-123']]);
        $this->assertSee('Current password is incorrect');
        $this->post('/account/password', ['PasswordForm' => ['current_password' => self::PASSWORD, 'new_password' => 'short', 'new_password_repeat' => 'short']]);
        $this->assertSee('at least 10 characters');
        $this->post('/account/password', ['PasswordForm' => ['current_password' => self::PASSWORD, 'new_password' => 'New!Password-123', 'new_password_repeat' => 'Other!Password-123']]);
        $this->assertSee('Passwords do not match');
        $this->post('/account/password', ['PasswordForm' => ['current_password' => self::PASSWORD, 'new_password' => self::PASSWORD, 'new_password_repeat' => self::PASSWORD]]);
        $this->assertSee('must differ from the current one');

        $this->post('/account/password', ['PasswordForm' => ['current_password' => self::PASSWORD, 'new_password' => 'New!Password-123', 'new_password_repeat' => 'New!Password-123']]);
        $this->assertRedirect('/');
        $user->refresh();
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertTrue($user->validatePassword('New!Password-123'));
        $this->assertNotEmpty($this->audit('password_change', $user->id));

        $this->get('/risks');
        $this->assertOk();
    }

    public function testProfileUpdate(): void
    {
        $this->loginAs('viewer');
        $this->get('/account/profile');
        $this->assertOk();
        $this->assertSee('fn.viewer@example.com');

        $this->post('/account/profile', ['ProfileForm' => ['full_name' => '', 'email' => 'fn.admin@example.com', 'job_title' => '']]);
        $this->assertOk();
        $this->assertSee('cannot be blank');
        $this->assertSee('has already been taken');

        $this->post('/account/profile', ['ProfileForm' => ['full_name' => ' Renamed Viewer ', 'email' => 'renamed@example.com', 'job_title' => 'Auditor']]);
        $this->assertRedirect();
        $user = User::findOne($this->userIdFor('viewer'));
        $this->assertSame('Renamed Viewer', $user->full_name);
        $this->assertSame('Auditor', $user->job_title);
        $this->assertSame('Profile updated.', $this->flash('success'));
    }

    public function testErrorPageRendersForSignedInAndGuestUsers(): void
    {
        // Without an exception the error action renders "not found" in the auth / main layout
        $this->get('/site/error');
        $this->assertStatus(404);
        $this->assertSee('</html>');
        $this->assertNotSee('gw-app');
        $this->loginAs('viewer');
        $this->get('/site/error');
        $this->assertStatus(404);
        $this->assertSee('gw-app');
    }
}
