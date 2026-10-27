<?php

namespace app\controllers;

use app\models\PasswordForm;
use app\models\ProfileForm;
use app\widgets\AuditHistory;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;

/**
 * Self-service: own profile and password.
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
}
