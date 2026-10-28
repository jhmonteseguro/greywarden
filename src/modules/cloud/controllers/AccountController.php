<?php

namespace app\modules\cloud\controllers;

use app\components\CrudController;
use app\models\DomainRecord;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAccountSearch;
use Yii;
use yii\web\Response;

class AccountController extends CrudController
{
    protected function modelClass(): string
    {
        return CloudAccount::class;
    }

    protected function searchClass(): string
    {
        return CloudAccountSearch::class;
    }

    protected function applyDefaults(DomainRecord $model): void
    {
        /** @var CloudAccount $model */
        $model->status = CloudAccount::STATUS_ACTIVE;
        $model->environment = 'production';
        $model->criticality = 'medium';
        $model->data_classification = 'internal';
        $model->owner_id = Yii::$app->user->id;
    }

    /** Assets keep their account: an account that still has assets cannot be deleted */
    public function actionDelete(int $id): Response
    {
        /** @var CloudAccount $model */
        $model = $this->findModel($id);
        $assets = (int) $model->getAssets()->count();
        if ($model->getIsArchived() && $assets) {
            Yii::$app->session->setFlash('warning', sprintf('%s still has %d asset(s). Delete them or move them to another account first.', $model->reference, $assets));
            return $this->redirect($model->getViewUrl());
        }
        return parent::actionDelete($id);
    }
}
