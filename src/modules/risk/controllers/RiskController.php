<?php

namespace app\modules\risk\controllers;

use app\components\Actor;
use app\components\CrudController;
use app\models\DomainRecord;
use app\modules\risk\models\Risk;
use app\modules\risk\models\RiskSearch;
use app\modules\risk\widgets\RiskHeatmap;
use Yii;

class RiskController extends CrudController
{
    protected function modelClass(): string
    {
        return Risk::class;
    }

    protected function searchClass(): string
    {
        return RiskSearch::class;
    }

    protected function viewActions(): array
    {
        return ['heatmap', 'pdf'];
    }

    protected function applyDefaults(DomainRecord $model): void
    {
        /** @var Risk $model */
        $model->status = Risk::STATUS_IDENTIFIED;
        $model->owner_id = Actor::id();
        $model->date_identified = date('Y-m-d');
        $model->next_review_date = date('Y-m-d', strtotime('+90 days'));
    }

    public function actionHeatmap(string $heatmap = RiskHeatmap::MODE_RESIDUAL): string
    {
        return $this->render('heatmap', ['mode' => $heatmap]);
    }
}
