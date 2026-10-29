<?php

namespace app\modules\admin\controllers;

use app\components\Rbac;
use app\modules\admin\models\BrandingForm;
use app\modules\admin\models\ComplianceForm;
use app\modules\admin\models\EdgeForm;
use app\modules\admin\models\IncidentReportForm;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;

/**
 * Instance settings: branding (display name, organisation, logo, sign-in text,
 * PDF marking), the compliance frameworks switched on, the wording of
 * incident PDF reports and edge monitoring. Each has its own form.
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
        $incidentReport = IncidentReportForm::current();
        $edge = EdgeForm::current();
        if ($compliance->load($post)) {
            if ($compliance->save()) {
                Yii::$app->session->setFlash('success', 'Compliance frameworks saved.');
                return $this->redirect(['index', '#' => 'compliance']);
            }
        } elseif ($incidentReport->load($post)) {
            if ($incidentReport->save()) {
                Yii::$app->session->setFlash('success', 'Incident report wording saved.');
                return $this->redirect(['index', '#' => 'incident-reports']);
            }
        } elseif ($edge->load($post)) {
            if ($edge->save()) {
                Yii::$app->session->setFlash('success', 'Edge monitoring settings saved.');
                return $this->redirect(['index', '#' => 'edge']);
            }
        } elseif ($model->load($post) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Branding saved.');
            return $this->redirect(['index']);
        }
        return $this->render('index', ['model' => $model, 'compliance' => $compliance, 'incidentReport' => $incidentReport, 'edge' => $edge]);
    }
}
