<?php

namespace app\modules\dashboard\controllers;

use app\components\Rbac;
use yii\filters\AccessControl;
use yii\web\Controller;

class DefaultController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => [Rbac::PERM_VIEW]]],
            ],
        ];
    }

    public function actionIndex(): string
    {
        /** @var \app\modules\dashboard\Module $module */
        $module = $this->module;
        return $this->render('index', ['kpis' => $module->kpis, 'panels' => $module->panels]);
    }
}
