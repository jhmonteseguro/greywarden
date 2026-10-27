<?php

namespace app\modules\incident\controllers;

use app\components\Actor;
use app\components\CrudController;
use app\models\DomainRecord;
use app\modules\incident\models\Incident;
use app\modules\incident\models\IncidentSearch;
use app\modules\incident\models\IncidentTimeline;
use Yii;
use yii\web\Response;

class IncidentController extends CrudController
{
    protected function modelClass(): string
    {
        return Incident::class;
    }

    protected function searchClass(): string
    {
        return IncidentSearch::class;
    }

    protected function manageActions(): array
    {
        return ['add-entry'];
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs']['actions']['add-entry'] = ['POST'];
        return $behaviors;
    }

    protected function applyDefaults(DomainRecord $model): void
    {
        /** @var Incident $model */
        $model->status = Incident::STATUS_NEW;
        $model->severity = Incident::SEVERITY_MEDIUM;
        $model->detected_at = Actor::now();
        $model->reported_at = Actor::now();
        $model->assigned_to_id = Actor::id();
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        $entry = new IncidentTimeline(['incident_id' => $model->id, 'entry_type' => IncidentTimeline::TYPE_NOTE, 'entry_at' => Actor::now()]);
        return $this->render('view', ['model' => $model, 'entry' => $entry]);
    }

    /** Inline timeline entry from the view page. */
    public function actionAddEntry(int $id): Response|string
    {
        /** @var Incident $model */
        $model = $this->findModel($id);
        if ($model->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived incidents are read-only.');
            return $this->redirect($model->getViewUrl());
        }
        $entry = new IncidentTimeline(['incident_id' => $model->id]);
        if ($entry->load(Yii::$app->request->post()) && $entry->validate()) {
            if ($entry->entry_type === IncidentTimeline::TYPE_STATUS) {
                $entry->entry_type = IncidentTimeline::TYPE_NOTE;
            }
            $entry->save(false);
            Yii::$app->session->setFlash('success', 'Timeline entry added.');
            return $this->redirect(['view', 'id' => $model->id, '#' => 'tab-timeline']);
        }
        Yii::$app->session->setFlash('danger', 'Timeline entry not added: ' . implode(' ', $entry->getErrorSummary(true)));
        return $this->redirect(['view', 'id' => $model->id, '#' => 'tab-timeline']);
    }
}
