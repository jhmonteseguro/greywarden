<?php

namespace app\controllers;

use app\components\Branding;
use app\components\LoginThrottle;
use app\components\Mfa;
use app\components\Passkeys;
use app\models\AuditLog;
use app\models\LoginForm;
use app\models\MfaCodeForm;
use Yii;
use yii\base\UserException;
use yii\helpers\Url;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SiteController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['login', 'logout', 'verify', 'passkey-options', 'passkey'],
                'rules' => [
                    ['actions' => ['login', 'verify', 'passkey-options', 'passkey'], 'allow' => true, 'roles' => ['?']],
                    ['actions' => ['logout'], 'allow' => true, 'roles' => ['@']],
                ],
                'denyCallback' => fn() => $this->goHome(),
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['logout' => ['post'], 'passkey-options' => ['post'], 'passkey' => ['post']],
            ],
        ];
    }

    public function actions(): array
    {
        return [
            'error' => [
                'class' => \yii\web\ErrorAction::class,
                'layout' => Yii::$app->user->isGuest ? 'auth' : 'main',
            ],
        ];
    }

    public function actionLogin(): Response|string
    {
        $this->layout = 'auth';
        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post())) {
            if ($model->login()) {
                return $this->goBack(['/dashboard/default/index']);
            }
            if ($model->needsSecondStep) {
                return $this->redirect(['/site/verify']);
            }
        }
        $model->password = '';
        return $this->render('login', ['model' => $model, 'passkeys' => Passkeys::available()]);
    }

    /**
     * The second step after a right password: a code from the authenticator app, a recovery code
     * or one of the user's passkeys.
     */
    public function actionVerify(): Response|string
    {
        $this->layout = 'auth';
        $user = Mfa::pendingUser();
        if ($user === null) {
            Mfa::clearPending();
            Yii::$app->session->setFlash('warning', 'Your sign-in timed out. Enter your password again.');
            return $this->redirect(['/site/login']);
        }
        $model = new MfaCodeForm($user);
        if ($model->load(Yii::$app->request->post()) && $model->verify()) {
            Mfa::completeLogin($user, $model->method);
            if ($model->method === 'recovery') {
                $left = Mfa::recoveryCodesLeft($user);
                Yii::$app->session->setFlash('warning', sprintf('You signed in with a recovery code. %d left. %s', $left,
                    $left <= 3 ? 'Make new ones in Account › Sign-in security.' : ''));
            }
            return $this->goBack(['/dashboard/default/index']);
        }
        if (Mfa::pendingUser() === null) {
            // Locked out while entering codes
            return $this->render('verify', ['model' => $model, 'user' => null, 'hasTotp' => false, 'passkeys' => false]);
        }
        $model->code = '';
        return $this->render('verify', [
            'model' => $model,
            'user' => $user,
            'hasTotp' => Mfa::hasTotp($user),
            'passkeys' => Mfa::passkeyCount($user) > 0 && Passkeys::available(),
        ]);
    }

    /** JSON options for navigator.credentials.get(): the second step, or sign-in with a passkey alone */
    public function actionPasskeyOptions(): Response
    {
        $mode = Yii::$app->request->post('mode');
        $user = $mode === 'second-step' ? Mfa::pendingUser() : null;
        if ($mode === 'second-step' && $user === null) {
            return $this->asJson(['error' => 'Your sign-in timed out. Enter your password again.', 'redirect' => Url::to(['/site/login'])]);
        }
        try {
            return $this->asJson(Passkeys::loginOptions($user));
        } catch (UserException $e) {
            return $this->asJson(['error' => $e->getMessage()]);
        }
    }

    /** Checks the passkey the browser signed with and signs the user in */
    public function actionPasskey(): Response
    {
        $request = Yii::$app->request;
        $pending = $request->post('mode') === 'second-step' ? Mfa::pendingUser() : null;
        if ($request->post('mode') === 'second-step' && $pending === null) {
            return $this->asJson(['error' => 'Your sign-in timed out. Enter your password again.', 'redirect' => Url::to(['/site/login'])]);
        }
        $throttle = new LoginThrottle();
        if (($wait = $throttle->lockedFor($pending?->username ?? '', $request->getUserIP())) > 0) {
            return $this->asJson(['error' => sprintf('Too many failed sign-in attempts. Try again in %d minute(s).', (int) ceil($wait / 60))]);
        }
        $response = (array) $request->post('credential', []);
        try {
            $user = Passkeys::authenticate($pending, $response);
        } catch (UserException $e) {
            if ($pending !== null) {
                Mfa::recordFailure($pending, 'passkey');
            } else {
                $throttle->record('(passkey)', $request->getUserIP(), false, $request->getUserAgent());
                AuditLog::record('user', null, '(passkey)', 'login_failed', ['step' => [null, 'passkey']], null, '(passkey)');
            }
            return $this->asJson(['error' => $e->getMessage()]);
        }
        if (($wait = $throttle->lockedFor($user->username, $request->getUserIP())) > 0) {
            return $this->asJson(['error' => sprintf('Too many failed sign-in attempts. Try again in %d minute(s).', (int) ceil($wait / 60))]);
        }
        Mfa::completeLogin($user, 'passkey');
        return $this->asJson(['redirect' => Yii::$app->user->getReturnUrl(['/dashboard/default/index'])]);
    }

    /**
     * The logo uploaded in Admin › Settings. Public: the sign-in page shows it.
     * Its URL carries the file's hash, so browsers may cache it for good.
     */
    public function actionLogo(): Response
    {
        $path = Branding::logoPath();
        if ($path === null) {
            throw new NotFoundHttpException('No logo has been uploaded.');
        }
        $response = Yii::$app->response->sendFile($path, basename($path), ['mimeType' => Branding::logoMimeType(), 'inline' => true]);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    public function actionLogout(): Response
    {
        $user = Yii::$app->user->identity;
        if ($user) {
            AuditLog::record('user', $user->id, $user->getAuditLabel(), 'logout');
        }
        Yii::$app->user->logout();
        return $this->redirect(['/site/login']);
    }
}
