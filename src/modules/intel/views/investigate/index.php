<?php
/**
 * Investigation console: search, key figures, recent investigations, analyst-flagged indicators.
 *
 * @var yii\web\View $this
 * @var string $q
 * @var yii\data\ActiveDataProvider $recent
 * @var app\modules\intel\models\IntelIndicator[] $flagged
 * @var array<int, int> $noteCounts indicator id => notes
 * @var array $stats
 */

use app\helpers\Badge;
use app\modules\intel\components\Assessment;
use app\modules\intel\components\IntelHtml;
use app\modules\intel\models\IntelIndicator;
use app\widgets\GridView;
use yii\helpers\Html;

$this->title = 'IP Investigation';
$this->params['breadcrumbs'][] = $this->title;
$this->params['hideHeader'] = true;
$fmt = Yii::$app->formatter;
?>
<section class="gw-intel-console" aria-labelledby="gw-intel-console-title">
    <div class="gw-intel-console__grid" aria-hidden="true"></div>
    <div class="gw-intel-console__inner">
        <p class="gw-intel-console__eyebrow"><i class="bi bi-radar" aria-hidden="true"></i> Threat intelligence <span class="gw-intel-hero__sep">//</span> IP &amp; ASN investigation</p>
        <h1 class="gw-intel-console__title" id="gw-intel-console-title">Who is behind this address?</h1>
        <p class="gw-intel-console__lead">Reputation, threat feeds, DNS blocklists, exposure, routing and registration from 16 sources, condensed into one assessment.</p>
        <form class="gw-intel-search" action="" method="get" role="search" data-gw-scan="1">
            <label for="gw-intel-q" class="visually-hidden">IP address or ASN</label>
            <span class="gw-intel-search__prompt" aria-hidden="true">&gt;_</span>
            <input type="search" id="gw-intel-q" name="q" value="<?= Html::encode($q) ?>" class="gw-intel-search__input" maxlength="200"
                   placeholder="203.0.113.7 · 2001:db8::1 · AS64496" autocomplete="off" spellcheck="false" autofocus required>
            <button type="submit" class="btn btn-primary gw-intel-search__go"><i class="bi bi-search" aria-hidden="true"></i> Investigate</button>
        </form>
        <p class="gw-intel-console__hint">Paste anything containing an address, e.g. a log line, <span class="gw-mono">198.51.100[.]23:443</span> or <span class="gw-mono">hxxp://[2001:db8::7]/</span>. Defanged indicators are fine.</p>
    </div>
</section>

<section class="gw-kpis gw-intel-kpis" aria-label="Key figures">
    <div class="gw-kpis__item"><div class="gw-kpi gw-kpi--info"><div class="gw-kpi__head"><span class="gw-kpi__label">Indicators investigated</span><span class="gw-kpi__icon"><i class="bi bi-fingerprint"></i></span></div>
        <span class="gw-kpi__value"><?= number_format($stats['investigated']) ?></span><span class="gw-kpi__caption">IP addresses and ASNs</span></div></div>
    <div class="gw-kpis__item"><div class="gw-kpi gw-kpi--<?= $stats['malicious'] ? 'critical' : 'success' ?>"><div class="gw-kpi__head"><span class="gw-kpi__label">Rated malicious</span><span class="gw-kpi__icon"><i class="bi bi-radioactive"></i></span></div>
        <span class="gw-kpi__value"><?= number_format($stats['malicious']) ?></span><span class="gw-kpi__caption">by an analyst or the last assessment</span></div></div>
    <div class="gw-kpis__item"><a class="gw-kpi gw-kpi--high" href="<?= Html::encode(yii\helpers\Url::to(['/intel/blocklist/index'])) ?>"><div class="gw-kpi__head"><span class="gw-kpi__label">Blocked</span><span class="gw-kpi__icon"><i class="bi bi-slash-circle"></i></span></div>
        <span class="gw-kpi__value"><?= number_format($stats['blocked']) ?></span>
        <ul class="gw-kpi__breakdown"><li class="gw-kpi__breakdown-item gw-kpi__breakdown-item--danger"><span class="gw-kpi__dot"></span><span class="gw-kpi__breakdown-value"><?= $stats['blockedIp'] ?></span> IP / network</li>
            <li class="gw-kpi__breakdown-item gw-kpi__breakdown-item--high"><span class="gw-kpi__dot"></span><span class="gw-kpi__breakdown-value"><?= $stats['blockedAsn'] ?></span> ASN</li></ul>
        <i class="bi bi-arrow-right gw-kpi__go" aria-hidden="true"></i></a></div>
    <div class="gw-kpis__item"><a class="gw-kpi gw-kpi--<?= $stats['feedEntries'] ? 'success' : 'warning' ?>" href="<?= Html::encode(yii\helpers\Url::to(['/intel/source/index'])) ?>"><div class="gw-kpi__head"><span class="gw-kpi__label">Feed entries cached</span><span class="gw-kpi__icon"><i class="bi bi-collection"></i></span></div>
        <span class="gw-kpi__value"><?= Assessment::compactNumber($stats['feedEntries']) ?></span><span class="gw-kpi__caption"><?= $stats['feeds'] ? $stats['feeds'] . ' feeds, matched locally' : 'Feeds downloading…' ?></span>
        <i class="bi bi-arrow-right gw-kpi__go" aria-hidden="true"></i></a></div>
</section>

<div class="row g-3">
    <div class="col-xxl-8">
        <section class="gw-panel gw-panel--flush gw-intel-recent">
            <div class="gw-panel__header gw-panel__header--flush">
                <h2 class="gw-panel__title"><i class="bi bi-clock-history" aria-hidden="true"></i> Recent investigations <span class="gw-panel__count"><?= $recent->getTotalCount() ?></span></h2>
            </div>
            <?= GridView::widget([
                'dataProvider' => $recent,
                'emptyText' => 'Nothing investigated yet. Enter an IP address or ASN above.',
                'columns' => [
                    [
                        'attribute' => 'value',
                        'format' => 'raw',
                        'value' => static fn(IntelIndicator $m) => Html::a(Html::encode($m->value), $m->getViewUrl(), ['class' => 'gw-ref gw-intel-ioc'])
                            . ($m->type === 'asn' ? ' <span class="gw-intel-kind">ASN</span>' : '')
                            . (isset($noteCounts[$m->id]) ? ' <span class="gw-intel-count" title="Notes"><i class="bi bi-sticky"></i> ' . $noteCounts[$m->id] . '</span>' : ''),
                    ],
                    [
                        'label' => 'Owner',
                        'format' => 'raw',
                        'contentOptions' => ['class' => 'gw-intel-owner'],
                        'value' => static fn(IntelIndicator $m) => IntelHtml::flag($m->country) . ' ' . Html::encode((string) $m->summary),
                    ],
                    ['attribute' => 'last_score', 'format' => 'raw', 'value' => static fn(IntelIndicator $m) => Badge::intelRating($m->last_rating, $m->last_score)],
                    ['attribute' => 'verdict', 'label' => 'Verdict', 'format' => 'raw', 'value' => static fn(IntelIndicator $m) => $m->verdict ? Badge::verdict($m->verdict) : ''],
                    ['attribute' => 'lookup_count', 'contentOptions' => ['class' => 'gw-mono']],
                    ['attribute' => 'last_checked_at', 'format' => 'raw', 'value' => static fn(IntelIndicator $m) => '<span title="' . Html::encode($fmt->asDatetime($m->last_checked_at)) . '">' . Html::encode(IntelHtml::age(strtotime($m->last_checked_at))) . '</span>'
                        . ($m->lastCheckedBy ? ' <span class="gw-muted">· ' . Html::encode($m->lastCheckedBy->getInitials()) . '</span>' : '')],
                ],
            ]) ?>
        </section>
    </div>
    <div class="col-xxl-4">
        <section class="gw-panel gw-intel-flagged">
            <h2 class="gw-panel__title"><i class="bi bi-person-badge" aria-hidden="true"></i> Flagged by analysts</h2>
            <?php if (!$flagged): ?>
                <p class="gw-empty">No indicator has been marked malicious or suspicious yet.</p>
            <?php else: ?>
                <ul class="gw-attack-top">
                    <?php foreach ($flagged as $m): ?>
                        <li class="gw-attack-top__item">
                            <?= Html::a(Html::encode($m->value), $m->getViewUrl(), ['class' => 'gw-ref gw-intel-ioc']) ?>
                            <span class="gw-muted gw-intel-flagged__owner"><?= Html::encode((string) $m->summary) ?></span>
                            <span class="gw-attack-top__meta"><?= Badge::verdict($m->verdict) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="gw-panel gw-intel-howto">
            <h2 class="gw-panel__title"><i class="bi bi-lightbulb" aria-hidden="true"></i> Workflow</h2>
            <ol class="gw-intel-steps">
                <li><strong>Investigate</strong>: the assessment at the top explains every point of the score.</li>
                <li><strong>Record</strong>: set an analyst verdict and add notes for colleagues.</li>
                <li><strong>Act</strong>: block the address, its network or its origin AS. Blocks can expire and link to an incident.</li>
                <li><strong>Pivot</strong>: open the indicator in VirusTotal, Shodan, Censys, bgp.tools and others.</li>
            </ol>
        </section>
    </div>
</div>
<?= $this->render('_scan-overlay', ['indicator' => null]) ?>
