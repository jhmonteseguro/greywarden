<?php

namespace app\modules\intel\controllers;

use app\components\Actor;
use app\components\Rbac;
use app\models\Lookup;
use app\modules\attack\models\AttackTechnique;
use app\modules\incident\models\Incident;
use app\modules\intel\components\edge\CloudflareTraffic;
use app\modules\intel\components\edge\EdgeAdvisor;
use app\modules\intel\components\edge\EdgeBlocker;
use app\modules\intel\components\edge\EdgePuller;
use app\modules\intel\components\edge\EdgeSettings;
use app\modules\intel\models\EdgeFinding;
use app\modules\intel\models\EdgeFindingSearch;
use app\modules\intel\models\EdgePull;
use RuntimeException;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Edge findings: addresses whose Cloudflare traffic looked like probing or
 * scanning (EdgePuller), with recommended actions.
 *
 *   /intel/edge                 triage queue, suggested WAF rule, repeat networks, recent pulls
 *   /intel/edge/<id>            one finding: why, evidence, recommendations
 *   POST /intel/edge/pull       pull now
 *   POST /intel/edge/<id>/block | incident | dismiss | ignore | reopen
 *   POST /intel/edge/dismiss-low  dismiss every low-priority finding still to triage
 *   POST /intel/edge/bulk       block | dismiss | ignore | reopen the ticked findings, or all matching the filters
 */
class EdgeController extends Controller
{
    /** Bulk actions other than block: action => [new status, statuses it applies to, past tense] */
    public const BULK_ACTIONS = [
        'block' => null,
        'dismiss' => [EdgeFinding::STATUS_DISMISSED, [EdgeFinding::STATUS_NEW], 'dismissed'],
        'ignore' => [EdgeFinding::STATUS_IGNORED, [EdgeFinding::STATUS_NEW, EdgeFinding::STATUS_DISMISSED], 'ignored'],
        'reopen' => [EdgeFinding::STATUS_NEW, [EdgeFinding::STATUS_BLOCKED, EdgeFinding::STATUS_ESCALATED, EdgeFinding::STATUS_DISMISSED, EdgeFinding::STATUS_IGNORED], 'reopened'],
    ];

    /** Why a bulk action passed over a finding */
    private const BULK_SKIP = [
        'dismiss' => 'not to triage',
        'ignore' => 'already closed',
        'reopen' => 'already to triage',
    ];

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => ['index', 'view'], 'roles' => [Rbac::PERM_VIEW]],
                    ['allow' => true, 'actions' => ['pull', 'block', 'incident', 'dismiss', 'ignore', 'reopen', 'dismiss-low', 'bulk'], 'roles' => [Rbac::PERM_MANAGE]],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['pull' => ['POST'], 'block' => ['POST'], 'incident' => ['POST'], 'dismiss' => ['POST'], 'ignore' => ['POST'], 'reopen' => ['POST'], 'dismiss-low' => ['POST'], 'bulk' => ['POST']],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $searchModel = new EdgeFindingSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'counts' => EdgeFindingSearch::counts(),
            'pulls' => EdgePull::recent(),
            'waf' => EdgeAdvisor::wafRule(),
            'clusters' => EdgeAdvisor::asnClusters(),
            'configured' => CloudflareTraffic::isConfigured(),
        ]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        return $this->render('view', ['model' => $model, 'recommendations' => EdgeAdvisor::recommendations($model)]);
    }

    public function actionPull(): Response
    {
        $puller = new EdgePuller();
        try {
            $pull = $puller->run();
        } catch (RuntimeException $e) {
            Yii::$app->session->setFlash('warning', $e->getMessage());
            return $this->redirect(['index']);
        }
        if ($pull->getIsError()) {
            Yii::$app->session->setFlash('danger', 'Pull failed: ' . $pull->message);
        } else {
            Yii::$app->session->setFlash($pull->status === EdgePull::STATUS_OK ? 'success' : 'warning', trim(sprintf(
                'Pulled %s of traffic from %d addresses: %d new finding%s, %d updated. %s',
                Yii::$app->formatter->asDuration(strtotime($pull->window_end) - strtotime($pull->window_start)),
                $pull->ips, $pull->findings_new, $pull->findings_new === 1 ? '' : 's', $pull->findings_updated, (string) $pull->message
            )));
        }
        return $this->redirect(['index']);
    }

    public function actionBlock(int $id): Response
    {
        $model = $this->findModel($id);
        $days = EdgeBlocker::days(Yii::$app->request->post('days', EdgeSettings::blockDays()));
        $result = EdgeBlocker::block([$model], $days);
        if ($result['failed']) {
            Yii::$app->session->setFlash('danger', sprintf('%s was not blocked: %s', $model->ip, reset($result['failed'])));
        } elseif ($result['blocked']) {
            Yii::$app->session->setFlash('success', trim(sprintf('%s blocked as %s%s. %s', $model->ip, $model->blocklistEntry->reference, $days > 0 ? " for $days days" : '', $result['notice'])));
        } else {
            $ref = $result['linked'][$model->reference] ?? $model->blocklistEntry->reference ?? '';
            Yii::$app->session->setFlash('success', sprintf('%s is already blocked by %s.', $model->ip, $ref));
        }
        return $this->redirect($model->getViewUrl());
    }

    /**
     * One action on several findings: the ticked rows (ids[]), or every finding matching
     * the grid's filters (scope=all). Block, dismiss and ignore close findings still to
     * triage (block also takes dismissed and ignored ones); reopen puts closed ones back.
     */
    public function actionBulk(): Response
    {
        $request = Yii::$app->request;
        $filters = array_filter(array_intersect_key((array) $request->post(), array_flip(['filter', 'q', 'priority', 'intel_rating'])),
            static fn($v) => is_string($v) && $v !== '');
        $back = array_merge(['index'], $filters);
        $do = $request->post('do');
        $do = is_string($do) ? $do : '';
        if (!array_key_exists($do, self::BULK_ACTIONS)) {
            Yii::$app->session->setFlash('warning', 'Choose an action for the selected findings.');
            return $this->redirect($back);
        }

        $query = EdgeFinding::find()->with(['blocklistEntry'])->orderBy(['id' => SORT_ASC]);
        if ($request->post('scope') === 'all') {
            $search = new EdgeFindingSearch();
            $query->andWhere(['id' => $search->search($filters)->query->select('edge_finding.id')->column()]);
        } else {
            $ids = array_filter(array_map('intval', (array) $request->post('ids', [])));
            $query->andWhere(['id' => $ids ?: [0]]);
        }
        $findings = $query->all();
        if (!$findings) {
            Yii::$app->session->setFlash('warning', 'No findings selected.');
            return $this->redirect($back);
        }

        $plural = static fn(int $n, string $what = 'finding') => sprintf('%d %s%s', $n, $what, $n === 1 ? '' : ($what === 'address' ? 'es' : 's'));
        if ($do === 'block') {
            $days = EdgeBlocker::days($request->post('days', EdgeSettings::blockDays()));
            $r = EdgeBlocker::block($findings, $days);
            $parts = [];
            if ($r['blocked']) {
                $parts[] = sprintf('%s blocked%s.', $plural(count($r['blocked']), 'address'), $days > 0 ? " for $days days" : ' permanently');
            }
            if ($r['linked']) {
                $parts[] = sprintf('%s already on the blocklist.', $plural(count($r['linked']), 'address'));
            }
            if ($r['skipped']) {
                $parts[] = sprintf('%s already blocked.', $plural(count($r['skipped'])));
            }
            if ($r['notice'] !== '') {
                $parts[] = $r['notice'];
            }
            if ($r['failed']) {
                $first = reset($r['failed']);
                $failed = sprintf('%s not blocked: %s', $plural(count($r['failed'])), count(array_unique($r['failed'])) === 1
                    ? $first : implode(' ', array_map(static fn($ref, $why) => "$ref: $why", array_keys($r['failed']), $r['failed'])));
                Yii::$app->session->setFlash('danger', mb_substr($failed, 0, 1000));
            }
            if ($parts) {
                Yii::$app->session->setFlash('success', implode(' ', $parts));
            }
            return $this->redirect($back);
        }

        [$status, $from, $done] = self::BULK_ACTIONS[$do];
        $count = 0;
        foreach ($findings as $finding) {
            if (!in_array($finding->status, $from, true)) {
                continue;
            }
            $count += (int) ($status === EdgeFinding::STATUS_NEW ? $finding->reopen() : $finding->resolve($status, sprintf('%s in bulk.', ucfirst($done))));
        }
        $skipped = count($findings) - $count;
        Yii::$app->session->setFlash('info', sprintf('%s %s.%s', $plural($count), $done,
            $skipped ? sprintf(' %s skipped (%s).', $plural($skipped), self::BULK_SKIP[$do]) : ''));
        return $this->redirect($back);
    }

    /** Raises an incident from the finding and opens it for editing */
    public function actionIncident(int $id): Response
    {
        $model = $this->findModel($id);
        if ($model->incident) {
            Yii::$app->session->setFlash('info', sprintf('%s is already linked to %s.', $model->reference, $model->incident->reference));
            return $this->redirect($model->incident->getViewUrl());
        }
        $signals = $model->getSignalList();
        $keys = array_column($signals, 'key');
        $exposure = in_array('exposure', $keys, true);
        $exploit = $exposure || in_array('payload', $keys, true);
        $categories = Lookup::options(Lookup::TYPE_INCIDENT_CATEGORY);
        $category = array_search($exploit ? 'Vulnerability Exploitation' : 'Other', $categories, true) ?: array_key_first($categories);

        $incident = new Incident();
        $incident->loadDefaultValues();
        $incident->setAttributes([
            'title' => mb_substr($exposure ? sprintf('Possible exposure found by %s from %s', $model->reference, $model->ip) : sprintf('Hostile scanning from %s (%s)', $model->ip, $model->reference), 0, 255),
            'description' => implode("\n", array_merge(
                [sprintf('Raised from edge finding %s (%s, edge score %d). Cloudflare traffic from %s%s between %s and %s.', $model->reference, $model->headline, $model->score,
                    $model->ip, $model->asn ? " (AS{$model->asn}" . ($model->country ? ", {$model->country}" : '') . ')' : '', $model->first_seen_at, $model->last_seen_at), ''],
                array_map(static fn(array $s) => '- ' . $s['label'], $signals),
            )),
            'category_id' => $category,
            'severity' => $exposure ? Incident::SEVERITY_HIGH : ($model->priority === 'high' ? Incident::SEVERITY_MEDIUM : Incident::SEVERITY_LOW),
            'status' => Incident::STATUS_NEW,
            'detected_at' => $model->first_seen_at,
            'reported_at' => Actor::now(),
            'reported_by' => 'Edge findings (Cloudflare)',
            'assigned_to_id' => Actor::id(),
        ], false);
        $incident->techniqueIds = AttackTechnique::find()->select('id')->where(['external_id' => $exploit ? ['T1595', 'T1190'] : ['T1595']])->column();
        if (!$incident->save()) {
            Yii::$app->session->setFlash('danger', 'The incident was not raised: ' . implode(' ', $incident->getErrorSummary(true)));
            return $this->redirect($model->getViewUrl());
        }
        $model->incident_id = $incident->id;
        $model->resolve(EdgeFinding::STATUS_ESCALATED);
        Yii::$app->session->setFlash('success', sprintf('%s raised from %s. Review the details, then add a timeline entry as you investigate.', $incident->reference, $model->reference));
        return $this->redirect(['/incident/incident/update', 'id' => $incident->id]);
    }

    public function actionDismiss(int $id): Response
    {
        return $this->close($id, EdgeFinding::STATUS_DISMISSED, '%s dismissed. It comes back if the address returns with a high enough score.');
    }

    public function actionIgnore(int $id): Response
    {
        return $this->close($id, EdgeFinding::STATUS_IGNORED, '%s ignored. New traffic from the address is still counted but never reopens it.');
    }

    public function actionReopen(int $id): Response
    {
        $model = $this->findModel($id);
        $model->reopen();
        Yii::$app->session->setFlash('success', sprintf('%s reopened.', $model->reference));
        return $this->redirect($model->getViewUrl());
    }

    public function actionDismissLow(): Response
    {
        $count = 0;
        foreach (EdgeFinding::find()->where(['status' => EdgeFinding::STATUS_NEW, 'priority' => 'low'])->each() as $finding) {
            /** @var EdgeFinding $finding */
            $count += (int) $finding->resolve(EdgeFinding::STATUS_DISMISSED, 'Dismissed in bulk (low priority).');
        }
        Yii::$app->session->setFlash('info', sprintf('%d low-priority finding%s dismissed.', $count, $count === 1 ? '' : 's'));
        return $this->redirect(['index']);
    }

    private function close(int $id, string $status, string $message): Response
    {
        $model = $this->findModel($id);
        $model->resolve($status, (string) Yii::$app->request->post('note', ''));
        Yii::$app->session->setFlash('info', sprintf($message, $model->reference));
        return $this->redirect($model->getViewUrl());
    }

    private function findModel(int $id): EdgeFinding
    {
        $model = EdgeFinding::find()->with(['blocklistEntry', 'incident', 'resolver'])->where(['id' => $id])->one();
        if ($model === null) {
            throw new NotFoundHttpException('Edge finding not found.');
        }
        return $model;
    }
}
