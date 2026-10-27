<?php
/**
 * Top of a report: verdict hero (gauge, rating, headline, key facts), summary
 * tiles, "why this rating" signal breakdown and the source sweep.
 *
 * @var yii\web\View $this
 * @var app\modules\intel\components\Report $report
 * @var app\modules\intel\models\IntelIndicator $model
 * @var app\modules\intel\components\LocalContext $local
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\intel\components\Assessment;
use app\modules\intel\components\IntelHtml;
use app\modules\intel\components\SourceRegistry;
use app\modules\intel\components\SourceResult;
use app\modules\intel\models\IntelIndicator;
use yii\helpers\Html;

$a = $report->assessment();
$f = $report->facts;
$i = $report->indicator;
$special = $a->rating === 'special';
$activeBlocks = $local->activeBlocks();
$asn = $i->isAsn() ? $i->asn : ($f['asn'] ?? null);
$owner = $f['as_name'] ?? $f['org'] ?? null;
$cc = $f['country'] ?? $f['registered_country'] ?? null;

// Key facts beside the verdict
$facts = [];
if ($i->isIp()) {
    $facts['Owner'] = $f['org'] ?? $f['as_name'] ?? null;
    $facts['Origin AS'] = $asn ? Html::a('AS' . $asn, ['/intel/investigate/asn', 'asn' => 'AS' . $asn], ['class' => 'gw-intel-pivot']) . (isset($f['as_name']) ? ' <span class="gw-muted">' . Html::encode($f['as_name']) . '</span>' : '') : null;
    $facts['Prefix'] = isset($f['bgp_prefix']) ? '<span class="gw-mono">' . Html::encode($f['bgp_prefix']) . '</span>' : (isset($f['network']) ? '<span class="gw-mono">' . Html::encode($f['network']) . '</span>' : null);
    $facts['Location'] = $cc ? IntelHtml::flag($cc) . ' ' . Html::encode(implode(', ', array_filter([$f['city'] ?? null, Assessment::countryName($cc)]))) : null;
    $facts['Reverse DNS'] = isset($f['hostname']) ? '<span class="gw-mono">' . Html::encode($f['hostname']) . '</span>' . (!empty($f['fcrdns']) ? ' <i class="bi bi-patch-check gw-intel-ok" title="Forward-confirmed"></i>' : '') : null;
    $facts['Abuse contact'] = isset($f['abuse_email']) ? Html::mailto(Html::encode($f['abuse_email']), $f['abuse_email']) : null;
} else {
    $facts['Holder'] = $owner ? Html::encode($owner) : null;
    $facts['Country'] = $cc ? IntelHtml::flag($cc) . ' ' . Html::encode(Assessment::countryName($cc)) : null;
    $facts['Registered'] = isset($f['registered_at']) ? Html::encode($f['registered_at']) : null;
    $facts['Website'] = isset($f['website']) ? IntelHtml::value($f['website']) : null;
    $facts['Abuse contact'] = isset($f['abuse_email']) ? Html::mailto(Html::encode($f['abuse_email']), $f['abuse_email']) : null;
}
if ($special) {
    $facts = ['Range' => Html::encode((string) $i->specialPurpose())] + array_filter($facts);
}
$facts = array_filter($facts);

$risk = $a->riskSignals();
$benign = $a->benignSignals();
$context = $a->contextSignals();
$maxWeight = max(1, ...array_map(static fn($s) => abs($s['weight']), $a->signals ?: [['weight' => 1]]));
$counts = $report->statusCounts();
?>
<section class="gw-intel-hero gw-tone--<?= $a->tone() ?>" data-rating="<?= Html::encode($a->rating) ?>" aria-labelledby="gw-intel-verdict">
    <div class="gw-intel-hero__scan" aria-hidden="true"></div>
    <div class="gw-intel-hero__gauge">
        <?= $this->render('../partials/_gauge', ['score' => $a->score, 'tone' => $a->tone(), 'label' => $special ? 'no score' : 'threat score', 'special' => $special]) ?>
        <div class="gw-intel-confidence" title="Share of reputation sources that answered. Adding free API keys raises it.">
            <span class="gw-intel-confidence__label">Confidence</span>
            <span class="gw-intel-confidence__bar"><span style="width: <?= (int) $a->confidence ?>%"></span></span>
            <span class="gw-intel-confidence__value"><?= $special ? 'n/a · not queried' : Assessment::confidenceLabel($a->confidence) . ' · ' . (int) $a->confidence . '%' ?></span>
        </div>
    </div>

    <div class="gw-intel-hero__main">
        <div class="gw-intel-hero__eyebrow">
            <span>Threat assessment</span>
            <span class="gw-intel-hero__sep" aria-hidden="true">//</span>
            <span><?= Html::encode($i->typeLabel()) ?></span>
            <span class="gw-intel-hero__sep" aria-hidden="true">//</span>
            <time datetime="<?= gmdate('c', $report->asOf()) ?>"><?= gmdate('d M Y H:i', $report->asOf()) ?> UTC</time>
        </div>
        <h2 class="gw-intel-hero__rating" id="gw-intel-verdict"><i class="bi bi-<?= $a->icon() ?>" aria-hidden="true"></i> <?= Html::encode($a->ratingLabel()) ?></h2>
        <p class="gw-intel-hero__headline"><?= Html::encode($a->headline) ?></p>
        <?php if ($line = Assessment::networkLine($report, $a->networkType)): ?>
            <p class="gw-intel-hero__context"><i class="bi bi-diagram-3" aria-hidden="true"></i> <?= Html::encode($line) ?></p>
        <?php endif; ?>
        <div class="gw-intel-hero__flags">
            <?php foreach ($activeBlocks as $b): ?>
                <?= Html::a('<i class="bi bi-slash-circle"></i> Blocked · ' . Html::encode($b->reference) . ($b->value !== $i->value ? ' <span>(' . Html::encode($b->value) . ')</span>' : ''), $b->getViewUrl(), ['class' => 'gw-intel-flagchip gw-intel-flagchip--blocked']) ?>
            <?php endforeach; ?>
            <?php if ($model->verdict): ?>
                <span class="gw-intel-flagchip gw-intel-flagchip--verdict gw-tone--<?= Badge::TONES['verdict'][$model->verdict] ?>" title="Set by <?= Html::encode($model->verdictBy->full_name ?? 'unknown') ?> on <?= Html::encode(Yii::$app->formatter->asDatetime($model->verdict_at)) ?>">
                    <i class="bi bi-person-badge"></i> Analyst: <?= Html::encode(IntelIndicator::verdictOptions()[$model->verdict]) ?>
                </span>
            <?php endif; ?>
            <?php foreach ($local->devices as $d): ?>
                <?= Html::a('<i class="bi bi-pc-display"></i> Your asset · ' . Html::encode($d->asset_tag), $d->getViewUrl(), ['class' => 'gw-intel-flagchip gw-intel-flagchip--asset']) ?>
            <?php endforeach; ?>
            <?php foreach (array_slice($local->incidents, 0, 3) as $inc): ?>
                <?= Html::a('<i class="bi bi-fire"></i> In ' . Html::encode($inc->reference), $inc->getViewUrl(), ['class' => 'gw-intel-flagchip gw-intel-flagchip--incident', 'title' => $inc->title]) ?>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($facts): ?>
        <dl class="gw-intel-hero__facts">
            <?php foreach ($facts as $label => $html): ?>
                <div class="gw-intel-hero__fact"><dt><?= Html::encode($label) ?></dt><dd><?= $html ?></dd></div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
</section>

<ul class="gw-intel-tiles" aria-label="Summary">
    <?php foreach ($a->tiles as $t): ?>
        <li class="gw-intel-tile gw-tone--<?= Html::encode($t['tone']) ?>">
            <span class="gw-intel-tile__icon" aria-hidden="true"><i class="bi bi-<?= Html::encode($t['icon']) ?>"></i></span>
            <span class="gw-intel-tile__label"><?= Html::encode($t['label']) ?></span>
            <span class="gw-intel-tile__value"><?= Html::encode($t['value']) ?></span>
            <span class="gw-intel-tile__caption"><?= Html::encode((string) $t['caption']) ?></span>
        </li>
    <?php endforeach; ?>
</ul>

<div class="row g-3 gw-intel-overview">
    <div class="col-xl-7">
        <section class="gw-panel gw-intel-signals">
            <h2 class="gw-panel__title"><i class="bi bi-activity" aria-hidden="true"></i> Why this rating
                <span class="gw-panel__count"><?= count($a->signals) ?></span></h2>
            <?php if (!$a->signals): ?>
                <div class="gw-empty-state<?= $special ? '' : ' gw-empty-state--ok' ?>">
                    <i class="bi bi-<?= $special ? 'diagram-3' : 'shield-check' ?>" aria-hidden="true"></i>
                    <p><?= $special ? 'Special-purpose space: only local data (blocklist, assets, incidents, feeds) was checked.' : 'No source reported anything about this ' . ($i->isIp() ? 'address' : 'network') . ' that affects the score.' ?></p>
                </div>
            <?php else: ?>
                <ol class="gw-intel-signal-list">
                    <?php foreach ([['Risk evidence', $risk], ['Known-good evidence', $benign], ['Context', $context]] as [$heading, $list]):
                        if (!$list) { continue; } ?>
                        <li class="gw-intel-signal-group"><span><?= $heading ?></span></li>
                        <?php foreach ($list as $s): ?>
                            <li class="gw-intel-signal gw-tone--<?= Html::encode($s['tone']) ?><?= $s['counted'] ? '' : ' is-corroborating' ?>">
                                <span class="gw-intel-signal__weight" title="<?= $s['counted'] ? 'Weight ' . (int) $s['weight'] : 'Corroborates a stronger signal; not counted again' ?>"><?= $s['weight'] > 0 ? '+' . (int) $s['weight'] : ($s['weight'] < 0 ? '−' . abs((int) $s['weight']) : '·') ?></span>
                                <span class="gw-intel-signal__body">
                                    <span class="gw-intel-signal__label"><?= Html::encode($s['label']) ?><?= $s['counted'] ? '' : ' <span class="gw-intel-signal__tag">corroborates</span>' ?></span>
                                    <?php if (!empty($s['detail'])): ?><span class="gw-intel-signal__detail"><?= Html::encode($s['detail']) ?></span><?php endif; ?>
                                </span>
                                <span class="gw-intel-signal__source"><?= Html::a(Html::encode($s['sourceLabel']), '#src-' . $s['source'], ['data-gw-show-tab' => 'tab-intel']) ?></span>
                                <span class="gw-intel-signal__bar" aria-hidden="true"><span style="width: <?= round(100 * abs($s['weight']) / $maxWeight) ?>%"></span></span>
                            </li>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </ol>
                <p class="gw-intel-method">Weights combine like independent probabilities (two moderate listings outweigh one), known-good evidence scales the result down. <?= Html::a('How scoring works', ['/intel/source/index', '#' => 'scoring']) ?></p>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-xl-5">
        <section class="gw-panel gw-intel-sweep">
            <h2 class="gw-panel__title"><i class="bi bi-radar" aria-hidden="true"></i> Source sweep
                <span class="gw-intel-sweep__meta"><?= $report->queriedCount() ?> queried · <?= IntelHtml::duration($report->duration) ?><?= $report->cachedCount() ? ' · ' . $report->cachedCount() . ' cached' : '' ?></span></h2>
            <div class="gw-intel-sweep__legend">
                <?php foreach ([SourceResult::HIT, SourceResult::CLEAN, SourceResult::INFO, SourceResult::ERROR, SourceResult::UNCONFIGURED, SourceResult::SKIPPED] as $st):
                    if (!$counts[$st]) { continue; } ?>
                    <span class="gw-intel-sweep__count gw-intel-status--<?= $st ?>"><span class="gw-intel-dot" aria-hidden="true"></span><?= $counts[$st] ?> <?= strtolower(IntelHtml::STATUS_LABELS[$st]) ?></span>
                <?php endforeach; ?>
            </div>
            <ul class="gw-intel-sweep__list">
                <?php foreach ($report->results as $key => $r):
                    $source = SourceRegistry::get($key); ?>
                    <li class="gw-intel-sweep__item gw-intel-status--<?= $r->status ?>">
                        <span class="gw-intel-dot" aria-hidden="true"></span>
                        <?= Html::a(Html::encode($source->label()), '#src-' . $key, ['class' => 'gw-intel-sweep__name', 'data-gw-show-tab' => $source->category() === 'registry' ? 'tab-registry' : 'tab-intel']) ?>
                        <span class="gw-intel-sweep__summary" title="<?= Html::encode($r->summary) ?>"><?= Html::encode($r->summary) ?></span>
                        <span class="gw-intel-sweep__time"><?= $r->cached ? '<i class="bi bi-database" title="Cached ' . Html::encode(IntelHtml::age($r->fetchedAt)) . '"></i>' : ($r->answered() || $r->status === SourceResult::ERROR ? IntelHtml::duration($r->time) : '') ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="gw-panel gw-intel-verdict-panel">
            <h2 class="gw-panel__title"><i class="bi bi-person-badge" aria-hidden="true"></i> Analyst verdict</h2>
            <?php if ($model->verdict): ?>
                <p class="gw-intel-verdict-panel__current"><?= Badge::verdict($model->verdict) ?>
                    <span class="gw-muted">by <?= Html::encode($model->verdictBy->full_name ?? 'unknown') ?> · <?= Yii::$app->formatter->asDatetime($model->verdict_at) ?></span></p>
            <?php else: ?>
                <p class="gw-muted gw-intel-verdict-panel__current">No analyst verdict yet. The automated rating is <?= Badge::intelRating($a->rating) ?>.</p>
            <?php endif; ?>
            <?php if (Rbac::canManage()): ?>
                <?= Html::beginForm(['/intel/investigate/verdict'], 'post', ['class' => 'gw-intel-verdict-form']) ?>
                <?= Html::hiddenInput('indicator', $i->value) ?>
                <div class="gw-intel-segmented" role="group" aria-label="Set analyst verdict">
                    <?php foreach (IntelIndicator::verdictOptions() as $value => $label): ?>
                        <button type="submit" name="verdict" value="<?= $value ?>" class="gw-intel-segmented__btn gw-tone--<?= Badge::TONES['verdict'][$value] ?><?= $model->verdict === $value ? ' is-active' : '' ?>"
                                aria-pressed="<?= $model->verdict === $value ? 'true' : 'false' ?>"><?= $label ?></button>
                    <?php endforeach; ?>
                    <?php if ($model->verdict): ?>
                        <button type="submit" name="verdict" value="" class="gw-intel-segmented__btn gw-intel-segmented__btn--clear" title="Clear verdict"><i class="bi bi-x-lg"></i><span class="visually-hidden">Clear</span></button>
                    <?php endif; ?>
                </div>
                <?= Html::endForm() ?>
            <?php endif; ?>
            <p class="gw-intel-verdict-panel__meta">
                Investigated <?= (int) $model->lookup_count ?> time<?= (int) $model->lookup_count === 1 ? '' : 's' ?>
                · first <?= Yii::$app->formatter->asDate($model->created_at) ?>
                <?= $model->lastCheckedBy ? '· last by ' . Html::encode($model->lastCheckedBy->full_name) : '' ?>
            </p>
        </section>
    </div>
</div>
