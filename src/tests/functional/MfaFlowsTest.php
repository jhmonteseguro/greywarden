<?php

namespace tests\functional;

use app\components\Mfa;
use app\components\Passkeys;
use app\components\Totp;
use app\models\AuditLog;
use app\models\Setting;
use app\models\User;
use app\models\UserPasskey;
use app\models\UserTotp;
use tests\FunctionalTestCase;
use tests\support\SoftAuthenticator;
use Yii;
use yii\db\Query;
use yii\helpers\Json;

/**
 * Two-step sign-in end to end: setting up an authenticator app, passkeys (with a software
 * authenticator, so the WebAuthn signatures are real), recovery codes, the second step of
 * signing in, passkey-only sign-in, the admin requirement and reset.
 */
class MfaFlowsTest extends FunctionalTestCase
{
    private function signIn(string $role): static
    {
        return $this->post('/login', ['LoginForm' => ['username' => "fn.$role", 'password' => self::PASSWORD]]);
    }

    /** Signed in with the password confirmed just now, as after a fresh sign-in */
    private function confirmedAs(string $role): User
    {
        $user = $this->loginAs($role);
        $_SESSION['mfa.confirmedAt'] = time();
        return $user;
    }

    /** The JSON an action answered, as the browser receives it */
    private function json(): array
    {
        $this->assertNotNull($this->response, "no response ({$this->status}: {$this->body})");
        return Json::decode(Json::encode($this->response->data));
    }

    /** Sets up the authenticator app for $role through the pages; returns the secret */
    private function setUpTotp(string $role): string
    {
        $user = $this->confirmedAs($role);
        $this->get('/account/totp')->assertOk();
        $this->assertSee('Set up an authenticator app');
        $this->assertSame('no-store', $this->response->headers->get('Cache-Control'));
        $secret = UserTotp::findOne(['user_id' => $user->id])->getPlainSecret();
        $this->assertSee(Totp::formatSecret($secret));
        $this->post('/account/totp', ['TotpSetupForm' => ['code' => Totp::code($secret, Totp::counterAt(time()) - 1)]])->assertRedirect('recovery-codes');
        return $secret;
    }

    /** Registers a software passkey for $role through the JSON endpoints */
    private function addPasskey(string $role, string $name = 'Test key', ?SoftAuthenticator $key = null): SoftAuthenticator
    {
        $this->confirmedAs($role);
        $key ??= new SoftAuthenticator();
        $this->post('/account/passkey-options');
        $options = $this->json();
        $this->assertSame('required', $options['publicKey']['authenticatorSelection']['residentKey']);
        $this->post('/account/passkey-add', ['name' => $name, 'credential' => $key->create($options)]);
        $this->assertArrayNotHasKey('error', $this->json(), (string) ($this->json()['error'] ?? ''));
        return $key;
    }

    private function lastAudit(string $action): ?AuditLog
    {
        return AuditLog::find()->where(['action' => $action])->orderBy(['id' => SORT_DESC])->one();
    }

    // ---- authenticator app -----------------------------------------------------------

    public function testSettingUpTheAppNeedsAConfirmedPasswordAndShowsCodesOnce(): void
    {
        $user = $this->loginAs('analyst');
        $this->get('/account/security')->assertOk();
        $this->assertSee('Two-step sign-in');
        $this->assertSee('Set up');

        // Not confirmed since signing in (loginAs skips the sign-in)
        $this->get('/account/totp')->assertRedirect('/account/confirm');
        $this->get('/account/confirm?return=/account/totp')->assertOk();
        $this->post('/account/confirm?return=/account/totp', ['ConfirmPasswordForm' => ['password' => 'wrong']])->assertOk();
        $this->assertSee('That password is not right.');
        $this->assertSame(['password confirmation'], array_column(Json::decode($this->lastAudit('login_failed')->changes), 1));
        $this->post('/account/confirm?return=//evil.example.com/', ['ConfirmPasswordForm' => ['password' => self::PASSWORD]])
            ->assertRedirect('/account/security');
        $this->post('/account/confirm?return=/account/totp', ['ConfirmPasswordForm' => ['password' => self::PASSWORD]])->assertRedirect('/account/totp');

        $this->get('/account/totp')->assertOk();
        $totp = UserTotp::findOne(['user_id' => $user->id]);
        $secret = $totp->getPlainSecret();
        $this->assertNotNull($secret);
        $this->assertStringNotContainsString($secret, $totp->secret, 'stored encrypted');
        $this->get('/account/totp')->assertOk();
        $this->assertSame($secret, UserTotp::findOne(['user_id' => $user->id])->getPlainSecret(), 'a reload shows the same secret');
        $this->assertSee('data:image/svg+xml;base64,');

        $this->post('/account/totp', ['TotpSetupForm' => ['code' => '000000']])->assertOk();
        $this->assertSee('That code is not right');
        $this->assertFalse(Mfa::hasTotp($user));

        $this->post('/account/totp', ['TotpSetupForm' => ['code' => Totp::code($secret, Totp::counterAt(time()))]])->assertRedirect('recovery-codes');
        $this->assertTrue(Mfa::hasTotp($user));
        $this->assertNotNull($this->lastAudit('mfa_totp_on'));
        $this->get('/account/recovery-codes')->assertOk();
        $this->assertSame('no-store', $this->response->headers->get('Cache-Control'));
        preg_match_all('/<li>([a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4})<\/li>/', $this->body, $m);
        $this->assertCount(10, $m[1]);
        $this->get('/account/recovery-codes')->assertRedirect('/account/security');
        $this->assertSame(10, Mfa::recoveryCodesLeft($user));

        // A second app is refused while one is set up
        $this->get('/account/totp')->assertRedirect('/account/security');
        $this->get('/account/security')->assertOk();
        $this->assertSee('10 of 10 left');
        $this->assertSee('Turn off');
    }

    public function testSignInAsksForTheSecondStepAndAcceptsEachCodeOnce(): void
    {
        $secret = $this->setUpTotp('analyst');
        $user = User::findOne($this->userIdFor('analyst'));
        $codes = Mfa::generateRecoveryCodes($user);
        $this->logout();

        $this->signIn('analyst')->assertRedirect('/site/verify');
        $this->assertTrue(Yii::$app->user->isGuest, 'not signed in yet');
        $this->get('/site/verify')->assertOk();
        $this->assertSee('Code from the app or a recovery code');
        $this->assertSee('fn.analyst');
        $this->get('/')->assertRedirect('login');

        $this->post('/site/verify', ['MfaCodeForm' => ['code' => '000000']])->assertOk();
        $this->assertSee('not right');
        $this->assertSame(['authenticator code'], array_column(Json::decode($this->lastAudit('login_failed')->changes), 1));

        // The step used during setup cannot be used again; the next one can
        $last = (int) UserTotp::findOne(['user_id' => $user->id])->last_counter;
        $this->post('/site/verify', ['MfaCodeForm' => ['code' => Totp::code($secret, $last)]])->assertOk();
        UserTotp::updateAll(['last_counter' => Totp::counterAt(time()) - 2], ['user_id' => $user->id]);
        $code = Totp::code($secret, Totp::counterAt(time()));
        $this->post('/site/verify', ['MfaCodeForm' => ['code' => $code]])->assertRedirect();
        $this->assertSame($user->id, (int) $_SESSION['__id']);
        $this->assertSame(['totp'], array_column(Json::decode($this->lastAudit('login')->changes), 1));

        // The same code a second time
        $this->logout();
        $this->signIn('analyst')->assertRedirect('/site/verify');
        $this->post('/site/verify', ['MfaCodeForm' => ['code' => $code]])->assertOk();
        $this->assertSee('already been used');

        // A recovery code, once, typed loosely
        $this->post('/site/verify', ['MfaCodeForm' => ['code' => strtoupper($codes[0])]])->assertRedirect();
        $this->assertStringContainsString('9 left', (string) $this->flash('warning'));
        $this->assertNotNull($this->lastAudit('recovery_code_used'));
        $this->logout();
        $this->signIn('analyst');
        $this->post('/site/verify', ['MfaCodeForm' => ['code' => Mfa::formatCode($codes[0])]])->assertOk();
        $this->assertSee('already been used');
        $this->post('/site/verify', ['MfaCodeForm' => ['code' => 'not-a-code']])->assertOk();
        $this->assertSame(['recovery code'], array_column(Json::decode($this->lastAudit('login_failed')->changes), 1));
        $this->post('/site/verify', ['MfaCodeForm' => ['code' => '']])->assertOk();
        $this->assertSee('Enter the code.');
    }

    public function testTheSecondStepLocksOutAndTimesOut(): void
    {
        $this->setUpTotp('viewer');
        $this->logout();
        $this->signIn('viewer')->assertRedirect('/site/verify');
        for ($i = 0; $i < (int) Yii::$app->params['auth.maxFailedAttempts']; $i++) {
            $this->post('/site/verify', ['MfaCodeForm' => ['code' => '000000']]);
        }
        $this->post('/site/verify', ['MfaCodeForm' => ['code' => '000000']])->assertOk();
        $this->assertSee('Too many failed sign-in attempts');
        $this->assertSee('Back to sign in');
        $this->assertNotNull($this->lastAudit('login_blocked'));
        $this->get('/site/verify')->assertRedirect('/login');
        (new \app\components\LoginThrottle())->clear('fn.viewer');

        // The password step is only good for a few minutes
        $this->signIn('viewer')->assertRedirect('/site/verify');
        $_SESSION['mfa.pending']['at'] = time() - Mfa::PENDING_TTL - 1;
        $this->get('/site/verify')->assertRedirect('/login');
        $this->assertStringContainsString('timed out', (string) $this->flash('warning'));

        // ... and ends when the password changes
        $this->signIn('viewer')->assertRedirect('/site/verify');
        $user = User::findOne($this->userIdFor('viewer'));
        $user->generateAuthKey();
        $user->save(false);
        $this->get('/site/verify')->assertRedirect('/login');
    }

    public function testTurningTheAppOff(): void
    {
        $this->setUpTotp('analyst');
        $user = User::findOne($this->userIdFor('analyst'));
        $_SESSION['mfa.confirmedAt'] = time() - Mfa::CONFIRM_TTL - 1;
        $this->post('/account/totp-disable')->assertRedirect('/account/confirm');
        $_SESSION['mfa.confirmedAt'] = time();
        $this->post('/account/totp-disable')->assertRedirect('/account/security');
        $this->assertFalse(Mfa::hasTotp($user));
        $this->assertSame(0, Mfa::recoveryCodesLeft($user), 'no method left, so no codes');
        $this->assertNotNull($this->lastAudit('mfa_totp_off'));
        $this->post('/account/totp-disable')->assertRedirect('/account/security');
        $this->post('/account/recovery-codes')->assertRedirect('/account/security');
        $this->assertSame(0, Mfa::recoveryCodesLeft($user), 'no codes without a method');
    }

    // ---- passkeys --------------------------------------------------------------------

    public function testPasskeysSignInAloneOrAsTheSecondStep(): void
    {
        $user = User::findOne($this->userIdFor('analyst'));
        $key = $this->addPasskey('analyst', 'Laptop');
        $this->assertSame(1, Mfa::passkeyCount($user));
        $this->assertSame('/account/recovery-codes', $this->json()['redirect'], 'first method: recovery codes');
        $this->assertSame(['Laptop'], array_column(Json::decode($this->lastAudit('passkey_add')->changes), 1));
        $passkey = UserPasskey::findOne(['user_id' => $user->id]);
        $this->assertSame(hash('sha256', $key->credentialId), $passkey->credential_hash);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $passkey->public_key);
        $this->assertSame('00000000-0000-0000-0000-000000000000', $passkey->aaguid);
        $this->assertSame(Passkeys::fromBase64Url((string) User::findOne($user->id)->passkey_handle), $key->userHandle);

        // The same authenticator again: excluded, and refused if it tries
        $this->post('/account/passkey-options');
        $this->assertCount(1, $this->json()['publicKey']['excludeCredentials']);
        $this->post('/account/passkey-add', ['credential' => $key->create($this->json())]);
        $this->assertSame('This passkey is already registered.', $this->json()['error']);

        // Sign in with the passkey alone
        $this->logout();
        $this->get('/login')->assertOk();
        $this->assertSee('Sign in with a passkey');
        $this->post('/site/passkey-options', ['mode' => 'passwordless']);
        $options = $this->json();
        $this->assertSame([], $options['publicKey']['allowCredentials'] ?? []);
        $this->assertSame('required', $options['publicKey']['userVerification']);
        $this->post('/site/passkey', ['mode' => 'passwordless', 'credential' => $key->get($options)]);
        $this->assertArrayHasKey('redirect', $this->json(), (string) ($this->json()['error'] ?? ''));
        $this->assertSame($user->id, (int) $_SESSION['__id']);
        $this->assertSame(['passkey'], array_column(Json::decode($this->lastAudit('login')->changes), 1));
        $this->assertSame($key->counter, (int) UserPasskey::findOne($passkey->id)->sign_count);
        $this->assertNotNull(UserPasskey::findOne($passkey->id)->last_used_at);

        // As the second step after the password
        $this->logout();
        $this->signIn('analyst')->assertRedirect('/site/verify');
        $this->get('/site/verify')->assertOk();
        $this->assertSee('Use a passkey');
        $this->assertSee('Recovery code');
        $this->post('/site/passkey-options', ['mode' => 'second-step']);
        $options = $this->json();
        $this->assertCount(1, $options['publicKey']['allowCredentials']);
        $this->post('/site/passkey', ['mode' => 'second-step', 'credential' => $key->get($options)]);
        $this->assertArrayHasKey('redirect', $this->json());
        $this->assertSame($user->id, (int) $_SESSION['__id']);
    }

    public function testPasskeyResponsesThatMustBeRefused(): void
    {
        $key = $this->addPasskey('analyst');
        $other = $this->addPasskey('viewer');
        $this->logout();
        $passwordless = function (SoftAuthenticator $authenticator, array $overrides = [], ?int $counter = null): ?string {
            $this->post('/site/passkey-options', ['mode' => 'passwordless']);
            $this->post('/site/passkey', ['mode' => 'passwordless', 'credential' => $authenticator->get($this->json(), $overrides, $counter)]);
            return $this->json()['error'] ?? null;
        };

        $this->assertStringContainsString('another site', (string) $passwordless($key, ['origin' => 'https://evil.example.com']));
        $this->assertStringContainsString('another site', (string) $passwordless($key, ['origin' => 'http://localhost.evil.example.com']));
        $this->assertStringContainsString('could not be checked', (string) $passwordless($key, ['type' => 'webauthn.create']));
        $this->assertStringContainsString('could not be checked', (string) $passwordless($key, ['challenge' => SoftAuthenticator::b64(random_bytes(32))]));
        $unknown = new SoftAuthenticator();
        $this->assertStringContainsString('not registered', (string) $passwordless($unknown));
        $unverified = clone $key;
        $unverified->userVerified = false;
        $this->assertStringContainsString('could not be checked', (string) $passwordless($unverified), 'passwordless needs user verification');
        $wrongHandle = clone $key;
        $wrongHandle->userHandle = random_bytes(32);
        $this->assertStringContainsString('another account', (string) $passwordless($wrongHandle));
        $this->assertTrue(Yii::$app->user->isGuest);

        // After a sign-in, a counter that goes backwards means a cloned key
        $this->assertNull($passwordless($key));
        $this->logout();
        $this->assertStringContainsString('could not be checked', (string) $passwordless($key, [], 0), 'counter went backwards');
        $this->assertTrue(Yii::$app->user->isGuest);
        $this->assertSame('(passkey)', AuditLog::find()->where(['action' => 'login_failed'])->orderBy(['id' => SORT_DESC])->one()->username);

        // No challenge asked for, or used twice
        $this->post('/site/passkey', ['mode' => 'passwordless', 'credential' => $key->get(['publicKey' => ['challenge' => 'x']])]);
        $this->assertStringContainsString('expired', $this->json()['error']);
        $this->post('/site/passkey', ['mode' => 'passwordless', 'credential' => ['id' => '']]);
        $this->assertStringContainsString('expired', $this->json()['error']);
        $this->post('/site/passkey-options', ['mode' => 'passwordless']);
        $this->post('/site/passkey', ['mode' => 'passwordless', 'credential' => ['id' => '']]);
        $this->assertStringContainsString('incomplete', $this->json()['error']);

        // Second step: only the user's own passkeys
        $this->signIn('analyst')->assertRedirect('/site/verify');
        $this->post('/site/passkey-options', ['mode' => 'second-step']);
        $this->post('/site/passkey', ['mode' => 'second-step', 'credential' => $other->get($this->json())]);
        $this->assertStringContainsString('not registered', $this->json()['error']);
        $this->assertSame(['passkey'], array_column(Json::decode($this->lastAudit('login_failed')->changes), 1));

        // A disabled account
        User::updateAll(['status' => User::STATUS_DISABLED], ['id' => $this->userIdFor('viewer')]);
        $this->assertStringContainsString('disabled', (string) $passwordless($other));
        User::updateAll(['status' => User::STATUS_ACTIVE], ['id' => $this->userIdFor('viewer')]);

        // Second step without a password step
        $this->logout();
        $this->post('/site/passkey-options', ['mode' => 'second-step']);
        $this->assertStringContainsString('timed out', $this->json()['error']);
        $this->post('/site/passkey', ['mode' => 'second-step']);
        $this->assertStringContainsString('timed out', $this->json()['error']);
    }

    public function testPasskeysNeedANameNotAnIpAddressAndAConfirmedPassword(): void
    {
        $this->loginAs('analyst');
        $this->post('/account/passkey-options');
        $this->assertStringContainsString('/account/confirm', $this->json()['redirect']);
        $this->post('/account/passkey-add');
        $this->assertStringContainsString('/account/confirm', $this->json()['redirect']);

        $this->get('/account/security')->assertOk();
        $request = Yii::$app->request;
        $request->setHostInfo('http://127.0.0.1:8080');
        $this->assertNull(Passkeys::rpId());
        $this->assertStringContainsString('http://localhost:8080', (string) Passkeys::unavailableReason());
        $request->setHostInfo('http://[::1]');
        $this->assertNull(Passkeys::rpId());
        $request->setHostInfo('http://greywarden.example.com');
        $this->assertSame('greywarden.example.com', Passkeys::rpId());
        $this->assertStringContainsString('HTTPS', (string) Passkeys::unavailableReason());
        Yii::$app->params['webauthn.rpId'] = 'example.com';
        $this->assertSame('example.com', Passkeys::rpId(), 'a parent domain');
        Yii::$app->params['webauthn.rpId'] = 'other.example.org';
        $this->assertNull(Passkeys::rpId());
        $this->assertStringContainsString('other.example.org', (string) Passkeys::unavailableReason());
        $request->setHostInfo('http://localhost');
        Yii::$app->params['webauthn.rpId'] = 'localhost';
        $this->assertNull(Passkeys::unavailableReason());
        $this->assertSame('', Passkeys::fromBase64Url('***'));
    }

    public function testAKeyWithAShortCoordinateRegisters(): void
    {
        // OpenSSL drops a coordinate's leading zero bytes (about one key in 128); COSE needs all 32
        $soft = new SoftAuthenticator(key: SoftAuthenticator::shortCoordinateKey());
        $this->addPasskey('analyst', 'Short key', $soft);
        $this->assertTrue(UserPasskey::find()->where(['user_id' => $this->userIdFor('analyst'), 'name' => 'Short key'])->exists());
        $this->assertSame(str_repeat("\0", 32), SoftAuthenticator::coordinate(''));
        $this->assertSame(str_repeat("\0", 31) . 'x', SoftAuthenticator::coordinate('x'));
    }

    public function testRemovingPasskeys(): void
    {
        $this->addPasskey('analyst', 'Old key');
        $user = User::findOne($this->userIdFor('analyst'));
        $this->addPasskey('analyst', 'New key');
        $this->assertSame('/account/security', $this->json()['redirect'], 'codes exist already');
        $this->get('/account/security')->assertOk();
        $this->assertSee('Old key');
        $this->assertSee('New key');

        $old = UserPasskey::findOne(['user_id' => $user->id, 'name' => 'Old key']);
        $theirs = $this->addPasskey('viewer');
        $this->confirmedAs('analyst');
        $viewerKey = UserPasskey::findOne(['user_id' => $this->userIdFor('viewer')]);
        $this->post('/account/passkey-remove?id=' . $viewerKey->id);
        $this->assertSame(404, $this->status, 'only your own');
        $this->assertNotNull($theirs);

        $_SESSION['mfa.confirmedAt'] = 0;
        $this->post('/account/passkey-remove?id=' . $old->id)->assertRedirect('/account/confirm');
        $_SESSION['mfa.confirmedAt'] = time();
        $this->post('/account/passkey-remove?id=' . $old->id)->assertRedirect('/account/security');
        $this->assertSame(1, Mfa::passkeyCount($user));
        $this->assertSame(['Old key'], array_column(Json::decode($this->lastAudit('passkey_remove')->changes), 0));
        $this->assertGreaterThan(0, Mfa::recoveryCodesLeft($user), 'a method is left');

        // New recovery codes
        $before = (new Query())->from('user_recovery_code')->where(['user_id' => $user->id])->column();
        $this->post('/account/recovery-codes')->assertRedirect('/account/recovery-codes');
        $this->get('/account/recovery-codes')->assertOk();
        $this->assertSee('Save these recovery codes now');
        $this->assertNotEquals($before, (new Query())->from('user_recovery_code')->where(['user_id' => $user->id])->column());
    }

    public function testErrorBranches(): void
    {
        // Passkeys switched off for this address: the JSON endpoints explain instead
        $this->params['webauthn.rpId'] = 'other.example.org';
        $this->confirmedAs('analyst');
        $this->post('/account/passkey-options');
        $this->assertStringContainsString('other.example.org', $this->json()['error']);
        $this->logout();
        $this->post('/site/passkey-options', ['mode' => 'passwordless']);
        $this->assertStringContainsString('other.example.org', $this->json()['error']);
        $this->get('/login')->assertOk();
        $this->assertNotSee('Sign in with a passkey');
        $this->params = [];

        // A registration the library refuses (signed for another site)
        $this->confirmedAs('analyst');
        $this->post('/account/passkey-options');
        $wrongSite = new SoftAuthenticator('http://localhost', 'evil.example.com');
        $this->post('/account/passkey-add', ['credential' => $wrongSite->create($this->json())]);
        $this->assertStringContainsString('could not be checked', $this->json()['error']);

        // Locked out: passkeys are refused before and after the passkey is identified
        $key = $this->addPasskey('analyst');
        $this->logout();
        $throttle = new \app\components\LoginThrottle();
        for ($i = 0; $i < (int) Yii::$app->params['auth.maxFailedAttempts']; $i++) {
            $throttle->record('fn.analyst', '10.9.9.9', false, 'test');
        }
        $this->post('/site/passkey-options', ['mode' => 'passwordless']);
        $this->post('/site/passkey', ['mode' => 'passwordless', 'credential' => $key->get($this->json())]);
        $this->assertStringContainsString('Too many', $this->json()['error'], 'the account is locked');
        for ($i = 0; $i < (int) Yii::$app->params['auth.ipMaxFailedAttempts']; $i++) {
            $throttle->record('someone-else', '127.0.0.1', false, 'test');
        }
        $this->post('/site/passkey', ['mode' => 'passwordless', 'credential' => []]);
        $this->assertStringContainsString('Too many', $this->json()['error'], 'the address is locked');

        // Password confirmation: empty, and locked
        $this->confirmedAs('viewer');
        $this->post('/account/confirm', ['ConfirmPasswordForm' => ['password' => '']])->assertOk();
        $this->assertSee('cannot be blank');
        $this->post('/account/confirm', ['ConfirmPasswordForm' => ['password' => self::PASSWORD]])->assertOk();
        $this->assertSee('Too many failed attempts');

        // App setup: empty code; making codes without a fresh password
        $throttle->clear('someone-else');
        \Yii::$app->db->createCommand()->delete('login_attempt')->execute();
        $this->confirmedAs('viewer');
        $this->get('/account/totp')->assertOk();
        $this->post('/account/totp', ['TotpSetupForm' => ['code' => '']])->assertOk();
        $this->assertSee('Enter the 6-digit code');
        $_SESSION['mfa.confirmedAt'] = 0;
        $this->post('/account/recovery-codes')->assertRedirect('/account/confirm');

        $this->assertNull(Passkeys::formatAaguid(null));
        $this->assertNull(Passkeys::formatAaguid('short'));
        $this->assertSame('01020304-0506-0708-090a-0b0c0d0e0f10', Passkeys::formatAaguid(implode('', array_map('chr', range(1, 16)))));
    }

    // ---- requiring it ----------------------------------------------------------------

    public function testAdminsCanRequireTwoStepSignIn(): void
    {
        $this->loginAs('admin');
        $this->get('/admin/settings')->assertOk();
        $this->assertSee('Sign-in security');
        $this->post('/admin/settings', ['SecurityForm' => ['mfa_required' => 'everyone-please']])->assertOk();
        $this->assertSame(Mfa::REQUIRED_OFF, Mfa::policy());
        $this->post('/admin/settings', ['SecurityForm' => ['mfa_required' => Mfa::REQUIRED_ADMINS]])->assertRedirect('security');
        $this->assertSame(Mfa::REQUIRED_ADMINS, Mfa::policy());
        $this->assertSame(['Required for administrators'], array_column(Json::decode($this->lastAudit('update')->changes), 1));
        $this->post('/admin/settings', ['SecurityForm' => ['mfa_required' => Mfa::REQUIRED_ADMINS]])->assertRedirect();

        // The admin has no method yet: every page sends them to set one up, except those needed to do it
        $this->get('/risks')->assertRedirect('/account/security');
        $this->assertStringContainsString('requires two-step sign-in', (string) $this->flash('warning'));
        $this->get('/account/security')->assertOk();
        $this->assertSee('Your administrator requires it.');
        $this->get('/account/password')->assertOk();

        // Analysts are not affected
        $this->loginAs('analyst');
        $this->get('/risks')->assertOk();

        // Everyone
        Setting::put([Mfa::SETTING_REQUIRED => Mfa::REQUIRED_ALL]);
        Setting::reset();
        $this->get('/risks')->assertRedirect('/account/security');
        $this->addPasskey('analyst');
        $this->get('/risks')->assertOk();

        // The last method cannot be removed while it is required
        $this->confirmedAs('analyst');
        $only = UserPasskey::findOne(['user_id' => $this->userIdFor('analyst')]);
        $this->post('/account/passkey-remove?id=' . $only->id)->assertRedirect('/account/security');
        $this->assertStringContainsString('required', (string) $this->flash('danger'));
        $this->assertNotNull(UserPasskey::findOne($only->id));
        $secret = $this->setUpTotp('analyst');
        $this->assertNotEmpty($secret);
        $this->post('/account/passkey-remove?id=' . $only->id)->assertRedirect('/account/security');
        $this->assertNull(UserPasskey::findOne($only->id), 'the app is still there');
        $this->post('/account/totp-disable')->assertRedirect('/account/security');
        $this->assertStringContainsString('Add a passkey before', (string) $this->flash('danger'));
        $this->assertTrue(Mfa::hasTotp(User::findOne($this->userIdFor('analyst'))));

        // A forced password change still comes first
        User::updateAll(['must_change_password' => 1], ['id' => $this->userIdFor('viewer')]);
        $this->loginAs('viewer');
        $this->get('/risks')->assertRedirect('/account/password');
        User::updateAll(['must_change_password' => 0], ['id' => $this->userIdFor('viewer')]);
        Setting::put([Mfa::SETTING_REQUIRED => '']);
        Setting::reset();
    }

    public function testAdminResetAndUserList(): void
    {
        $this->setUpTotp('analyst');
        $this->addPasskey('analyst');
        $analyst = User::findOne($this->userIdFor('analyst'));
        $this->assertSame('App + 1 passkey', Mfa::summary($analyst));

        $this->loginAs('admin');
        $this->get('/admin/user')->assertOk();
        $this->assertSee('App + 1 passkey');
        $this->get('/admin/user/' . $analyst->id)->assertOk();
        $this->assertSee('Reset two-step sign-in');
        $this->assertSee('recovery codes left');

        $this->loginAs('analyst');
        $this->post('/admin/user/' . $analyst->id . '/reset-mfa');
        $this->assertSame(403, $this->status, 'admins only');

        $this->loginAs('admin');
        $this->post('/admin/user/' . $analyst->id . '/reset-mfa')->assertRedirect('/admin/user/' . $analyst->id);
        $this->assertFalse(Mfa::isEnabled($analyst));
        $this->assertSame(0, Mfa::recoveryCodesLeft($analyst));
        $this->assertNotNull($this->lastAudit('mfa_reset'));
        $this->get('/admin/user/' . $analyst->id)->assertOk();
        $this->assertNotSee('Reset two-step sign-in');
    }

    public function testAccountMenuLinksToTheSecurityPage(): void
    {
        $this->loginAs('viewer');
        $this->get('/')->assertOk();
        $this->assertSee('Sign-in security');
        $this->get('/account/recovery-codes')->assertRedirect('/account/security');
        $this->logout();
        $this->signIn('viewer')->assertRedirect();
        $this->assertSame(['password'], array_column(Json::decode($this->lastAudit('login')->changes), 1), 'without a method the password is enough');
    }
}
