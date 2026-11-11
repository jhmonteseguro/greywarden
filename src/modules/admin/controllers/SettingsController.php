<?php

namespace app\modules\admin\controllers;

use app\components\Rbac;
use app\modules\admin\models\BackupForm;
use app\modules\admin\models\BrandingForm;
use app\modules\admin\models\ComplianceForm;
use app\modules\admin\models\EdgeForm;
use app\modules\admin\models\IncidentReportForm;
use app\modules\admin\models\ReportForm;
use app\modules\admin\models\SecurityForm;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;

/**
 * Instance settings: branding (display name, organisation, logo, sign-in text,
 * PDF marking), the compliance frameworks switched on, the wording of
 * incident PDF reports, management reports, edge monitoring, the backup
 * directory and sign-in security. Each has its own form.
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
        $report = ReportForm::current();
        $backup = BackupForm::current();
        $security = SecurityForm::current();
        if ($security->load($post)) {
            if ($security->save()) {
                Yii::$app->session->setFlash('success', 'Sign-in security saved.');
                return $this->redirect(['index', '#' => 'security']);
            }
        } elseif ($compliance->load($post)) {
            if ($compliance->save()) {
                Yii::$app->session->setFlash('success', 'Compliance frameworks saved.');
                return $this->redirect(['index', '#' => 'compliance']);
            }
        } elseif ($incidentReport->load($post)) {
            if ($incidentReport->save()) {
                Yii::$app->session->setFlash('success', 'Incident report wording saved.');
                return $this->redirect(['index', '#' => 'incident-reports']);
            }
        } elseif ($report->load($post)) {
            if ($report->save()) {
                Yii::$app->session->setFlash('success', 'Management report settings saved.');
                return $this->redirect(['index', '#' => 'management-reports']);
            }
        } elseif ($edge->load($post)) {
            if ($edge->save()) {
                Yii::$app->session->setFlash('success', 'Edge monitoring settings saved.');
                return $this->redirect(['index', '#' => 'edge']);
            }
        } elseif ($backup->load($post)) {
            if ($backup->save()) {
                Yii::$app->session->setFlash('success', 'Backup settings saved.');
                return $this->redirect(['index', '#' => 'backups']);
            }
        } elseif ($model->load($post) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Branding saved.');
            return $this->redirect(['index']);
        }
        return $this->render('index', ['model' => $model, 'compliance' => $compliance, 'incidentReport' => $incidentReport, 'report' => $report, 'edge' => $edge, 'backup' => $backup, 'security' => $security]);
    }
}
