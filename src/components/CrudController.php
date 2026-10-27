<?php

namespace app\components;

use app\models\DomainRecord;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Shared CRUD + archive behaviour for the domain modules.
 *
 * Access:  index/view (+viewActions)       -> records.view   (viewer+)
 *          create/update/archive/restore    -> records.manage (analyst+)
 *          delete (hard, archived only)      -> records.delete (admin)
 */
abstract class CrudController extends Controller
{
    /** @return class-string<DomainRecord> */
    abstract protected function modelClass(): string;

    /** @return class-string<\yii\base\Model> */
    abstract protected function searchClass(): string;

    /** Extra read-only actions */
    protected function viewActions(): array
    {
        return [];
    }

    /** Extra write actions */
    protected function manageActions(): array
    {
        return [];
    }

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => array_merge(['index', 'view'], $this->viewActions()), 'roles' => [Rbac::PERM_VIEW]],
                    ['allow' => true, 'actions' => array_merge(['create', 'update', 'archive', 'restore'], $this->manageActions()), 'roles' => [Rbac::PERM_MANAGE]],
                    ['allow' => true, 'actions' => ['delete'], 'roles' => [Rbac::PERM_DELETE]],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['archive' => ['POST'], 'restore' => ['POST'], 'delete' => ['POST']],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $searchClass = $this->searchClass();
        $searchModel = new $searchClass();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        return $this->render('index', ['searchModel' => $searchModel, 'dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        return $this->render('view', ['model' => $this->findModel($id)]);
    }

    /** PDF of one record; controllers enable it by listing 'pdf' in viewActions() and providing a `pdf` view. */
    public function actionPdf(int $id): Response
    {
        return RecordPdf::send($this->getView(), $this->getViewPath() . '/pdf.php', $this->findModel($id));
    }

    public function actionCreate(): Response|string
    {
        $class = $this->modelClass();
        /** @var DomainRecord $model */
        $model = new $class();
        $model->loadDefaultValues();
        $this->applyDefaults($model);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', sprintf('%s %s created.', $class::entityLabel(), $model->getReferenceValue()));
            return $this->redirect($model->getViewUrl());
        }
        $model->populateLinks();
        return $this->render('create', ['model' => $model]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if ($model->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only. Restore the record to edit it.');
            return $this->redirect($model->getViewUrl());
        }
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', sprintf('%s %s updated.', $model::entityLabel(), $model->getReferenceValue()));
            return $this->redirect($model->getViewUrl());
        }
        $model->populateLinks();
        return $this->render('update', ['model' => $model]);
    }

    public function actionArchive(int $id): Response
    {
        $model = $this->findModel($id);
        $model->archive();
        Yii::$app->session->setFlash('info', sprintf('%s %s archived. It can be restored from the Archived filter.', $model::entityLabel(), $model->getReferenceValue()));
        return $this->redirect(['index']);
    }

    public function actionRestore(int $id): Response
    {
        $model = $this->findModel($id);
        $model->restore();
        Yii::$app->session->setFlash('success', sprintf('%s %s restored.', $model::entityLabel(), $model->getReferenceValue()));
        return $this->redirect($model->getViewUrl());
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        if (!$model->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archive the record before deleting it permanently.');
            return $this->redirect($model->getViewUrl());
        }
        $ref = $model->getReferenceValue();
        $model->delete();
        Yii::$app->session->setFlash('success', sprintf('%s %s permanently deleted.', $model::entityLabel(), $ref));
        return $this->redirect(['index', 'filter' => 'archived']);
    }

    /** Hook for create defaults (owner = current user, dates = today ...). */
    protected function applyDefaults(DomainRecord $model): void
    {
    }

    protected function findModel(int $id): DomainRecord
    {
        $class = $this->modelClass();
        $model = $class::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException(sprintf('%s not found.', $class::entityLabel()));
        }
        return $model;
    }
}
