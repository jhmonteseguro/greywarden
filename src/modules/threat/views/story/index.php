<?php
/**
 * Threat Watch: the stories to triage, most urgent first.
 *
 * @var yii\web\View $this
 * @var app\modules\threat\models\ThreatSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var array $counts filter => count
 * @var app\modules\threat\models\ThreatPull|null $lastPull
 * @var int $failing enabled sources currently failing
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\threat\components\StoryScorer;
use app\modules\threat\models\ThreatSearch;
use app\modules\threat\models\ThreatTriage;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\grid\CheckboxColumn;
use yii\helpers\Html;
use yii\helpers\StringHelper;
use yii\helpers\Url;

$this->title = 'Threat Watch';
$this->params['breadcrumbs'][] = 'Threat Intel';
$this->params['breadcrumbs'][] = 'Threat Watch';
$fmt = Yii::$app->formatter;
$this->params['subtitle'] = $lastPull ? 'Last read ' . $fmt->asRelativeTime($lastPull->started_at) : 'Not read yet';
$actions = [Html::a('<i class="bi bi-eye"></i> Watchlist', ['/threat/watchlist/index'], ['class' => 'btn btn-outline-secondary gw-btn'])];
if (Rbac::canManage() && ThreatTriage::find()->forOrganisation()->open()->andWhere(['threat_triage.priority' => 'low'])->exists()) {
    $actions[] = Html::a('<i class="bi bi-check2-all"></i> Dismiss low priority', ['dismiss-low'], [
        'class' => 'btn btn-outline-secondary gw-btn', 'data-method' => 'post',
        'data-confirm' => 'Dismiss every low-priority story still to triage? One comes back if it becomes critical or high.',
    ]);
}
$this->params['actions'] = implode("\n", $actions);
$canBulk = Rbac::canManage() && $dataProvider->getTotalCount() > 0;
$kpi = static fn(string $tone, string $filter, string $label, string $icon, int $value, string $caption) => sprintf(
    '<div class="gw-kpis__item"><a class="gw-kpi gw-kpi--%s" href="%s"><div class="gw-kpi__head"><span class="gw-kpi__label">%s</span><span class="gw-kpi__icon"><i class="bi bi-%s"></i></span></div><span class="gw-kpi__value">%s</span><span class="gw-kpi__caption">%s</span></a></div>',
    $tone, Html::encode(Url::to(['index', 'filter' => $filter])), Html::encode($label), $icon, number_format($value), Html::encode($caption));
?>
<?php if (!$lastPull): ?>
    <div class="alert alert-info gw-callout" role="status">
        <i class="bi bi-rss" aria-hidden="true"></i>
        <span>Nothing has been read yet. The scheduler container reads the sources every few minutes, or use <em>Read now</em> on <?= Html::a('News Sources', ['/threat/source/index']) ?>.</span>
    </div>
<?php elseif ($lastPull->getIsError()): ?>
    <div class="alert alert-danger gw-callout" role="alert">
        <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
        <span>The last read failed: <?= Html::encode((string) $lastPull->message) ?></span>
    </div>
<?php elseif ($failing): ?>
    <div class="alert alert-warning gw-callout" role="status">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <span><?= $failing ?> source<?= $failing === 1 ? ' is' : 's are' ?> failing, so coverage may have gaps. See <?= Html::a('News Sources', ['/threat/source/index']) ?>.</span>
    </div>
<?php endif; ?>

<section class="gw-kpis gw-edge-kpis" aria-label="Key figures">
    <?= $kpi($counts['urgent'] ? 'critical' : 'success', 'urgent', 'Critical and high', 'exclamation-octagon', $counts['urgent'], 'still to triage') ?>
    <?= $kpi($counts['exploited'] ? 'warning' : 'success', 'exploited', 'Exploited', 'bug', $counts['exploited'], 'in KEV or reported in the wild') ?>
    <?= $kpi('info', 'matched', 'On your watchlist', 'eye', $counts['matched'], 'stories naming what you run') ?>
    <?= $kpi('neutral', 'new', 'To triage', 'inbox', $counts['new'], sprintf('%s background advisories set aside', number_format($counts['background']))) ?>
</section>

<?= QuickFilters::widget([
    'filters' => ThreatSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'counts' => $counts,
    'placeholder' => 'Search headline, summary or CVE…',
]) ?>

<?php if ($canBulk): ?>
    <?= Html::beginForm(['bulk'], 'post', ['id' => 'threat-bulk', 'class' => 'gw-bulk', 'data-gw-bulk' => '', 'data-total' => $dataProvider->getTotalCount(), 'hidden' => true]) ?>
        <?php foreach (['filter' => $searchModel->filter, 'q' => $searchModel->q, 'priority' => $searchModel->priority] as $name => $value): ?>
            <?php if ((string) $value !== ''): ?><?= Html::hiddenInput($name, $value) ?><?php endif; ?>
        <?php endforeach; ?>
        <?= Html::hiddenInput('scope', 'ids', ['data-gw-bulk-scope' => '']) ?>
        <div class="gw-bulk__status" role="status" aria-live="polite">
            <strong data-gw-bulk-count>0 selected</strong>
            <span class="gw-bulk__all" data-gw-bulk-page hidden>
                All <span data-gw-bulk-page-count></span> on this page are selected.
                <button type="button" class="btn btn-link gw-bulk__link" data-gw-bulk-select-all>Select all <?= number_format($dataProvider->getTotalCount()) ?> matching stories</button>
            </span>
            <span class="gw-bulk__all" data-gw-bulk-everything hidden>
                All <?= number_format($dataProvider->getTotalCount()) ?> matching stories are selected.
                <button type="button" class="btn btn-link gw-bulk__link" data-gw-bulk-clear>Clear selection</button>
            </span>
        </div>
        <div class="gw-bulk__actions">
            <?= Html::submitButton('<i class="bi bi-bookmark-check" aria-hidden="true"></i> Relevant', ['name' => 'do', 'value' => 'relevant', 'class' => 'btn btn-sm btn-outline-secondary']) ?>
            <?= Html::submitButton('<i class="bi bi-x-circle" aria-hidden="true"></i> Dismiss', ['name' => 'do', 'value' => 'dismiss', 'class' => 'btn btn-sm btn-outline-secondary',
                'data-gw-bulk-confirm' => 'Dismiss {n}? One comes back if it becomes critical or high.']) ?>
            <?php if (!in_array($searchModel->filter, ['new', 'urgent'], true)): ?>
                <?= Html::submitButton('<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reopen', ['name' => 'do', 'value' => 'reopen', 'class' => 'btn btn-sm btn-outline-secondary']) ?>
            <?php endif; ?>
        </div>
    <?= Html::endForm() ?>
<?php endif; ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'filterModel' => $searchModel,
    'emptyText' => $searchModel->filter === 'new' ? 'Nothing to triage. New stories appear here after each read.' : 'No stories match.',
    'columns' => array_merge($canBulk ? [[
        'class' => CheckboxColumn::class,
        'name' => 'ids',
        'headerOptions' => ['class' => 'gw-col-check'],
        'contentOptions' => ['class' => 'gw-col-check'],
        'header' => Html::tag('input', '', ['type' => 'checkbox', 'class' => 'form-check-input', 'data-gw-bulk-page-toggle' => '', 'aria-label' => 'Select every story on this page']),
        'checkboxOptions' => static fn(ThreatTriage $m) => ['form' => 'threat-bulk', 'class' => 'form-check-input', 'data-gw-bulk-row' => '', 'aria-label' => 'Select ' . $m->getReferenceValue()],
    ]] : [], [
        [
            'attribute' => 'priority',
            'format' => 'raw',
            'headerOptions' => ['class' => 'gw-threat-col-priority'],
            'contentOptions' => ['class' => 'gw-threat-col-priority'],
            'value' => static fn(ThreatTriage $m) => Badge::render('threat-priority', $m->priority, ucfirst($m->priority)),
            'filter' => ThreatTriage::priorityOptions(),
        ],
        [
            'label' => 'Story',
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-col-title gw-threat-col-story'],
            'value' => static function (ThreatTriage $m) use ($fmt) {
                $story = $m->story;
                $flags = [];
                $broad = StoryScorer::isBroad($story);
                $kev = array_filter($story->cves, static fn($c) => $c->getInKev());
                if ($kev && !$broad) {
                    $flags[] = Badge::render('threat-flag', 'kev', 'KEV', ['title' => 'In the CISA Known Exploited Vulnerabilities catalogue']);
                } elseif ($m->is_exploited) {
                    $flags[] = Badge::render('threat-flag', 'exploited', 'Exploited', ['title' => 'Coverage reports exploitation']);
                }
                if ($broad) {
                    $flags[] = Badge::render('threat-flag', 'roundup', 'Roundup', ['title' => 'Covers several events, so CVEs are not scored']);
                }
                foreach (array_slice($m->getMatchedTermList(), 0, $m->is_matched ? 3 : 0) as $term) {
                    $flags[] = Badge::render('threat-flag', 'watchlist', $term);
                }
                $cves = array_map(static fn($c) => $c->cve_id, array_slice($story->getSortedCves(), 0, 3));
                $extra = count($story->cves) - count($cves);
                $meta = array_filter([
                    $story->source_count > 1 ? sprintf('%d sources', $story->source_count) : ($story->articles[0]->source->name ?? null),
                    // The Latest column is hidden on phones
                    '<span class="gw-threat-when-inline">' . Html::encode($fmt->asRelativeTime($story->last_seen_at)) . '</span>',
                    $cves ? implode(', ', $cves) . ($extra > 0 ? " +$extra" : '') : null,
                ]);
                return '<div class="gw-threat-row">' . Html::a(Html::encode($story->title), $m->getViewUrl(), ['class' => 'gw-threat-row__title'])
                    . ($flags ? ' <span class="gw-threat-flags">' . implode(' ', $flags) . '</span>' : '')
                    . ($story->summary ? '<div class="gw-threat-row__summary">' . Html::encode(StringHelper::truncate($story->summary, 170)) . '</div>' : '')
                    . '<div class="gw-muted gw-intel-when">' . implode(' · ', array_map(static fn(string $part) => str_starts_with($part, '<span') ? $part : Html::encode($part), $meta)) . '</div></div>';
            },
        ],
        [
            'attribute' => 'last_seen_at',
            'label' => 'Latest',
            'format' => 'raw',
            'headerOptions' => ['class' => 'gw-threat-col-aux'],
            'contentOptions' => ['class' => 'gw-threat-col-aux'],
            'filterOptions' => ['class' => 'gw-threat-col-aux'],
            'value' => static fn(ThreatTriage $m) => $fmt->asRelativeTime($m->story->last_seen_at),
        ],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'enableSorting' => false,
            'filter' => false,
            'headerOptions' => ['class' => 'gw-threat-col-aux'],
            'contentOptions' => ['class' => 'gw-threat-col-aux'],
            'filterOptions' => ['class' => 'gw-threat-col-aux'],
            'value' => static fn(ThreatTriage $m) => Badge::render('threat-status', $m->status, $m->getStatusLabel()),
        ],
    ]),
]) ?>
