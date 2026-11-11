<?php

namespace app\controllers;

use app\components\Actor;
use app\components\Mfa;
use app\components\Passkeys;
use app\components\Totp;
use app\models\AuditLog;
use app\models\ConfirmPasswordForm;
use app\models\PasswordForm;
use app\models\ProfileForm;
use app\models\TotpSetupForm;
use app\models\User;
use app\models\UserPasskey;
use app\models\UserTotp;
use app\widgets\AuditHistory;
use Yii;
use yii\base\UserException;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\Url;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Self-service: own profile, password and sign-in security (authenticator app, passkeys,
 * recovery codes). Changing sign-in methods needs the password confirmed in the last
 * Mfa::CONFIRM_TTL seconds (a fresh sign-in counts).
 */
class AccountController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@']]],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'totp-disable' => ['post'], 'passkey-options' => ['post'], 'passkey-add' => ['post'],
                    'passkey-remove' => ['post'], 'recovery-codes' => ['get', 'post'],
                ],
            ],
        ];
    }

    public function actionProfile(): Response|string
    {
        $model = new ProfileForm(Yii::$app->user->identity);
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Profile updated.');
            return $this->refresh();
        }
        return $this->render('profile', ['model' => $model]);
    }

    public function actionPassword(): Response|string
    {
        $user = Yii::$app->user->identity;
        $model = new PasswordForm($user);
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Your password has been changed.');
            return $this->redirect(['/dashboard/default/index']);
        }
        $model->current_password = $model->new_password = $model->new_password_repeat = null;
        return $this->render('password', ['model' => $model, 'forced' => (bool) $user->must_change_password]);
    }

    // ---- sign-in security ------------------------------------------------------------

    public function actionSecurity(): string
    {
        $user = $this->identity();
        return $this->render('security', [
            'user' => $user,
            'hasTotp' => Mfa::hasTotp($user),
            'passkeys' => UserPasskey::forUser($user->id),
            'codesLeft' => Mfa::recoveryCodesLeft($user),
            'required' => Mfa::isRequiredFor($user),
            'passkeyProblem' => Passkeys::unavailableReason(),
            'confirmed' => Mfa::isRecentlyConfirmed(),
        ]);
    }

    /** Asks for the password before sign-in methods change */
    public function actionConfirm(?string $return = null): Response|string
    {
        $model = new ConfirmPasswordForm($this->identity());
        $target = $this->safeReturn($return);
        if ($model->load(Yii::$app->request->post()) && $model->confirm()) {
            return $this->redirect($target);
        }
        $model->password = '';
        return $this->render('confirm', ['model' => $model, 'return' => $target]);
    }

    /** Sets up an authenticator app: shows the secret as a QR code and checks the first code */
    public function actionTotp(): Response|string
    {
        if (($redirect = $this->requireConfirmed()) !== null) {
            return $redirect;
        }
        $user = $this->identity();
        if (Mfa::hasTotp($user)) {
            Yii::$app->session->setFlash('info', 'An authenticator app is already set up. Turn it off first to move it to another app.');
            return $this->redirect(['security']);
        }
        $totp = UserTotp::findOne(['user_id' => $user->id]);
        if ($totp === null || $totp->getPlainSecret() === null) {
            $totp ??= new UserTotp(['user_id' => $user->id]);
            $totp->setPlainSecret(Totp::generateSecret());
            $totp->confirmed_at = null;
            $totp->last_counter = null;
            $totp->created_at = Actor::now();
            $totp->save(false);
        }
        $model = new TotpSetupForm($totp);
        if ($model->load(Yii::$app->request->post()) && $model->confirm()) {
            AuditLog::record('user', $user->id, $user->getAuditLabel(), 'mfa_totp_on');
            Mfa::ensureRecoveryCodes($user);
            Yii::$app->session->setFlash('success', 'Authenticator app set up. You will be asked for a code each time you sign in.');
            return $this->redirect(['recovery-codes']);
        }
        $secret = (string) $totp->getPlainSecret();
        $model->code = '';
        Yii::$app->response->headers->set('Cache-Control', 'no-store');
        return $this->render('totp', [
            'model' => $model,
            'secret' => $secret,
            'uri' => Totp::uri($secret, $user->username, Yii::$app->name),
        ]);
    }

    public function actionTotpDisable(): Response
    {
        if (($redirect = $this->requireConfirmed()) !== null) {
            return $redirect;
        }
        $user = $this->identity();
        if (!Mfa::hasTotp($user)) {
            return $this->redirect(['security']);
        }
        if (Mfa::isRequiredFor($user) && Mfa::passkeyCount($user) === 0) {
            Yii::$app->session->setFlash('danger', 'Two-step sign-in is required. Add a passkey before turning the authenticator app off.');
            return $this->redirect(['security']);
        }
        UserTotp::deleteAll(['user_id' => $user->id]);
        AuditLog::record('user', $user->id, $user->getAuditLabel(), 'mfa_totp_off');
        Mfa::afterMethodRemoved($user);
        Yii::$app->session->setFlash('success', 'Authenticator app turned off.');
        return $this->redirect(['security']);
    }

    /** JSON options for navigator.credentials.create() */
    public function actionPasskeyOptions(): Response
    {
        if (!Mfa::isRecentlyConfirmed()) {
            return $this->asJson(['error' => 'Confirm your password first.', 'redirect' => Url::to(['confirm', 'return' => Url::to(['security'])])]);
        }
        try {
            return $this->asJson(Passkeys::registrationOptions($this->identity()));
        } catch (UserException $e) {
            return $this->asJson(['error' => $e->getMessage()]);
        }
    }

    /** Saves the passkey the browser created */
    public function actionPasskeyAdd(): Response
    {
        if (!Mfa::isRecentlyConfirmed()) {
            return $this->asJson(['error' => 'Confirm your password first.', 'redirect' => Url::to(['confirm', 'return' => Url::to(['security'])])]);
        }
        $user = $this->identity();
        $request = Yii::$app->request;
        try {
            $passkey = Passkeys::register($user, (string) $request->post('name', ''), (array) $request->post('credential', []));
        } catch (UserException $e) {
            return $this->asJson(['error' => $e->getMessage()]);
        }
        AuditLog::record('user', $user->id, $user->getAuditLabel(), 'passkey_add', ['passkey' => [null, $passkey->name]]);
        $hadCodes = Mfa::recoveryCodesLeft($user) > 0;
        Mfa::ensureRecoveryCodes($user);
        Yii::$app->session->setFlash('success', sprintf('Passkey "%s" added. You can sign in with it, with or without your password.', $passkey->name));
        return $this->asJson(['redirect' => Url::to([$hadCodes ? 'security' : 'recovery-codes'])]);
    }

    public function actionPasskeyRemove(int $id): Response
    {
        if (($redirect = $this->requireConfirmed()) !== null) {
            return $redirect;
        }
        $user = $this->identity();
        $passkey = UserPasskey::findOne(['id' => $id, 'user_id' => $user->id]);
        if ($passkey === null) {
            throw new NotFoundHttpException('Passkey not found.');
        }
        if (Mfa::isRequiredFor($user) && !Mfa::hasTotp($user) && Mfa::passkeyCount($user) === 1) {
            Yii::$app->session->setFlash('danger', 'Two-step sign-in is required. Set up another passkey or an authenticator app before removing your last passkey.');
            return $this->redirect(['security']);
        }
        $passkey->delete();
        AuditLog::record('user', $user->id, $user->getAuditLabel(), 'passkey_remove', ['passkey' => [$passkey->name, null]]);
        Mfa::afterMethodRemoved($user);
        Yii::$app->session->setFlash('success', sprintf('Passkey "%s" removed.', $passkey->name));
        return $this->redirect(['security']);
    }

    /** GET shows codes made by the previous step, once; POST replaces all codes with new ones */
    public function actionRecoveryCodes(): Response|string
    {
        $user = $this->identity();
        if (Yii::$app->request->isPost) {
            if (($redirect = $this->requireConfirmed()) !== null) {
                return $redirect;
            }
            if (!Mfa::isEnabled($user)) {
                return $this->redirect(['security']);
            }
            Mfa::keepNewCodes(Mfa::generateRecoveryCodes($user));
            return $this->redirect(['recovery-codes']);
        }
        $codes = Mfa::takeNewCodes();
        if ($codes === null) {
            return $this->redirect(['security']);
        }
        Yii::$app->response->headers->set('Cache-Control', 'no-store');
        return $this->render('recovery-codes', ['codes' => $codes]);
    }

    private function identity(): User
    {
        /** @var User */
        return Yii::$app->user->identity;
    }

    /** A redirect to the password confirmation when it is not recent, coming back to the security page */
    private function requireConfirmed(): ?Response
    {
        if (Mfa::isRecentlyConfirmed()) {
            return null;
        }
        $return = Yii::$app->request->isGet ? Yii::$app->request->getUrl() : Url::to(['security']);
        return $this->redirect(['confirm', 'return' => $return]);
    }

    /** Only a path on this site, never another host */
    private function safeReturn(?string $return): string
    {
        return is_string($return) && preg_match('#^/(?![/\\\\])#', $return) ? $return : Url::to(['security']);
    }
}
