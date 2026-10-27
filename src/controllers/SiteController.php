<?php

namespace app\controllers;

use app\components\Branding;
use app\models\AuditLog;
use app\models\LoginForm;
use Yii;
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
                'only' => ['login', 'logout'],
                'rules' => [
                    ['actions' => ['login'], 'allow' => true, 'roles' => ['?']],
                    ['actions' => ['logout'], 'allow' => true, 'roles' => ['@']],
                ],
                'denyCallback' => fn() => $this->goHome(),
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['logout' => ['post']],
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
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->goBack(['/dashboard/default/index']);
        }
        $model->password = '';
        return $this->render('login', ['model' => $model]);
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
