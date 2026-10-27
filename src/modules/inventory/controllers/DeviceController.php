<?php

namespace app\modules\inventory\controllers;

use app\components\CrudController;
use app\models\DomainRecord;
use app\modules\inventory\models\Device;
use app\modules\inventory\models\DeviceSearch;

class DeviceController extends CrudController
{
    protected function modelClass(): string
    {
        return Device::class;
    }

    protected function searchClass(): string
    {
        return DeviceSearch::class;
    }

    protected function applyDefaults(DomainRecord $model): void
    {
        /** @var Device $model */
        $model->status = 'active';
        $model->criticality = 'medium';
        $model->data_classification = 'internal';
    }
}
