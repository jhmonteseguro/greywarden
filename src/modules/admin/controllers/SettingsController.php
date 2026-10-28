<?php

namespace app\modules\admin\controllers;

use app\components\Rbac;
use app\modules\admin\models\BrandingForm;
use app\modules\admin\models\ComplianceForm;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;

/**
 * Instance settings: branding (display name, organisation, logo, sign-in text,
 * PDF marking) and the compliance frameworks switched on. Each has its own form.
 */
class SettingsController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => [Rbac::PERM_SETTINGS]]],
            ],
        ];
    }

    public function actionIndex(): Response|string
    {
        $post = Yii::$app->request->post();
        $model = BrandingForm::current();
        $compliance = ComplianceForm::current();
        if ($compliance->load($post)) {
            if ($compliance->save()) {
                Yii::$app->session->setFlash('success', 'Compliance frameworks saved.');
                return $this->redirect(['index', '#' => 'compliance']);
            }
        } elseif ($model->load($post) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Branding saved.');
            return $this->redirect(['index']);
        }
        return $this->render('index', ['model' => $model, 'compliance' => $compliance]);
    }
}
