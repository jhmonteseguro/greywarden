<?php

namespace app\modules\admin\controllers;

use app\components\LoginThrottle;
use app\components\Rbac;
use app\models\AuditLog;
use app\models\User;
use app\modules\admin\models\ResetPasswordForm;
use app\modules\admin\models\UserForm;
use app\modules\admin\models\UserSearch;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class UserController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => [Rbac::PERM_USERS]]],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['toggle-status' => ['POST'], 'unlock' => ['POST']],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $searchModel = new UserSearch();
        return $this->render('index', ['searchModel' => $searchModel, 'dataProvider' => $searchModel->search(Yii::$app->request->queryParams)]);
    }

    public function actionView(int $id): string
    {
        $user = $this->findUser($id);
        $attempts = (new \yii\db\Query())->from('login_attempt')->where(['username' => [$user->username, $user->email]])
            ->orderBy(['id' => SORT_DESC])->limit(15)->all();
        $locked = (new LoginThrottle())->lockedFor($user->username, null);
        return $this->render('view', ['user' => $user, 'attempts' => $attempts, 'locked' => $locked]);
    }

    public function actionCreate(): Response|string
    {
        $model = new UserForm();
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', "User {$model->username} created.");
            return $this->redirect(['view', 'id' => $model->getUser()->id]);
        }
        return $this->render('create', ['model' => $model]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = new UserForm($this->findUser($id));
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', "User {$model->username} updated.");
            return $this->redirect(['view', 'id' => $id]);
        }
        return $this->render('update', ['model' => $model]);
    }

    public function actionResetPassword(int $id): Response|string
    {
        $user = $this->findUser($id);
        $model = new ResetPasswordForm();
        if ($model->load(Yii::$app->request->post()) && $model->apply($user)) {
            (new LoginThrottle())->clear($user->username);
            Yii::$app->session->setFlash('success', "Password for {$user->username} has been reset.");
            return $this->redirect(['view', 'id' => $id]);
        }
        return $this->render('reset-password', ['model' => $model, 'user' => $user]);
    }

    public function actionToggleStatus(int $id): Response
    {
        $user = $this->findUser($id);
        if ((int) $user->id === (int) Yii::$app->user->id) {
            Yii::$app->session->setFlash('danger', 'You cannot disable your own account.');
            return $this->redirect(['view', 'id' => $id]);
        }
        $user->status = $user->isActive() ? User::STATUS_DISABLED : User::STATUS_ACTIVE;
        $user->generateAuthKey();
        $user->save(false);
        Yii::$app->session->setFlash('success', sprintf('User %s %s.', $user->username, $user->isActive() ? 'enabled' : 'disabled'));
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionUnlock(int $id): Response
    {
        $user = $this->findUser($id);
        $n = (new LoginThrottle())->clear($user->username) + (new LoginThrottle())->clear($user->email);
        AuditLog::record('user', $user->id, $user->getAuditLabel(), 'unlock', ['failed_attempts_cleared' => [$n, 0]]);
        Yii::$app->session->setFlash('success', "Sign-in lockout cleared for {$user->username}.");
        return $this->redirect(['view', 'id' => $id]);
    }

    private function findUser(int $id): User
    {
        $user = User::findOne($id);
        if (!$user) {
            throw new NotFoundHttpException('User not found.');
        }
        return $user;
    }
}
