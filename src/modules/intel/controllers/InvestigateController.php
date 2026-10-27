<?php

namespace app\modules\intel\controllers;

use app\components\Rbac;
use app\modules\intel\components\Feeds;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\Investigator;
use app\modules\intel\components\LocalContext;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\BlocklistSearch;
use app\modules\intel\models\IntelIndicator;
use app\modules\intel\models\IntelNote;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * IP address / ASN investigation.
 *
 *   /intel                      search + recent investigations
 *   /intel/ip/203.0.113.7       IP report      (?refresh=1 bypasses the source cache)
 *   /intel/asn/AS64496          ASN report
 *   POST /intel/note, /intel/verdict   analyst notes and verdict (records.manage)
 */
class InvestigateController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => ['index', 'ip', 'asn', 'indicator'], 'roles' => [Rbac::PERM_VIEW]],
                    ['allow' => true, 'actions' => ['note', 'verdict'], 'roles' => [Rbac::PERM_MANAGE]],
                ],
            ],
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['note' => ['POST'], 'verdict' => ['POST']]],
        ];
    }

    /** Extract the first IP / ASN from pasted text such as "203.0.113.7:443", "hxxp://[2001:db8::1]/x" or "AS64496". */
    public static function extract(string $q): ?Indicator
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }
        if ($i = Indicator::parse($q)) {
            return $i;
        }
        $clean = str_replace(['[.]', '(.)', '{.}', '[:]'], ['.', '.', '.', ':'], $q);
        if (preg_match('/(?<![\d.])((?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3})(?![\d])/', $clean, $m)) {
            return Indicator::ip($m[1]);
        }
        if (preg_match('/\[?([0-9a-f]{0,4}(?::[0-9a-f]{0,4}){2,7})\]?/i', $clean, $m) && ($i = Indicator::ip($m[1]))) {
            return $i;
        }
        if (preg_match('/\bAS\s?(\d{1,10})\b/i', $clean, $m)) {
            return Indicator::parse('AS' . $m[1]);
        }
        return null;
    }

    public function actionIndex(string $q = ''): Response|string
    {
        if ($q !== '') {
            if ($indicator = self::extract($q)) {
                return $this->redirect($indicator->url());
            }
            Yii::$app->session->setFlash('warning', 'Enter an IPv4 / IPv6 address or an AS number (e.g. 203.0.113.7 or AS64496).');
        }
        $recent = new ActiveDataProvider([
            'query' => IntelIndicator::find()->with(['lastCheckedBy'])->where(['not', ['last_checked_at' => null]]),
            'pagination' => ['pageSize' => 15],
            'sort' => ['defaultOrder' => ['last_checked_at' => SORT_DESC], 'attributes' => ['value', 'last_score', 'last_checked_at', 'lookup_count', 'verdict']],
        ]);
        $flagged = IntelIndicator::find()->where(['verdict' => [IntelIndicator::VERDICT_MALICIOUS, IntelIndicator::VERDICT_SUSPICIOUS]])
            ->orderBy(['verdict_at' => SORT_DESC])->limit(8)->all();
        $noteCounts = array_map('intval', (new Query())->select(['n' => 'COUNT(*)', 'indicator_id'])->from('intel_note')->groupBy('indicator_id')->indexBy('indicator_id')->column());
        $blockCounts = BlocklistSearch::counts();
        $feedEntries = (int) (new Query())->from('intel_feed')->sum('entry_count');
        Feeds::refreshInBackground();

        return $this->render('index', [
            'q' => $q,
            'recent' => $recent,
            'flagged' => $flagged,
            'noteCounts' => $noteCounts,
            'stats' => [
                'investigated' => (int) IntelIndicator::find()->count(),
                'malicious' => (int) IntelIndicator::find()->where(['or', ['verdict' => IntelIndicator::VERDICT_MALICIOUS], ['and', ['verdict' => null], ['last_rating' => 'malicious']]])->count(),
                'blocked' => $blockCounts['ip']['active'] + $blockCounts['asn']['active'],
                'blockedIp' => $blockCounts['ip']['active'],
                'blockedAsn' => $blockCounts['asn']['active'],
                'feedEntries' => $feedEntries,
                'feeds' => (int) (new Query())->from('intel_feed')->where(['>', 'entry_count', 0])->count(),
            ],
        ]);
    }

    public function actionIp(string $ip, int $refresh = 0): Response|string
    {
        $indicator = Indicator::ip($ip);
        if (!$indicator) {
            throw new NotFoundHttpException('Not a valid IP address.');
        }
        if ($indicator->value !== $ip) {
            return $this->redirect($indicator->url($refresh ? ['refresh' => 1] : []));
        }
        return $this->report($indicator, (bool) $refresh);
    }

    public function actionAsn(string $asn, int $refresh = 0): Response|string
    {
        $indicator = Indicator::parse($asn);
        if (!$indicator || !$indicator->isAsn()) {
            throw new NotFoundHttpException('Not a valid AS number.');
        }
        if ($indicator->value !== $asn) {
            return $this->redirect($indicator->url($refresh ? ['refresh' => 1] : []));
        }
        return $this->report($indicator, (bool) $refresh);
    }

    private function report(Indicator $indicator, bool $refresh): string
    {
        $report = Investigator::run($indicator, $refresh);
        $model = IntelIndicator::recordLookup($report);
        $local = LocalContext::for($indicator, $report->facts);
        $note = new IntelNote(['indicator_id' => $model->id]);
        $block = new BlocklistEntry(['value' => $indicator->value, 'severity' => 'high', 'direction' => 'both']);
        if ($local->incidents) {
            $block->incident_id = $local->incidents[0]->id;
        }
        return $this->render('report', [
            'indicator' => $indicator,
            'report' => $report,
            'model' => $model,
            'local' => $local,
            'note' => $note,
            'block' => $block,
        ]);
    }

    /** Audit log links point at the record id */
    public function actionIndicator(int $id): Response
    {
        $model = IntelIndicator::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException('Indicator not found.');
        }
        return $this->redirect($model->getViewUrl());
    }

    private function findIndicator(): IntelIndicator
    {
        $indicator = Indicator::parse((string) Yii::$app->request->post('indicator', ''));
        if (!$indicator) {
            throw new BadRequestHttpException('Missing indicator.');
        }
        $model = IntelIndicator::findOrCreate($indicator);
        if ($model->isNewRecord) {
            $model->save(false);
        }
        return $model;
    }

    public function actionNote(): Response
    {
        $model = $this->findIndicator();
        $note = new IntelNote(['indicator_id' => $model->id]);
        if ($note->load(Yii::$app->request->post()) && $note->save()) {
            Yii::$app->session->setFlash('success', 'Note added to ' . $model->value . '.');
        } else {
            Yii::$app->session->setFlash('danger', 'Note not added: ' . implode(' ', $note->getErrorSummary(true)));
        }
        return $this->redirect($model->getViewUrl() + ['#' => 'tab-notes']);
    }

    public function actionVerdict(): Response
    {
        $model = $this->findIndicator();
        $verdict = (string) Yii::$app->request->post('verdict', '');
        if ($model->setVerdict($verdict)) {
            Yii::$app->session->setFlash('success', $verdict === ''
                ? 'Analyst verdict cleared for ' . $model->value . '.'
                : sprintf('%s marked as %s.', $model->value, strtolower(IntelIndicator::verdictOptions()[$verdict])));
        } else {
            Yii::$app->session->setFlash('danger', 'Unknown verdict.');
        }
        return $this->redirect($model->getViewUrl());
    }
}
