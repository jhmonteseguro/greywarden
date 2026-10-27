<?php

namespace app\modules\admin\controllers;

use app\components\Rbac;
use app\models\AuditLog;
use app\modules\admin\models\AuditLogSearch;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class AuditController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => [Rbac::PERM_AUDIT]]],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $searchModel = new AuditLogSearch();
        return $this->render('index', ['searchModel' => $searchModel, 'dataProvider' => $searchModel->search(Yii::$app->request->queryParams)]);
    }

    public function actionView(int $id): string
    {
        $entry = AuditLog::findOne($id);
        if (!$entry) {
            throw new NotFoundHttpException('Audit entry not found.');
        }
        return $this->render('view', ['entry' => $entry]);
    }
}
