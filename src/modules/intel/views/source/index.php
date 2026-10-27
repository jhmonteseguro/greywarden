<?php
/**
 * Intelligence sources (live lookups) and local feeds, plus how the score is calculated.
 *
 * @var yii\web\View $this
 * @var app\modules\intel\components\sources\Source[] $sources
 * @var array $status feed_key => intel_feed row
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\intel\components\Assessment;
use app\modules\intel\components\FeedCatalogue;
use app\modules\intel\components\IntelHtml;
use app\modules\intel\components\SourceRegistry;
use yii\helpers\Html;

$this->title = 'Intel Sources';
$this->params['breadcrumbs'][] = ['label' => 'IP Investigation', 'url' => ['/intel/investigate/index']];
$this->params['breadcrumbs'][] = 'Sources';
$this->params['subtitle'] = 'Free services queried live for each investigation, and bulk feeds kept locally and matched without sending anything out.';
$this->params['actions'] = Rbac::canManage() ? Html::a('<i class="bi bi-cloud-arrow-down"></i> Update feeds now', ['update-feeds'], ['class' => 'btn btn-outline-secondary gw-btn', 'data-method' => 'post']) : '';
$fmt = Yii::$app->formatter;
$catalogue = FeedCatalogue::all();
$categories = SourceRegistry::categoryLabels();
$totalEntries = array_sum(array_map(static fn($r) => (int) $r['entry_count'], $status));
$configured = count(array_filter($sources, static fn($s) => $s->isEnabled() && $s->isConfigured()));
?>
<ul class="gw-intel-tiles gw-intel-tiles--4">
    <li class="gw-intel-tile gw-tone--info"><span class="gw-intel-tile__icon"><i class="bi bi-broadcast"></i></span><span class="gw-intel-tile__label">Live sources</span>
        <span class="gw-intel-tile__value"><?= $configured ?> / <?= count($sources) ?></span><span class="gw-intel-tile__caption">enabled and configured</span></li>
    <li class="gw-intel-tile gw-tone--<?= $totalEntries ? 'success' : 'warning' ?>"><span class="gw-intel-tile__icon"><i class="bi bi-collection"></i></span><span class="gw-intel-tile__label">Local feeds</span>
        <span class="gw-intel-tile__value"><?= count(array_filter($status, static fn($r) => $r['entry_count'] > 0)) ?> / <?= count($catalogue) ?></span><span class="gw-intel-tile__caption">downloaded</span></li>
    <li class="gw-intel-tile gw-tone--info"><span class="gw-intel-tile__icon"><i class="bi bi-database"></i></span><span class="gw-intel-tile__label">Feed entries</span>
        <span class="gw-intel-tile__value"><?= Assessment::compactNumber($totalEntries) ?></span><span class="gw-intel-tile__caption">addresses, networks and ASNs</span></li>
    <li class="gw-intel-tile gw-tone--neutral"><span class="gw-intel-tile__icon"><i class="bi bi-key"></i></span><span class="gw-intel-tile__label">Optional keys</span>
        <span class="gw-intel-tile__value"><?= count(array_filter($sources, static fn($s) => $s->apiKeyEnv() && $s->isConfigured())) ?> / <?= count(array_filter($sources, static fn($s) => $s->apiKeyEnv())) ?></span><span class="gw-intel-tile__caption">free API keys set in .env</span></li>
</ul>

<section class="gw-panel gw-panel--flush">
    <div class="gw-panel__header gw-panel__header--flush">
        <h2 class="gw-panel__title"><i class="bi bi-broadcast" aria-hidden="true"></i> Live lookup sources <span class="gw-panel__count"><?= count($sources) ?></span></h2>
    </div>
    <div class="table-responsive">
        <table class="table gw-coverage__table gw-intel-sources">
            <thead><tr><th scope="col">Source</th><th scope="col">Category</th><th scope="col">Indicators</th><th scope="col">Status</th><th scope="col">Terms</th></tr></thead>
            <tbody>
            <?php foreach ($sources as $key => $s):
                $ip = $s->supports(\app\modules\intel\components\Indicator::ip('192.0.2.1'));
                $asn = $s->supports(\app\modules\intel\components\Indicator::asn(64496));
                if (!$s->isEnabled()) {
                    $state = Badge::render('source-status', 'skipped', 'Disabled');
                } elseif (!$s->isConfigured()) {
                    $state = Badge::render('source-status', 'unconfigured', 'Set ' . $s->apiKeyEnv(), ['title' => 'Free API key: add ' . $s->apiKeyEnv() . ' to .env, then run make up (a plain restart does not reload .env)']);
                } else {
                    $state = Badge::render('source-status', 'clean', $s->isLocal() ? 'Local' : 'Active');
                } ?>
                <tr>
                    <td>
                        <span class="gw-intel-sources__name"><i class="bi bi-<?= IntelHtml::CATEGORY_ICONS[$s->category()] ?? 'circle' ?>" aria-hidden="true"></i>
                            <?= str_starts_with($s->homepage(), 'http') ? Html::a(Html::encode($s->label()), $s->homepage(), ['target' => '_blank', 'rel' => 'noopener noreferrer']) : Html::encode($s->label()) ?></span>
                        <div class="gw-coverage__desc"><?= Html::encode($s->description()) ?></div>
                    </td>
                    <td class="text-nowrap"><?= Html::encode($categories[$s->category()] ?? $s->category()) ?></td>
                    <td class="text-nowrap gw-mono"><?= implode(' · ', array_filter([$ip ? 'IP' : null, $asn ? 'ASN' : null])) ?></td>
                    <td class="text-nowrap"><?= $state ?></td>
                    <td class="gw-coverage__techniques"><?= Html::encode((string) $s->terms()) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="gw-panel gw-panel--flush">
    <div class="gw-panel__header gw-panel__header--flush">
        <h2 class="gw-panel__title"><i class="bi bi-collection" aria-hidden="true"></i> Local threat feeds <span class="gw-panel__count"><?= count($catalogue) ?></span></h2>
        <div class="gw-panel__tools"><span class="gw-muted gw-intel-source__time">Refreshed automatically in the background when stale · <span class="gw-mono">make intel-update</span></span></div>
    </div>
    <div class="table-responsive">
        <table class="table gw-coverage__table gw-intel-sources">
            <thead><tr><th scope="col">Feed</th><th scope="col">Category</th><th scope="col">Weight</th><th scope="col" class="text-end">Entries</th><th scope="col">Updated</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($catalogue as $key => $def):
                $row = $status[$key] ?? null;
                $fetched = $row && $row['fetched_at'] ? strtotime($row['fetched_at']) : null;
                $stale = !$fetched || time() - $fetched > $def['maxAge'] * 3600;
                if ($row && $row['last_error']) {
                    $state = Badge::render('source-status', 'error', 'Error', ['title' => $row['last_error']]);
                } elseif (!$fetched) {
                    $state = Badge::render('source-status', 'skipped', 'Not downloaded');
                } elseif ($stale) {
                    $state = Badge::render('source-status', 'error', 'Stale');
                } else {
                    $state = Badge::render('source-status', 'clean', 'Current');
                } ?>
                <tr class="<?= $row && $row['last_error'] ? 'gw-coverage__row--gap' : '' ?>">
                    <td>
                        <span class="gw-intel-sources__name"><?= Html::a(Html::encode($def['name']), $def['homepage'], ['target' => '_blank', 'rel' => 'noopener noreferrer']) ?></span>
                        <div class="gw-coverage__desc"><?= Html::encode($def['description']) ?><?= $row && $row['last_error'] ? '<br><span class="gw-coverage__gaps">' . Html::encode($row['last_error']) . '</span>' : '' ?></div>
                    </td>
                    <td class="text-nowrap"><?= Html::encode(FeedCatalogue::CATEGORY_LABELS[$def['category']]) ?></td>
                    <td class="text-nowrap gw-mono"><?= $def['format'] === 'ipsum' ? '17–75 <span class="gw-muted">(by list count)</span>' : ($def['weight'] ? '+' . $def['weight'] : '<span class="gw-muted">context</span>') ?></td>
                    <td class="text-end gw-mono"><?= $row ? number_format((int) $row['entry_count']) : '—' ?></td>
                    <td class="text-nowrap"><span title="<?= $fetched ? Html::encode($fmt->asDatetime($fetched)) : '' ?>"><?= Html::encode(IntelHtml::age($fetched)) ?></span>
                        <div class="gw-muted gw-intel-when">every <?= $def['maxAge'] >= 24 ? ($def['maxAge'] / 24) . ' d' : $def['maxAge'] . ' h' ?><?= $row && $row['duration_ms'] ? ' · ' . IntelHtml::duration($row['duration_ms'] / 1000) : '' ?></div></td>
                    <td class="text-nowrap"><?= $state ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="gw-panel gw-intel-scoring" id="scoring">
    <h2 class="gw-panel__title"><i class="bi bi-calculator" aria-hidden="true"></i> How the score works</h2>
    <div class="row g-4">
        <div class="col-lg-7">
            <p>Every source turns what it finds into <strong>signals</strong> with a weight from 0 to 100. A Feodo Tracker C2 listing weighs +90, a Tor exit +35, a residential PTR name 0 (context only).
                Known-good evidence such as GreyNoise RIOT or a whitelist carries a negative weight.</p>
            <div class="gw-terminal gw-terminal--compact"><pre class="gw-terminal__body">risk  = 1 − Π (1 − wᵢ / 100)        over all positive weights
score = 100 × risk × (1 − 0.8 × benign / 100)   benign = strongest negative weight</pre></div>
            <p class="mt-3 mb-0">Independent listings reinforce each other: two signals of 50 give 75, not 100. The score therefore never exceeds 100, and one weak hit cannot dominate.
                <strong>Confidence</strong> is the share of reputation sources (feeds, DNSBLs, reputation services) that answered. Sources without an API key count half against it.</p>
        </div>
        <div class="col-lg-5">
            <ul class="gw-intel-bands">
                <?php foreach (['malicious' => '70 – 100', 'suspicious' => '40 – 69', 'elevated' => '15 – 39', 'clean' => '0 – 14, confidence ≥ 40%', 'unknown' => '0 – 14, confidence < 40%', 'special' => 'private / reserved space'] as $rating => $range): ?>
                    <li><?= Badge::intelRating($rating) ?> <span class="gw-mono gw-muted"><?= $range ?></span></li>
                <?php endforeach; ?>
            </ul>
            <p class="gw-muted gw-intel-method">Special-purpose addresses (RFC 1918, documentation, CGNAT …) and private ASNs are never sent to external services.</p>
        </div>
    </div>
</section>
