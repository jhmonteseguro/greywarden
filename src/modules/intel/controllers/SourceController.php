<?php

namespace app\modules\intel\controllers;

use app\components\Rbac;
use app\modules\intel\components\Feeds;
use app\modules\intel\components\SourceRegistry;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

/**
 * Intelligence sources and local feed status; analysts can trigger a feed refresh.
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
                    ['allow' => true, 'actions' => ['update-feeds'], 'roles' => [Rbac::PERM_MANAGE]],
                ],
            ],
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['update-feeds' => ['POST']]],
        ];
    }

    public function actionIndex(): string
    {
        return $this->render('index', [
            'sources' => SourceRegistry::all(),
            'status' => Feeds::status(),
        ]);
    }

    /** Downloads run after the redirect has been sent (PHP-FPM), so the page returns at once. */
    public function actionUpdateFeeds(): Response
    {
        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function(static function () {
                fastcgi_finish_request();
                ignore_user_abort(true);
                set_time_limit(600);
                Feeds::update(null, true);
            });
            Yii::$app->session->setFlash('info', 'Feed update started in the background. Reload this page in a minute to see the new counts.');
        } else {
            $results = Feeds::update(null, true);
            $failed = array_keys(array_filter($results, static fn($r) => !$r['ok']));
            Yii::$app->session->setFlash($failed ? 'warning' : 'success', sprintf('%d feeds updated%s.', count($results) - count($failed), $failed ? '; failed: ' . implode(', ', $failed) : ''));
        }
        return $this->redirect(['index']);
    }
}
