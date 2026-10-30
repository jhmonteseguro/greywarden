<?php

namespace app\modules\report\controllers;

use app\components\CrudController;
use app\components\Rbac;
use app\models\DomainRecord;
use app\modules\report\models\Report;
use app\modules\report\models\ReportSearch;
use Yii;
use yii\web\Response;

/**
 * Management reports. Besides the CRUD actions: regenerate the figures and
 * approve (analysts, drafts only) and withdraw an approval (administrators).
 */
class ReportController extends CrudController
{
    protected function modelClass(): string
    {
        return Report::class;
    }

    protected function searchClass(): string
    {
        return ReportSearch::class;
    }

    protected function viewActions(): array
    {
        return ['pdf'];
    }

    protected function manageActions(): array
    {
        return ['regenerate', 'approve'];
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['access']['rules'][] = ['allow' => true, 'actions' => ['withdraw'], 'roles' => [Rbac::PERM_DELETE]];
        $behaviors['verbs']['actions'] += ['regenerate' => ['POST'], 'approve' => ['POST'], 'withdraw' => ['POST']];
        return $behaviors;
    }

    protected function applyDefaults(DomainRecord $model): void
    {
        /** @var Report $model */
        $model->scenario = Report::SCENARIO_CREATE;
        $model->preset = 'last-month';
    }

    /** A new report gets its figures (Report::create()) */
    protected function saveModel(DomainRecord $model): bool
    {
        /** @var Report $model */
        return $model->isNewRecord ? $model->create() : $model->save();
    }

    public function actionUpdate(int $id): Response|string
    {
        /** @var Report $model */
        $model = $this->findModel($id);
        if ($model->isApproved()) {
            Yii::$app->session->setFlash('warning', 'Approved reports are read-only. An administrator can withdraw the approval to change it.');
            return $this->redirect($model->getViewUrl());
        }
        return parent::actionUpdate($id);
    }

    public function actionRegenerate(int $id): Response
    {
        /** @var Report $model */
        $model = $this->findModel($id);
        if ($model->regenerate()) {
            Yii::$app->session->setFlash('success', sprintf('Figures for %s worked out again.', $model->reference));
        } else {
            Yii::$app->session->setFlash('warning', 'Only draft reports that are not archived can be regenerated.');
        }
        return $this->redirect($model->getViewUrl());
    }

    public function actionApprove(int $id): Response
    {
        /** @var Report $model */
        $model = $this->findModel($id);
        if ($model->approve(Yii::$app->request->post('approval_note'))) {
            Yii::$app->session->setFlash('success', sprintf('%s approved. It is now read-only.', $model->reference));
        } else {
            Yii::$app->session->setFlash('warning', 'The report was not approved: ' . implode(' ', $model->approvalBlockers()));
        }
        return $this->redirect($model->getViewUrl());
    }

    public function actionWithdraw(int $id): Response
    {
        /** @var Report $model */
        $model = $this->findModel($id);
        if ($model->withdrawApproval()) {
            Yii::$app->session->setFlash('info', sprintf('Approval of %s withdrawn. It is a draft again.', $model->reference));
        } else {
            Yii::$app->session->setFlash('warning', 'Only approved reports that are not archived can be withdrawn.');
        }
        return $this->redirect($model->getViewUrl());
    }
}
