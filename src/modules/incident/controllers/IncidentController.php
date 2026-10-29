<?php

namespace app\modules\incident\controllers;

use app\components\Actor;
use app\components\CrudController;
use app\models\Attachment;
use app\models\DomainRecord;
use app\modules\incident\models\Incident;
use app\modules\incident\models\IncidentSearch;
use app\modules\incident\models\IncidentTimeline;
use Yii;
use yii\web\Response;
use yii\web\UploadedFile;

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

    protected function viewActions(): array
    {
        return ['pdf'];
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
        // A refused entry comes back once, so it need not be typed again
        $session = Yii::$app->session;
        if ($draft = $session->get(IncidentTimeline::draftKey($model->id))) {
            $entry->setAttributes(array_filter($draft, static fn($v) => $v !== null));
            $session->remove(IncidentTimeline::draftKey($model->id));
        }
        return $this->render('view', ['model' => $model, 'entry' => $entry]);
    }

    /**
     * Inline timeline entry from the view page. The entry may link a file already
     * on the incident, or attach a new one (which also appears on the Files tab).
     */
    public function actionAddEntry(int $id): Response|string
    {
        /** @var Incident $model */
        $model = $this->findModel($id);
        $back = ['view', 'id' => $model->id, '#' => 'tab-timeline'];
        if ($model->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived incidents are read-only.');
            return $this->redirect($model->getViewUrl());
        }
        $request = Yii::$app->request;
        $entry = new IncidentTimeline(['incident_id' => $model->id]);
        // Yii drops an empty file input, so no upload is null
        $upload = UploadedFile::getInstanceByName('file');

        // Check everything before storing a file, so a refused entry leaves nothing behind
        $problem = $entry->load($request->post()) && $entry->validate() ? null : implode(' ', $entry->getErrorSummary(true));
        $file = null;
        if ($problem === null && ($fileId = (int) $request->post('attachment_id'))) {
            $file = Attachment::findOne(['id' => $fileId, 'entity_type' => $model::entityType(), 'entity_id' => $model->id]);
            $problem = $file === null ? 'That file is not attached to ' . $model->getReferenceValue() . '.' : null;
        }
        if ($problem === null && $upload !== null) {
            if ($file !== null) {
                $problem = 'Link an existing file or attach a new one, not both.';
            } else {
                $file = Attachment::attach($model, $upload);
                $problem = is_string($file) ? $file : null;
            }
        }
        if ($problem !== null) {
            Yii::$app->session->setFlash('danger', 'Timeline entry not added: ' . $problem);
            $draft = $entry->getAttributes(['entry_type', 'entry_at', 'body']);
            $draft['body'] = mb_substr((string) $draft['body'], 0, 20000);
            Yii::$app->session->set(IncidentTimeline::draftKey($model->id), $draft);
            return $this->redirect($back);
        }

        if ($entry->entry_type === IncidentTimeline::TYPE_STATUS) {
            $entry->entry_type = IncidentTimeline::TYPE_NOTE;
        }
        $entry->linkFile($file);
        $entry->save(false);
        Yii::$app->session->setFlash('success', 'Timeline entry added' . ($upload ? ', with ' . $file->original_name . ' attached.' : '.'));
        return $this->redirect($back);
    }
}
