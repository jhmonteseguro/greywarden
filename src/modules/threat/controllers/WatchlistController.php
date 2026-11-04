<?php

namespace app\modules\threat\controllers;

use app\components\Rbac;
use app\models\Organisation;
use app\modules\threat\components\StoryScorer;
use app\modules\threat\components\Watchlist;
use app\modules\threat\models\ThreatTriage;
use app\modules\threat\models\ThreatWatchTerm;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The organisation's watchlist. Every change re-ranks recent stories at once, so the
 * effect of a term shows straight away.
 */
class WatchlistController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => ['index'], 'roles' => [Rbac::PERM_VIEW]],
                    ['allow' => true, 'actions' => ['create', 'toggle', 'delete', 'sync'], 'roles' => [Rbac::PERM_MANAGE]],
                ],
            ],
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['create' => ['POST'], 'toggle' => ['POST'], 'delete' => ['POST'], 'sync' => ['POST']]],
        ];
    }

    public function actionIndex(): string
    {
        return $this->render('index', [
            'terms' => ThreatWatchTerm::find()->where(['organisation_id' => Organisation::currentId()])->orderBy(['term' => SORT_ASC])->all(),
            'model' => new ThreatWatchTerm(['category' => 'other']),
            'matches' => self::matchCounts(),
        ]);
    }

    /** @return array<string, int> term => recent stories mentioning it, to show which terms are noisy */
    private static function matchCounts(): array
    {
        $counts = [];
        foreach (ThreatTriage::find()->forOrganisation()->select('matched_terms')->andWhere(['<>', 'matched_terms', '[]'])->column() as $json) {
            foreach (json_decode((string) $json, true) ?: [] as $term) {
                $counts[$term] = ($counts[$term] ?? 0) + 1;
            }
        }
        return $counts;
    }

    public function actionCreate(): Response
    {
        $model = new ThreatWatchTerm(['organisation_id' => Organisation::currentId(), 'origin' => ThreatWatchTerm::ORIGIN_MANUAL, 'is_enabled' => 1]);
        $model->load(Yii::$app->request->post());
        $model->organisation_id = Organisation::currentId();
        $model->origin = ThreatWatchTerm::ORIGIN_MANUAL;
        if ($model->save()) {
            self::rescore();
            Yii::$app->session->setFlash('success', sprintf('%s added to the watchlist.', $model->term));
        } else {
            Yii::$app->session->setFlash('danger', implode(' ', $model->getFirstErrors()));
        }
        return $this->redirect(['index']);
    }

    public function actionToggle(int $id): Response
    {
        $model = $this->findModel($id);
        $model->is_enabled = $model->is_enabled ? 0 : 1;
        $model->save(false);
        self::rescore();
        Yii::$app->session->setFlash('success', sprintf('%s switched %s.', $model->term, $model->is_enabled ? 'on' : 'off'));
        return $this->redirect(['index']);
    }

    /** Terms from the asset registers would only come back: those are switched off instead */
    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        if ($model->getIsDerived()) {
            Yii::$app->session->setFlash('warning', sprintf('%s comes from your assets, so it would come back. Switch it off instead.', $model->term));
            return $this->redirect(['index']);
        }
        $model->delete();
        self::rescore();
        Yii::$app->session->setFlash('success', sprintf('%s removed from the watchlist.', $model->term));
        return $this->redirect(['index']);
    }

    public function actionSync(): Response
    {
        $result = Watchlist::sync(Organisation::currentId());
        self::rescore();
        Yii::$app->session->setFlash('success', sprintf('Updated from your assets: %d added, %d removed.', $result['added'], $result['removed']));
        return $this->redirect(['index']);
    }

    private static function rescore(): void
    {
        StoryScorer::run(Organisation::currentId());
    }

    private function findModel(int $id): ThreatWatchTerm
    {
        return ThreatWatchTerm::findOwn($id) ?? throw new NotFoundHttpException('Term not found.');
    }
}
