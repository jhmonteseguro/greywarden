<?php

namespace app\modules\supplier\controllers;

use app\components\CrudController;
use app\models\DomainRecord;
use app\modules\supplier\models\Supplier;
use app\modules\supplier\models\SupplierAssurance;
use app\modules\supplier\models\SupplierSearch;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SupplierController extends CrudController
{
    protected function modelClass(): string
    {
        return Supplier::class;
    }

    protected function searchClass(): string
    {
        return SupplierSearch::class;
    }

    protected function viewActions(): array
    {
        return ['pdf'];
    }

    protected function manageActions(): array
    {
        return ['add-assurance', 'delete-assurance'];
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs']['actions'] += ['add-assurance' => ['POST'], 'delete-assurance' => ['POST']];
        return $behaviors;
    }

    protected function applyDefaults(DomainRecord $model): void
    {
        /** @var Supplier $model */
        $model->status = Supplier::STATUS_ACTIVE;
        $model->criticality = 'medium';
        $model->data_classification = 'internal';
        $model->access_level = Supplier::ACCESS_NONE;
        $model->owner_id = Yii::$app->user->id;
    }

    public function actionView(int $id): string
    {
        return $this->render('view', ['model' => $this->findModel($id), 'assurance' => new SupplierAssurance()]);
    }

    public function actionAddAssurance(int $id): Response|string
    {
        /** @var Supplier $model */
        $model = $this->findModel($id);
        if ($model->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only. Restore the supplier to add assurance.');
            return $this->redirect($model->getViewUrl());
        }
        $assurance = new SupplierAssurance(['supplier_id' => $model->id]);
        if ($assurance->load(Yii::$app->request->post()) && $assurance->save()) {
            Yii::$app->session->setFlash('success', sprintf('%s added to %s.', $assurance->getTypeLabel(), $model->reference));
            return $this->redirect(['view', 'id' => $model->id, '#' => 'tab-assurance']);
        }
        return $this->render('view', ['model' => $model, 'assurance' => $assurance]);
    }

    public function actionDeleteAssurance(int $id, int $assurance): Response
    {
        $model = $this->findModel($id);
        $record = SupplierAssurance::findOne(['id' => $assurance, 'supplier_id' => $model->id]) ?? throw new NotFoundHttpException('Assurance not found.');
        if ($model->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only.');
            return $this->redirect($model->getViewUrl());
        }
        $record->delete();
        Yii::$app->session->setFlash('info', sprintf('%s removed.', $record->getTypeLabel()));
        return $this->redirect(['view', 'id' => $model->id, '#' => 'tab-assurance']);
    }

    /** Cloud accounts keep their supplier: a supplier that still has accounts cannot be deleted */
    public function actionDelete(int $id): Response
    {
        /** @var Supplier $model */
        $model = $this->findModel($id);
        $accounts = (int) $model->getCloudAccounts()->count();
        if ($model->getIsArchived() && $accounts) {
            Yii::$app->session->setFlash('warning', sprintf('%s still has %d cloud account(s). Link them to another supplier or unlink them first.', $model->reference, $accounts));
            return $this->redirect($model->getViewUrl());
        }
        return parent::actionDelete($id);
    }
}
