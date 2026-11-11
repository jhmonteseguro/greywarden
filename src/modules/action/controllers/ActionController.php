<?php

namespace app\modules\action\controllers;

use app\components\CrudController;
use app\models\DomainRecord;
use app\modules\action\models\Action;
use app\modules\action\models\ActionSearch;
use Yii;
use yii\web\Response;

class ActionController extends CrudController
{
    protected function modelClass(): string
    {
        return Action::class;
    }

    protected function searchClass(): string
    {
        return ActionSearch::class;
    }

    protected function manageActions(): array
    {
        return ['close'];
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs']['actions']['close'] = ['POST'];
        return $behaviors;
    }

    /** New action: owned by you, due in 30 days; ?record_type=&record_id= raises it from that record */
    protected function applyDefaults(DomainRecord $model): void
    {
        /** @var Action $model */
        $model->status = Action::STATUS_OPEN;
        $model->priority = Action::PRIORITY_MEDIUM;
        $model->owner_id = Yii::$app->user->id;
        $model->due_date = date('Y-m-d', strtotime('+30 days'));
        $request = Yii::$app->request;
        $class = Action::RECORD_CLASSES[(string) $request->get('record_type')] ?? null;
        $record = $class !== null ? $class::findOne((int) $request->get('record_id')) : null;
        if ($record !== null) {
            $model->raiseFrom($record);
        }
    }

    /** Closes an action from its page: done (or cancelled) with the outcome */
    public function actionClose(int $id): Response
    {
        /** @var Action $model */
        $model = $this->findModel($id);
        if ($model->getIsArchived() || !$model->isOpen()) {
            return $this->redirect($model->getViewUrl());
        }
        $post = Yii::$app->request->post();
        $model->status = ($post['status'] ?? '') === Action::STATUS_CANCELLED ? Action::STATUS_CANCELLED : Action::STATUS_DONE;
        $model->outcome = (string) ($post['outcome'] ?? '');
        if ($model->save()) {
            Yii::$app->session->setFlash('success', sprintf('Action %s %s.', $model->reference, $model->status === Action::STATUS_DONE ? 'done' : 'cancelled'));
        } else {
            Yii::$app->session->setFlash('danger', implode(' ', $model->getErrorSummary(false)));
        }
        return $this->redirect($model->getViewUrl());
    }
}
