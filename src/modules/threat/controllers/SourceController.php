<?php

namespace app\modules\threat\controllers;

use app\components\Rbac;
use app\modules\intel\components\Http;
use app\modules\threat\components\FeedParser;
use app\modules\threat\components\ThreatPuller;
use app\modules\threat\models\ThreatPull;
use app\modules\threat\models\ThreatSource;
use app\modules\threat\models\ThreatStory;
use InvalidArgumentException;
use RuntimeException;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The feeds Threat Watch reads and how they are doing. Analysts can read them now; sources
 * are shared by the whole instance, so changing them takes the settings permission.
 */
class SourceController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => ['index'], 'roles' => [Rbac::PERM_VIEW]],
                    ['allow' => true, 'actions' => ['pull'], 'roles' => [Rbac::PERM_MANAGE]],
                    ['allow' => true, 'actions' => ['create', 'toggle', 'delete'], 'roles' => [Rbac::PERM_SETTINGS]],
                ],
            ],
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['pull' => ['POST'], 'create' => ['POST'], 'toggle' => ['POST'], 'delete' => ['POST']]],
        ];
    }

    public function actionIndex(): string
    {
        return $this->render('index', [
            'sources' => ThreatSource::find()->orderBy(['name' => SORT_ASC])->all(),
            'pulls' => ThreatPull::recent(),
            'model' => new ThreatSource(['category' => ThreatSource::CATEGORY_NEWS]),
        ]);
    }

    /** Reads run after the redirect has been sent (PHP-FPM), so the page returns at once */
    public function actionPull(): Response
    {
        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function(static function () {
                fastcgi_finish_request();
                ignore_user_abort(true);
                set_time_limit(300);
                self::pull();
            });
            Yii::$app->session->setFlash('info', 'Reading the sources in the background. Reload this page in a minute to see the results.');
            return $this->redirect(['index']);
        }
        try {
            $pull = self::pull();
            Yii::$app->session->setFlash($pull->status === ThreatPull::STATUS_OK ? 'success' : 'warning', trim(sprintf('%d sources read, %d failed: %d new articles. %s',
                $pull->sources_read, $pull->sources_failed, $pull->articles_new, (string) $pull->message)));
        } catch (RuntimeException $e) {
            Yii::$app->session->setFlash('warning', $e->getMessage());
        }
        return $this->redirect(['index']);
    }

    private static function pull(): ThreatPull
    {
        $puller = new ThreatPuller();
        $puller->force = true;
        return $puller->run();
    }

    /** A feed is only added once it has been read successfully */
    public function actionCreate(): Response
    {
        $model = new ThreatSource(['format' => ThreatSource::FORMAT_RSS, 'is_custom' => 1, 'is_enabled' => 1, 'interval_minutes' => ThreatSource::CUSTOM_INTERVAL]);
        $model->load(Yii::$app->request->post());
        $model->key = 'custom-' . bin2hex(random_bytes(6));
        $model->format = ThreatSource::FORMAT_RSS;
        $model->is_custom = 1;
        if (!$model->validate()) {
            Yii::$app->session->setFlash('danger', implode(' ', $model->getFirstErrors()));
            return $this->redirect(['index']);
        }
        try {
            $request = ThreatPuller::request($model);
            $items = FeedParser::parse(ThreatPuller::body(Http::fetchAll(['feed' => $request])['feed']), ThreatSource::FORMAT_RSS);
            if (!$items) {
                throw new RuntimeException('The feed has no items.');
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            Yii::$app->session->setFlash('danger', sprintf('%s was not added: %s', $model->name, $e->getMessage()));
            return $this->redirect(['index']);
        }
        $model->save(false);
        Yii::$app->session->setFlash('success', sprintf('%s added, with %d items. It is read on the next pull.', $model->name, count($items)));
        return $this->redirect(['index']);
    }

    public function actionToggle(int $id): Response
    {
        $model = $this->findModel($id);
        $model->is_enabled = $model->is_enabled ? 0 : 1;
        $model->save(false);
        Yii::$app->session->setFlash('success', sprintf('%s switched %s.', $model->name, $model->is_enabled ? 'on' : 'off'));
        return $this->redirect(['index']);
    }

    /** Only custom feeds: built-in ones are switched off. Its articles go too, and stories nothing else covers. */
    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        if (!$model->is_custom) {
            Yii::$app->session->setFlash('warning', 'Built-in sources can only be switched off.');
            return $this->redirect(['index']);
        }
        $stories = ThreatStory::find()->innerJoinWith('articles', false)->where(['threat_article.source_id' => $model->id])->distinct()->all();
        $model->delete();
        foreach ($stories as $story) {
            $story->refreshCounts();
        }
        Yii::$app->session->setFlash('success', sprintf('%s removed.', $model->name));
        return $this->redirect(['index']);
    }

    private function findModel(int $id): ThreatSource
    {
        return ThreatSource::findOne($id) ?? throw new NotFoundHttpException('Source not found.');
    }
}
