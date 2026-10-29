<?php

namespace app\modules\control\controllers;

use app\components\Actor;
use app\components\CrudController;
use app\models\DomainRecord;
use app\modules\control\models\Control;
use app\modules\control\models\ControlSearch;
use app\modules\control\models\ControlTest;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

class ControlController extends CrudController
{
    protected function modelClass(): string
    {
        return Control::class;
    }

    protected function searchClass(): string
    {
        return ControlSearch::class;
    }

    protected function viewActions(): array
    {
        return ['pdf'];
    }

    protected function manageActions(): array
    {
        return ['record-test', 'delete-test'];
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs']['actions'] += ['record-test' => ['POST'], 'delete-test' => ['POST']];
        return $behaviors;
    }

    protected function applyDefaults(DomainRecord $model): void
    {
        /** @var Control $model */
        $model->owner_id = Actor::id();
        $model->status = Control::STATUS_PLANNED;
        $model->check_max_age_days = Control::defaultPatchMaxAgeDays();
    }

    /** Evidence files posted with the form are checked with it and attached once it saves */
    protected function saveModel(DomainRecord $model): bool
    {
        /** @var Control $model */
        $model->evidenceFiles = UploadedFile::getInstances($model, 'evidenceFiles');
        if (!$model->save()) {
            return false;
        }
        if ($problems = $model->attachEvidenceFiles()) {
            Yii::$app->session->setFlash('danger', implode(' ', $problems));
        }
        return true;
    }

    public function actionView(int $id): string
    {
        return $this->render('view', ['model' => $this->findModel($id), 'test' => $this->newTest()]);
    }

    public function actionRecordTest(int $id): Response|string
    {
        /** @var Control $model */
        $model = $this->findModel($id);
        if ($model->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only. Restore the control to record a test.');
            return $this->redirect($model->getViewUrl());
        }
        $test = $this->newTest();
        $test->control_id = $model->id;
        if ($test->load(Yii::$app->request->post())) {
            $test->evidenceFiles = UploadedFile::getInstances($test, 'evidenceFiles');
            if ($test->save()) {
                Yii::$app->session->setFlash('success', sprintf('Test recorded: %s is %s.', $model->reference, strtolower($test->getResultLabel())));
                if ($problems = $test->attachEvidenceFiles()) {
                    Yii::$app->session->setFlash('danger', implode(' ', $problems));
                }
                return $this->redirect(['view', 'id' => $model->id, '#' => 'tab-testing']);
            }
        }
        return $this->render('view', ['model' => $model, 'test' => $test]);
    }

    public function actionDeleteTest(int $id, int $test): Response
    {
        $model = $this->findModel($id);
        $record = ControlTest::findOne(['id' => $test, 'control_id' => $model->id]);
        if (!$record) {
            throw new NotFoundHttpException('Test not found.');
        }
        if ($model->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only.');
            return $this->redirect($model->getViewUrl());
        }
        $record->delete();
        Yii::$app->session->setFlash('info', sprintf('Test of %s removed.', Yii::$app->formatter->asDate($record->tested_on)));
        return $this->redirect(['view', 'id' => $model->id, '#' => 'tab-testing']);
    }

    private function newTest(): ControlTest
    {
        return new ControlTest(['tested_on' => Actor::today(), 'tested_by_id' => Actor::id()]);
    }
}
