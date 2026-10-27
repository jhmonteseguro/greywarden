<?php

namespace app\modules\intel\controllers;

use app\components\CrudController;
use app\models\DomainRecord;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\BlocklistSearch;
use Yii;
use yii\web\Response;

/**
 * Blocklist: IP addresses, networks and ASNs. "Archive" is presented as Unblock.
 * Entries can also be pushed to the Cloudflare edge rule (BlocklistEntry::syncCloudflare);
 * the rule is updated before the record is saved, and unblocking takes them out again.
 *
 *   /intel/blocklist             IP addresses & networks   (?kind=asn for ASNs)
 *   /intel/blocklist/create      ?value=203.0.113.7&incident_id=4 pre-fills the form
 */
class BlocklistController extends CrudController
{
    protected function modelClass(): string
    {
        return BlocklistEntry::class;
    }

    protected function searchClass(): string
    {
        return BlocklistSearch::class;
    }

    public function actionIndex(): string
    {
        $searchModel = new BlocklistSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        return $this->render('index', ['searchModel' => $searchModel, 'dataProvider' => $dataProvider, 'counts' => BlocklistSearch::counts()]);
    }

    protected function applyDefaults(DomainRecord $model): void
    {
        /** @var BlocklistEntry $model */
        $model->severity = 'high';
        $model->direction = 'both';
        $request = Yii::$app->request;
        $model->value = mb_substr((string) $request->get('value', ''), 0, 64);
        $model->incident_id = $request->get('incident_id') ? (int) $request->get('incident_id') : null;
    }

    public function actionCreate(): Response|string
    {
        $model = new BlocklistEntry();
        $model->loadDefaultValues();
        $this->applyDefaults($model);
        if ($model->load(Yii::$app->request->post())) {
            if ($model->validate() && $model->syncCloudflare() && $model->save(false)) {
                Yii::$app->session->setFlash('success', trim(sprintf('%s blocked as %s. %s', $model->value, $model->reference, $model->cloudflareNotice)));
                // Blocked from an investigation page: go back to it
                if (Yii::$app->request->post('return') === 'report' && ($url = $model->getIndicatorUrl())) {
                    return $this->redirect($url);
                }
                return $this->redirect($model->getViewUrl());
            }
        }
        return $this->render('create', ['model' => $model]);
    }

    public function actionUpdate(int $id): Response|string
    {
        /** @var BlocklistEntry $model */
        $model = $this->findModel($id);
        if ($model->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only. Restore the record to edit it.');
            return $this->redirect($model->getViewUrl());
        }
        if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->syncCloudflare() && $model->save(false)) {
            Yii::$app->session->setFlash('success', trim(sprintf('Blocklist entry %s updated. %s', $model->reference, $model->cloudflareNotice)));
            return $this->redirect($model->getViewUrl());
        }
        return $this->render('update', ['model' => $model]);
    }

    public function actionArchive(int $id): Response
    {
        /** @var BlocklistEntry $model */
        $model = $this->findModel($id);
        // Take it out of the Cloudflare rule first, so an unblocked entry is never still blocked at the edge
        $model->cloudflare = false;
        if (!$model->syncCloudflare()) {
            Yii::$app->session->setFlash('danger', sprintf('%s was not unblocked: %s', $model->value, $model->getFirstError('cloudflare')));
            return $this->redirect($model->getViewUrl());
        }
        $model->archive();
        Yii::$app->session->setFlash('info', trim(sprintf('%s (%s) unblocked. The entry is kept under Unblocked and can be re-instated. %s', $model->value, $model->reference, $model->cloudflareNotice)));
        return $this->redirect(Yii::$app->request->post('return') === 'report' && ($url = $model->getIndicatorUrl()) ? $url : $model->getViewUrl());
    }

    public function actionRestore(int $id): Response
    {
        /** @var BlocklistEntry $model */
        $model = $this->findModel($id);
        $duplicate = BlocklistEntry::find()->active()->andWhere(['value' => $model->value])->andWhere(['<>', 'id', $model->id])->one();
        if ($duplicate) {
            Yii::$app->session->setFlash('warning', sprintf('%s is already blocked by %s.', $model->value, $duplicate->reference));
            return $this->redirect($model->getViewUrl());
        }
        $model->restore();
        Yii::$app->session->setFlash('success', sprintf('%s (%s) blocked again.', $model->value, $model->reference));
        return $this->redirect($model->getViewUrl());
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        if (!$model->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Unblock the entry before deleting it permanently.');
            return $this->redirect($model->getViewUrl());
        }
        $ref = $model->getReferenceValue();
        $model->delete();
        Yii::$app->session->setFlash('success', sprintf('Blocklist entry %s permanently deleted.', $ref));
        return $this->redirect(['index', 'filter' => 'unblocked']);
    }
}
