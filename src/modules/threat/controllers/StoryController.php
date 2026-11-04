<?php

namespace app\modules\threat\controllers;

use app\components\Rbac;
use app\modules\threat\models\ThreatPull;
use app\modules\threat\models\ThreatSearch;
use app\modules\threat\models\ThreatSource;
use app\modules\threat\models\ThreatTriage;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Threat Watch: stories to triage, ranked for this organisation.
 *
 *   /threat                     the queue
 *   /threat/<id>                one story: why it ranks, its CVEs and its coverage
 *   POST /threat/<id>/relevant | dismiss | reopen
 *   POST /threat/dismiss-low    dismiss every low-priority story still to triage
 *   POST /threat/bulk           relevant | dismiss | reopen the ticked stories, or all matching the filters
 */
class StoryController extends Controller
{
    /** Bulk action => [new status (null = reopen), statuses it applies to, past tense] */
    public const BULK_ACTIONS = [
        'relevant' => [ThreatTriage::STATUS_RELEVANT, [ThreatTriage::STATUS_NEW, ThreatTriage::STATUS_DISMISSED], 'marked relevant'],
        'dismiss' => [ThreatTriage::STATUS_DISMISSED, [ThreatTriage::STATUS_NEW, ThreatTriage::STATUS_RELEVANT], 'dismissed'],
        'reopen' => [null, [ThreatTriage::STATUS_RELEVANT, ThreatTriage::STATUS_DISMISSED], 'reopened'],
    ];

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => ['index', 'view'], 'roles' => [Rbac::PERM_VIEW]],
                    ['allow' => true, 'actions' => ['relevant', 'dismiss', 'reopen', 'dismiss-low', 'bulk'], 'roles' => [Rbac::PERM_MANAGE]],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['relevant' => ['POST'], 'dismiss' => ['POST'], 'reopen' => ['POST'], 'dismiss-low' => ['POST'], 'bulk' => ['POST']],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $searchModel = new ThreatSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'counts' => ThreatSearch::counts(),
            'lastPull' => ThreatPull::recent(1)[0] ?? null,
            'failing' => (int) ThreatSource::find()->where(['is_enabled' => 1])->andWhere(['>', 'failures', 0])->count(),
        ]);
    }

    public function actionView(int $id): string
    {
        return $this->render('view', ['model' => $this->findModel($id)]);
    }

    public function actionRelevant(int $id): Response
    {
        return $this->decide($id, ThreatTriage::STATUS_RELEVANT, 'Marked relevant.');
    }

    public function actionDismiss(int $id): Response
    {
        return $this->decide($id, ThreatTriage::STATUS_DISMISSED, 'Dismissed. It comes back if it becomes critical or high.');
    }

    private function decide(int $id, string $status, string $message): Response
    {
        $model = $this->findModel($id);
        $model->resolve($status, $this->note());
        Yii::$app->session->setFlash('success', $message);
        return $this->redirect($model->getViewUrl());
    }

    public function actionReopen(int $id): Response
    {
        $model = $this->findModel($id);
        $model->reopen();
        Yii::$app->session->setFlash('success', 'Back in the queue.');
        return $this->redirect($model->getViewUrl());
    }

    public function actionDismissLow(): Response
    {
        $count = 0;
        foreach (ThreatTriage::find()->forOrganisation()->open()->andWhere(['threat_triage.priority' => 'low'])->all() as $triage) {
            $count += (int) $triage->resolve(ThreatTriage::STATUS_DISMISSED, 'Dismissed in bulk as low priority.');
        }
        Yii::$app->session->setFlash('info', sprintf('%d low-priority stor%s dismissed.', $count, $count === 1 ? 'y' : 'ies'));
        return $this->redirect(['index']);
    }

    /** One action on the ticked rows (ids[]), or on every story matching the grid's filters (scope=all) */
    public function actionBulk(): Response
    {
        $request = Yii::$app->request;
        $filters = array_filter(array_intersect_key((array) $request->post(), array_flip(['filter', 'q', 'priority'])), static fn($v) => is_string($v) && $v !== '');
        $back = array_merge(['index'], $filters);
        $do = $request->post('do');
        if (!is_string($do) || !isset(self::BULK_ACTIONS[$do])) {
            Yii::$app->session->setFlash('warning', 'Choose an action for the selected stories.');
            return $this->redirect($back);
        }

        $query = ThreatTriage::find()->forOrganisation()->orderBy(['threat_triage.id' => SORT_ASC]);
        if ($request->post('scope') === 'all') {
            $query->andWhere(['threat_triage.id' => (new ThreatSearch())->search($filters)->query->select('threat_triage.id')->column()]);
        } else {
            $ids = array_filter(array_map('intval', (array) $request->post('ids', [])));
            $query->andWhere(['threat_triage.id' => $ids ?: [0]]);
        }
        $stories = $query->all();
        if (!$stories) {
            Yii::$app->session->setFlash('warning', 'No stories selected.');
            return $this->redirect($back);
        }

        [$status, $from, $done] = self::BULK_ACTIONS[$do];
        $count = 0;
        foreach ($stories as $story) {
            if (in_array($story->status, $from, true)) {
                $count += (int) ($status === null ? $story->reopen() : $story->resolve($status));
            }
        }
        $skipped = count($stories) - $count;
        Yii::$app->session->setFlash('info', sprintf('%d stor%s %s.%s', $count, $count === 1 ? 'y' : 'ies', $done, $skipped ? sprintf(' %d already there.', $skipped) : ''));
        return $this->redirect($back);
    }

    private function note(): ?string
    {
        $note = Yii::$app->request->post('note');
        return is_string($note) ? $note : null;
    }

    /** Only this organisation's stories exist as far as a request is concerned */
    private function findModel(int $id): ThreatTriage
    {
        $model = ThreatTriage::find()->forOrganisation()->andWhere(['threat_triage.id' => $id])->with(['story.cves', 'story.articles.source', 'resolver'])->one();
        if (!$model) {
            throw new NotFoundHttpException('Story not found.');
        }
        return $model;
    }
}
