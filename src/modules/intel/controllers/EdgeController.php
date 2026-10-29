<?php

namespace app\modules\intel\controllers;

use app\components\Actor;
use app\components\Rbac;
use app\models\Lookup;
use app\modules\attack\models\AttackTechnique;
use app\modules\incident\models\Incident;
use app\modules\intel\components\edge\CloudflareTraffic;
use app\modules\intel\components\edge\EdgeAdvisor;
use app\modules\intel\components\edge\EdgePuller;
use app\modules\intel\components\edge\EdgeSettings;
use app\modules\intel\models\BlocklistEntry;
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
 */
class EdgeController extends Controller
{
    /** Block lengths offered on a finding (days; 0 = permanent) */
    public const BLOCK_DAYS = [1, 7, 30, 90, 0];

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => ['index', 'view'], 'roles' => [Rbac::PERM_VIEW]],
                    ['allow' => true, 'actions' => ['pull', 'block', 'incident', 'dismiss', 'ignore', 'reopen', 'dismiss-low'], 'roles' => [Rbac::PERM_MANAGE]],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['pull' => ['POST'], 'block' => ['POST'], 'incident' => ['POST'], 'dismiss' => ['POST'], 'ignore' => ['POST'], 'reopen' => ['POST'], 'dismiss-low' => ['POST']],
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
        $days = (int) Yii::$app->request->post('days', EdgeSettings::blockDays());
        $days = in_array($days, self::BLOCK_DAYS, true) || ($days > 0 && $days <= 365) ? $days : EdgeSettings::blockDays();

        $existing = array_values(array_filter(BlocklistEntry::findForIp($model->ip), static fn(BlocklistEntry $b) => $b->getIsInForce()));
        if ($existing) {
            $entry = $existing[0];
            $notice = sprintf('%s is already blocked by %s.', $model->ip, $entry->reference);
        } else {
            $entry = new BlocklistEntry([
                'value' => $model->ip,
                'severity' => $model->priority === 'high' ? 'high' : 'medium',
                'direction' => 'inbound',
                'reason' => mb_substr(sprintf('Edge finding %s: %s.', $model->reference, $model->headline ?: 'suspicious traffic'), 0, 2000),
                'incident_id' => $model->incident_id,
                'expires_at' => $days > 0 ? date('Y-m-d H:i:s', strtotime(Actor::now() . " +$days days")) : null,
                'cloudflare' => CloudflareTraffic::isConfigured(),
            ]);
            // An address blocked before but since unblocked or expired is a new entry; only an exact duplicate fails validation
            if (!($entry->validate() && $entry->syncCloudflare() && $entry->save(false))) {
                Yii::$app->session->setFlash('danger', sprintf('%s was not blocked: %s', $model->ip, implode(' ', $entry->getErrorSummary(true))));
                return $this->redirect($model->getViewUrl());
            }
            $notice = trim(sprintf('%s blocked as %s%s. %s', $model->ip, $entry->reference, $days > 0 ? " for $days days" : '', $entry->cloudflareNotice));
        }
        $model->blocklist_id = $entry->id;
        $model->resolve(EdgeFinding::STATUS_BLOCKED);
        Yii::$app->session->setFlash('success', $notice);
        return $this->redirect($model->getViewUrl());
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
