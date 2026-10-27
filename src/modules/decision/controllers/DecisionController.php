<?php

namespace app\modules\decision\controllers;

use app\components\CrudController;
use app\models\DomainRecord;
use app\modules\decision\models\Decision;
use app\modules\decision\models\DecisionSearch;

class DecisionController extends CrudController
{
    protected function modelClass(): string
    {
        return Decision::class;
    }

    protected function searchClass(): string
    {
        return DecisionSearch::class;
    }

    protected function viewActions(): array
    {
        return ['pdf'];
    }

    protected function applyDefaults(DomainRecord $model): void
    {
        /** @var Decision $model */
        $model->status = Decision::STATUS_PROPOSED;
        $model->decision_date = date('Y-m-d');
        $model->review_date = date('Y-m-d', strtotime('+1 year'));
    }
}
