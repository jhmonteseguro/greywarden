<?php
/**
 * Edge findings: the triage queue, the suggested WAF rule, networks with
 * repeat findings and the recent pulls.
 *
 * @var yii\web\View $this
 * @var app\modules\intel\models\EdgeFindingSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var array $counts filter => count
 * @var app\modules\intel\models\EdgePull[] $pulls
 * @var array $waf EdgeAdvisor::wafRule()
 * @var array $clusters EdgeAdvisor::asnClusters()
 * @var bool $configured
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\intel\components\Assessment;
use app\modules\intel\components\edge\EdgeAdvisor;
use app\modules\intel\components\edge\EdgeSettings;
use app\modules\intel\models\EdgeFinding;
use app\modules\intel\models\EdgeFindingSearch;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Edge findings';
$this->params['breadcrumbs'][] = 'Threat Intel';
$this->params['breadcrumbs'][] = 'Edge findings';
$fmt = Yii::$app->formatter;
$last = $pulls[0] ?? null;
$this->params['subtitle'] = $last
    ? sprintf('Last pull %s · %s', $fmt->asRelativeTime($last->started_at), $last->window_start ? $fmt->asTime($last->window_start, 'short') . '–' . $fmt->asTime($last->window_end, 'short') : 'no window')
    : 'Not pulled yet';
$actions = [];
if (Rbac::canManage()) {
    if ($counts['new'] && EdgeFinding::find()->where(['status' => EdgeFinding::STATUS_NEW, 'priority' => 'low'])->exists()) {
        $actions[] = Html::a('<i class="bi bi-check2-all"></i> Dismiss low priority', ['dismiss-low'], [
            'class' => 'btn btn-outline-secondary gw-btn', 'data-method' => 'post',
            'data-confirm' => 'Dismiss every low-priority finding still to triage? They come back if the address returns with a higher score.',
        ]);
    }
    if ($configured) {
        $actions[] = Html::a('<i class="bi bi-cloud-download"></i> Pull now', ['pull'], ['class' => 'btn btn-primary gw-btn', 'data-method' => 'post']);
    }
}
$this->params['actions'] = implode("\n", $actions);
?>
<?php if (!$configured): ?>
    <div class="alert alert-info gw-callout" role="status">
        <i class="bi bi-cloud-slash" aria-hidden="true"></i>
        <span>Cloudflare is not configured. Set <code>CLOUDFLARE_API_TOKEN</code> (with Zone › Analytics › Read; to block, also Zone › Zone WAF › Edit and Account › Account Filter Lists › Edit) and <code>CLOUDFLARE_ZONE_ID</code> in <code>.env</code>,
        then <code>make up</code>: the scheduler container pulls every <code>EDGE_PULL_INTERVAL</code> minutes (default 15).</span>
    </div>
<?php elseif ($last && $last->getIsError()): ?>
    <div class="alert alert-danger gw-callout" role="alert">
        <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
        <span>The last pull failed: <?= Html::encode((string) $last->message) ?></span>
    </div>
<?php endif; ?>

<section class="gw-kpis gw-edge-kpis" aria-label="Key figures">
    <div class="gw-kpis__item"><a class="gw-kpi gw-kpi--<?= $counts['new'] ? 'warning' : 'success' ?>" href="<?= Html::encode(Url::to(['index', 'filter' => 'new'])) ?>"><div class="gw-kpi__head"><span class="gw-kpi__label">To triage</span><span class="gw-kpi__icon"><i class="bi bi-inbox"></i></span></div>
        <span class="gw-kpi__value"><?= number_format($counts['new']) ?></span><span class="gw-kpi__caption"><?= $counts['high'] ?> high priority</span></a></div>
    <div class="gw-kpis__item"><a class="gw-kpi gw-kpi--<?= $counts['exposure'] ? 'critical' : 'success' ?>" href="<?= Html::encode(Url::to(['index', 'filter' => 'exposure'])) ?>"><div class="gw-kpi__head"><span class="gw-kpi__label">Possible exposures</span><span class="gw-kpi__icon"><i class="bi bi-exclamation-diamond"></i></span></div>
        <span class="gw-kpi__value"><?= number_format($counts['exposure']) ?></span><span class="gw-kpi__caption">sensitive paths that answered 200</span></a></div>
    <div class="gw-kpis__item"><a class="gw-kpi gw-kpi--info" href="<?= Html::encode(Url::to(['index', 'filter' => 'blocked'])) ?>"><div class="gw-kpi__head"><span class="gw-kpi__label">Blocked from findings</span><span class="gw-kpi__icon"><i class="bi bi-slash-circle"></i></span></div>
        <span class="gw-kpi__value"><?= number_format($counts['blocked']) ?></span><span class="gw-kpi__caption"><?= $counts['escalated'] ?> incident<?= $counts['escalated'] === 1 ? '' : 's' ?> raised</span></a></div>
    <div class="gw-kpis__item"><div class="gw-kpi gw-kpi--<?= $last ? Badge::TONES['edge-pull'][$last->status] : 'neutral' ?>"><div class="gw-kpi__head"><span class="gw-kpi__label">Last pull</span><span class="gw-kpi__icon"><i class="bi bi-cloud-download"></i></span></div>
        <span class="gw-kpi__value"><?= $last ? Assessment::compactNumber((int) $last->requests) : '—' ?></span><span class="gw-kpi__caption"><?= $last ? sprintf('requests from %s addresses', number_format($last->ips)) : 'waiting for the scheduler' ?></span></div></div>
</section>

<?= QuickFilters::widget([
    'filters' => EdgeFindingSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'counts' => $counts,
    'placeholder' => 'Search address, ASN, path…',
]) ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'filterModel' => $searchModel,
    'emptyText' => $searchModel->filter === 'new' ? 'Nothing to triage. New findings appear here after each pull.' : 'No findings match.',
    'columns' => [
        ['class' => ReferenceColumn::class, 'attribute' => 'reference'],
        [
            'attribute' => 'ip',
            'format' => 'raw',
            'enableSorting' => false,
            'contentOptions' => ['class' => 'gw-col-title'],
            'value' => static function (EdgeFinding $m) {
                $html = Html::a(Html::encode($m->ip), $m->getViewUrl(), ['class' => 'gw-intel-ioc']);
                if ($m->exposure) {
                    $html .= ' ' . Badge::flag('missing', 'Exposure', 'A sensitive path answered 200');
                }
                $where = array_filter([$m->asn ? 'AS' . $m->asn : null, $m->country]);
                return $html . '<div class="gw-intel-reason">' . Html::encode($m->headline) . ($where ? ' <span class="gw-muted">· ' . Html::encode(implode(' · ', $where)) . '</span>' : '') . '</div>';
            },
        ],
        [
            'attribute' => 'priority',
            'format' => 'raw',
            'value' => static fn(EdgeFinding $m) => Badge::render('edge-priority', $m->priority) . '<div class="gw-muted gw-intel-when">score ' . (int) $m->score . '</div>',
            'filter' => EdgeFinding::priorityOptions(),
        ],
        [
            'attribute' => 'requests',
            'format' => 'raw',
            'filter' => false,
            'value' => static fn(EdgeFinding $m) => '<span class="gw-mono">' . number_format($m->getReached()) . '</span>'
                . ($m->stopped ? '<div class="gw-muted gw-intel-when">' . number_format($m->stopped) . ' stopped</div>' : ''),
            'headerOptions' => ['title' => 'Requests that reached the site (and those Cloudflare stopped)'],
            'label' => 'Got through',
        ],
        [
            'attribute' => 'intel_rating',
            'format' => 'raw',
            'value' => static fn(EdgeFinding $m) => $m->intel_rating ? Badge::intelRating($m->intel_rating, $m->intel_score) : '<span class="gw-muted">not checked</span>',
            'filter' => array_map(static fn($r) => $r['label'], Assessment::RATINGS),
        ],
        [
            'attribute' => 'last_seen_at',
            'format' => 'raw',
            'filter' => false,
            'value' => static fn(EdgeFinding $m) => $fmt->asRelativeTime($m->last_seen_at) . '<div class="gw-muted gw-intel-when">' . ($m->times_seen > 1 ? sprintf('in %d pulls', $m->times_seen) : 'first time') . '</div>',
        ],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'enableSorting' => false,
            'filter' => false,
            'value' => static fn(EdgeFinding $m) => Badge::render('edge-status', $m->status, $m->getStatusLabel()),
        ],
    ],
]) ?>

<div class="row g-4 mt-1">
    <div class="col-xl-8">
        <section class="gw-panel" id="waf">
            <h2 class="gw-panel__title"><i class="bi bi-shield-plus" aria-hidden="true"></i> Suggested WAF rule</h2>
            <?php if ($waf['expression'] === null): ?>
                <p class="gw-empty">No probe paths in the last <?= EdgeAdvisor::LOOKBACK_DAYS ?> days.</p>
            <?php else: ?>
                <p class="gw-muted gw-attack-tab__intro">
                    Blocking the paths scanners look for stops the next address too, so the blocklist stays short. Create a custom rule
                    (Security › WAF › Custom rules) with this expression and the <strong>Block</strong> action. It only covers the groups seen in the last <?= EdgeAdvisor::LOOKBACK_DAYS ?> days.
                    If your site really serves one of these paths, add its prefix under Admin › Settings › Edge monitoring and it drops out.
                </p>
                <ul class="gw-edge-groups">
                    <?php foreach ($waf['groups'] as $group): ?>
                        <li><strong><?= Html::encode($group['label']) ?></strong> <span class="gw-muted">· <?= $group['findings'] ?> finding<?= $group['findings'] === 1 ? '' : 's' ?></span>
                            <div class="gw-edge-paths"><?php foreach ($group['paths'] as $path => $n): ?><code><?= Html::encode($path) ?></code> <?php endforeach; ?></div></li>
                    <?php endforeach; ?>
                </ul>
                <div class="gw-terminal gw-terminal--compact">
                    <div class="gw-terminal__bar" aria-hidden="true"><span></span><span></span><span></span><code>cloudflare</code></div>
                    <button type="button" class="gw-terminal__copy" data-gw-copy-target="#edge-waf" title="Copy"><i class="bi bi-copy"></i><span class="visually-hidden">Copy</span></button>
                    <pre class="gw-terminal__body" id="edge-waf"><?= Html::encode($waf['expression']) ?></pre>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-diagram-3" aria-hidden="true"></i> Networks with repeat findings</h2>
            <?php if (!$clusters): ?>
                <p class="gw-empty">No network has <?= EdgeAdvisor::CLUSTER_MIN ?> or more findings in the last <?= EdgeAdvisor::LOOKBACK_DAYS ?> days.</p>
            <?php else: ?>
                <ul class="gw-edge-clusters">
                    <?php foreach ($clusters as $asn => $c): ?>
                        <li>
                            <?= Html::a('AS' . $asn, ['/intel/investigate/asn', 'asn' => 'AS' . $asn], ['class' => 'gw-intel-ioc']) ?>
                            <span class="gw-muted"><?= $c['count'] ?> findings<?= $c['open'] ? ', ' . $c['open'] . ' to triage' : '' ?></span>
                            <?= $c['blocked'] ? Badge::blockState('blocked')
                                : (Rbac::canManage() ? Html::a('Block ASN', ['/intel/blocklist/create', 'value' => 'AS' . $asn], ['class' => 'gw-panel__link']) : '') ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-clock-history" aria-hidden="true"></i> Recent pulls</h2>
            <?php if (!$pulls): ?>
                <p class="gw-empty">No pulls yet. <?= EdgeSettings::pullInterval() > 0
                    ? sprintf('The scheduler container pulls every %d minutes once Cloudflare is configured;', EdgeSettings::pullInterval())
                    : 'Scheduled pulls are off (<code>EDGE_PULL_INTERVAL=0</code>);' ?> <em>Pull now</em> or <code>make edge-pull</code> pull straight away.</p>
            <?php else: ?>
                <ul class="gw-edge-pulls">
                    <?php foreach ($pulls as $pull): ?>
                        <li>
                            <?= Badge::render('edge-pull', $pull->status) ?>
                            <span><?= $fmt->asDatetime($pull->started_at, 'short') ?></span>
                            <span class="gw-muted"><?= $pull->getIsError() ? '' : sprintf('%s req · %d new · %d updated', Assessment::compactNumber((int) $pull->requests), $pull->findings_new, $pull->findings_updated) ?>
                                · <?= Html::encode($pull->runner->full_name ?? 'scheduler') ?></span>
                            <?php if ($pull->message): ?><div class="gw-edge-pulls__message"><?= Html::encode($pull->message) ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
