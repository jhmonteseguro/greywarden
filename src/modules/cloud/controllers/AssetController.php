<?php

namespace app\modules\cloud\controllers;

use app\components\CrudController;
use app\models\DomainRecord;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\cloud\models\CloudAssetSearch;
use Yii;

class AssetController extends CrudController
{
    protected function modelClass(): string
    {
        return CloudAsset::class;
    }

    protected function searchClass(): string
    {
        return CloudAssetSearch::class;
    }

    /** "Add asset" from an account page pre-selects the account (and its environment) */
    protected function applyDefaults(DomainRecord $model): void
    {
        /** @var CloudAsset $model */
        $model->status = CloudAsset::STATUS_ACTIVE;
        $model->criticality = 'medium';
        $model->data_classification = 'internal';
        $model->encryption_at_rest = CloudAsset::ENCRYPTION_UNKNOWN;
        $model->environment = null; // "Same as the account" unless an account is pre-selected
        $account = CloudAccount::find()->active()->andWhere(['id' => (int) Yii::$app->request->get('account')])->one();
        if ($account) {
            $model->account_id = $account->id;
            $model->environment = $account->environment;
        }
    }
}
