<?php

namespace app\modules\admin\controllers;

use app\components\Rbac;
use app\models\Lookup;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LookupController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => [Rbac::PERM_LOOKUPS]]],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['toggle' => ['POST'], 'delete' => ['POST']],
            ],
        ];
    }

    public function actionIndex(string $type = Lookup::TYPE_RISK_CATEGORY): string
    {
        if (!array_key_exists($type, Lookup::types())) {
            $type = Lookup::TYPE_RISK_CATEGORY;
        }
        $dataProvider = new ActiveDataProvider([
            'query' => Lookup::find()->where(['type' => $type]),
            'pagination' => false,
            'sort' => ['defaultOrder' => ['sort_order' => SORT_ASC, 'name' => SORT_ASC], 'attributes' => ['name', 'sort_order', 'is_active']],
        ]);
        $counts = Lookup::find()->select(['COUNT(*)', 'type'])->groupBy('type')->indexBy('type')->column();
        return $this->render('index', ['type' => $type, 'dataProvider' => $dataProvider, 'counts' => $counts]);
    }

    public function actionCreate(string $type = Lookup::TYPE_RISK_CATEGORY): Response|string
    {
        $model = new Lookup(['type' => array_key_exists($type, Lookup::types()) ? $type : Lookup::TYPE_RISK_CATEGORY, 'is_active' => true]);
        $model->sort_order = ((int) Lookup::find()->where(['type' => $model->type])->max('sort_order')) + 10;
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', "“{$model->name}” added.");
            return $this->redirect(['index', 'type' => $model->type]);
        }
        return $this->render('form', ['model' => $model]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $type = $model->type;
        if ($model->load(Yii::$app->request->post())) {
            $model->type = $type; // the list a value belongs to is fixed
        }
        if (Yii::$app->request->isPost && $model->save()) {
            Yii::$app->session->setFlash('success', "“{$model->name}” updated.");
            return $this->redirect(['index', 'type' => $model->type]);
        }
        return $this->render('form', ['model' => $model]);
    }

    /** Alias so audit-log links to lookups resolve. */
    public function actionView(int $id): Response
    {
        return $this->redirect(['update', 'id' => $id]);
    }

    public function actionToggle(int $id): Response
    {
        $model = $this->findModel($id);
        $model->is_active = !$model->is_active;
        $model->save(false);
        Yii::$app->session->setFlash('success', sprintf('“%s” %s.', $model->name, $model->is_active ? 'activated' : 'deactivated'));
        return $this->redirect(['index', 'type' => $model->type]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        if (($n = $model->getUsageCount()) > 0) {
            Yii::$app->session->setFlash('warning', sprintf('“%s” is used by %d record(s); deactivate it instead.', $model->name, $n));
        } else {
            $model->delete();
            Yii::$app->session->setFlash('success', "“{$model->name}” deleted.");
        }
        return $this->redirect(['index', 'type' => $model->type]);
    }

    private function findModel(int $id): Lookup
    {
        $model = Lookup::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException('Lookup value not found.');
        }
        return $model;
    }
}
