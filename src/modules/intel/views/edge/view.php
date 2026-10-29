<?php
/**
 * One edge finding: what to do, why it was flagged, and the traffic behind it.
 *
 * @var yii\web\View $this
 * @var app\modules\intel\models\EdgeFinding $model
 * @var array[] $recommendations EdgeAdvisor::recommendations()
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\intel\components\edge\EdgeCatalogue;
use app\modules\intel\components\edge\EdgeSettings;
use app\modules\intel\controllers\EdgeController;
use app\modules\intel\models\EdgeFinding;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;
use yii\helpers\StringHelper;

$this->title = $model->ip;
$this->params['breadcrumbs'][] = ['label' => 'Edge findings', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->reference;
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->reference) . '</span> '
    . Badge::render('edge-status', $model->status, $model->getStatusLabel()) . ' '
    . Badge::render('edge-priority', $model->priority, ucfirst($model->priority) . ' priority')
    . ($model->exposure ? ' ' . Badge::flag('missing', 'Possible exposure') : '');

$canManage = Rbac::canManage();
$buttons = [];
if ($url = $model->getIndicatorUrl()) {
    $buttons[] = Html::a('<i class="bi bi-radar"></i> Investigate', $url, ['class' => 'btn btn-outline-secondary gw-btn']);
}
if ($canManage) {
    if ($model->getIsOpen()) {
        $buttons[] = Html::a('<i class="bi bi-check2-circle"></i> Dismiss', ['dismiss', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn', 'data-method' => 'post']);
    } else {
        $buttons[] = Html::a('<i class="bi bi-arrow-counterclockwise"></i> Reopen', ['reopen', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn', 'data-method' => 'post']);
    }
}
$this->params['actions'] = implode("\n", $buttons);

$fmt = Yii::$app->formatter;
$profile = $model->getProfile();
$signals = $model->getSignalList();
$maxWeight = max(1, ...array_map(static fn($s) => (int) $s['weight'], $signals ?: [['weight' => 1]]));
$blockDays = EdgeSettings::blockDays();
$dayOptions = [];
foreach (array_unique(array_merge(EdgeController::BLOCK_DAYS, [$blockDays])) as $d) {
    $dayOptions[$d] = $d === 0 ? 'Permanently' : sprintf('For %d day%s', $d, $d === 1 ? '' : 's');
}
uksort($dayOptions, static fn($a, $b) => ($a ?: PHP_INT_MAX) <=> ($b ?: PHP_INT_MAX));

ob_start(); ?>
<?php if ($model->exposure && $model->status !== EdgeFinding::STATUS_IGNORED): ?>
    <div class="alert alert-danger gw-callout" role="alert">
        <i class="bi bi-exclamation-diamond" aria-hidden="true"></i>
        A path that should never be public answered with a success code. Check it from outside your network before anything else.
    </div>
<?php endif; ?>
<?php if ($model->note): ?>
    <div class="alert alert-secondary gw-callout" role="status"><i class="bi bi-sticky" aria-hidden="true"></i> <?= Html::encode($model->note) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel gw-edge-recs">
            <h2 class="gw-panel__title"><i class="bi bi-lightbulb" aria-hidden="true"></i> Recommended actions</h2>
            <?php if (!$recommendations): ?>
                <p class="gw-empty">Nothing further: the finding is <?= Html::encode(strtolower($model->getStatusLabel())) ?>.</p>
            <?php else: ?>
                <ol class="gw-edge-rec-list">
                    <?php foreach ($recommendations as $rec): ?>
                        <li class="gw-edge-rec gw-tone--<?= Html::encode($rec['tone']) ?>" data-rec="<?= Html::encode($rec['key']) ?>">
                            <i class="bi bi-<?= Html::encode($rec['icon']) ?> gw-edge-rec__icon" aria-hidden="true"></i>
                            <div class="gw-edge-rec__body">
                                <div class="gw-edge-rec__title"><?= Html::encode($rec['title']) ?></div>
                                <p class="gw-edge-rec__detail"><?= Html::encode($rec['detail']) ?></p>
                                <?php if ($canManage): ?>
                                    <div class="gw-edge-rec__actions">
                                        <?php if ($rec['action'] === 'block'): ?>
                                            <?= Html::beginForm(['block', 'id' => $model->id], 'post', ['class' => 'gw-edge-inline-form']) ?>
                                            <label class="visually-hidden" for="edge-block-days">Block length</label>
                                            <?= Html::dropDownList('days', $blockDays, $dayOptions, ['id' => 'edge-block-days', 'class' => 'form-select form-select-sm']) ?>
                                            <?= Html::submitButton('<i class="bi bi-slash-circle"></i> Block', ['class' => 'btn btn-danger btn-sm gw-btn']) ?>
                                            <?= Html::endForm() ?>
                                        <?php elseif ($rec['action'] === 'incident'): ?>
                                            <?= Html::a('<i class="bi bi-fire"></i> Raise incident', ['incident', 'id' => $model->id], ['class' => 'btn btn-danger btn-sm gw-btn', 'data-method' => 'post']) ?>
                                        <?php elseif ($rec['action'] === 'waf'): ?>
                                            <?= Html::a('See the suggested rule', ['index', '#' => 'waf'], ['class' => 'gw-panel__link']) ?>
                                        <?php elseif ($rec['action'] === 'asn'): ?>
                                            <?= Html::a('Block AS' . $rec['asn'], ['/intel/blocklist/create', 'value' => 'AS' . $rec['asn']], ['class' => 'gw-panel__link']) ?>
                                        <?php elseif (in_array($rec['action'], ['dismiss', 'ignore'], true)): ?>
                                            <?= Html::beginForm([$rec['action'], 'id' => $model->id], 'post', ['class' => 'gw-edge-inline-form']) ?>
                                            <label class="visually-hidden" for="edge-note-<?= $rec['action'] ?>">Note</label>
                                            <?= Html::textInput('note', '', ['id' => 'edge-note-' . $rec['action'], 'class' => 'form-control form-control-sm', 'maxlength' => 500, 'placeholder' => 'Note (optional)']) ?>
                                            <?= Html::submitButton($rec['action'] === 'ignore' ? 'Ignore' : 'Dismiss', ['class' => 'btn btn-outline-secondary btn-sm gw-btn']) ?>
                                            <?= Html::endForm() ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if ($canManage && $model->getIsOpen() && !in_array('ignore', array_column($recommendations, 'action'), true)): ?>
                <details class="gw-edge-more">
                    <summary>Ignore this address</summary>
                    <p class="gw-muted">For your own systems, monitors and partners: the address is still counted but never comes back to the queue. For whole networks, use the allowlist in Admin › Settings › Edge monitoring.</p>
                    <?= Html::beginForm(['ignore', 'id' => $model->id], 'post', ['class' => 'gw-edge-inline-form']) ?>
                    <label class="visually-hidden" for="edge-note-ignore-more">Note</label>
                    <?= Html::textInput('note', '', ['id' => 'edge-note-ignore-more', 'class' => 'form-control form-control-sm', 'maxlength' => 500, 'placeholder' => 'Why (e.g. our uptime monitor)']) ?>
                    <?= Html::submitButton('Ignore', ['class' => 'btn btn-outline-secondary btn-sm gw-btn']) ?>
                    <?= Html::endForm() ?>
                </details>
            <?php endif; ?>
        </section>

        <section class="gw-panel gw-intel-signals">
            <h2 class="gw-panel__title"><i class="bi bi-activity" aria-hidden="true"></i> Why it was flagged <span class="gw-panel__count"><?= count($signals) ?></span></h2>
            <?php if (!$signals): ?>
                <p class="gw-empty">No signals.</p>
            <?php else: ?>
                <ol class="gw-intel-signal-list">
                    <?php foreach ($signals as $s): ?>
                        <li class="gw-intel-signal gw-tone--<?= Html::encode($s['tone']) ?>">
                            <span class="gw-intel-signal__weight" title="Weight <?= (int) $s['weight'] ?>">+<?= (int) $s['weight'] ?></span>
                            <span class="gw-intel-signal__body">
                                <span class="gw-intel-signal__label"><?= Html::encode($s['label']) ?></span>
                                <span class="gw-intel-signal__detail"><?= Html::encode($s['detail']) ?></span>
                            </span>
                            <span class="gw-intel-signal__source">Edge</span>
                            <span class="gw-intel-signal__bar" aria-hidden="true"><span style="width: <?= round(100 * (int) $s['weight'] / $maxWeight) ?>%"></span></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p class="gw-intel-method">Weights combine like independent probabilities, as in the threat-intel score. Counts come from Cloudflare's sampled analytics, so they are estimates.</p>
            <?php endif; ?>
        </section>

        <section class="gw-panel gw-panel--flush">
            <div class="gw-panel__header gw-panel__header--flush">
                <h2 class="gw-panel__title"><i class="bi bi-signpost-split" aria-hidden="true"></i> Paths requested <span class="gw-panel__count"><?= count($profile['paths']) ?></span></h2>
            </div>
            <?php if (!$profile['paths']): ?>
                <p class="gw-empty">No error responses or sensitive paths.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table gw-table gw-edge-table">
                        <thead><tr><th scope="col">Path</th><th scope="col">Responses</th><th scope="col" class="text-end">Requests</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($profile['paths'], 0, 50, true) as $path => $row): ?>
                            <tr>
                                <td><code class="gw-edge-path"><?= Html::encode(StringHelper::truncate((string) $path, 120)) ?></code>
                                    <?= $row['group'] ? '<div class="gw-muted gw-intel-when">' . Html::encode(EdgeCatalogue::groupLabel($row['group'])) . '</div>' : '' ?></td>
                                <td><?php foreach ($row['statuses'] as $status => $n): ?>
                                        <span class="gw-edge-status gw-edge-status--<?= (int) $status < 300 ? 'ok' : ((int) $status < 400 ? 'redirect' : 'error') ?>"><?= (int) $status ?><?= $n > 1 ? ' ×' . (int) $n : '' ?></span>
                                    <?php endforeach; ?></td>
                                <td class="text-end gw-mono"><?= number_format((int) $row['count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($profile['paths']) > 50): ?><p class="gw-empty">Showing 50 of <?= count($profile['paths']) ?> paths.</p><?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($profile['payloads']): ?>
            <section class="gw-panel">
                <h2 class="gw-panel__title"><i class="bi bi-bug" aria-hidden="true"></i> Attack payloads <span class="gw-panel__count"><?= count($profile['payloads']) ?></span></h2>
                <ul class="gw-edge-payloads">
                    <?php foreach ($profile['payloads'] as $request => $row): ?>
                        <li><code class="gw-edge-path"><?= Html::encode(StringHelper::truncate((string) $request, 200)) ?></code>
                            <span class="gw-edge-status gw-edge-status--<?= (int) $row['status'] < 400 ? 'ok' : 'error' ?>"><?= (int) $row['status'] ?></span>
                            <span class="gw-muted"><?= Html::encode(implode(', ', $row['families'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Finding</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'ip', 'format' => 'raw', 'value' => Html::tag('span', Html::encode($model->ip), ['class' => 'gw-mono'])],
                    ['attribute' => 'asn', 'format' => 'raw', 'value' => $model->asn ? Html::a('AS' . $model->asn, ['/intel/investigate/asn', 'asn' => 'AS' . $model->asn], ['class' => 'gw-intel-ioc']) : null],
                    'country',
                    ['attribute' => 'score', 'value' => $model->score . ' / 100'],
                    ['label' => 'Got through', 'value' => number_format($model->getReached()) . ' of ' . number_format($model->requests) . ' requests'],
                    ['attribute' => 'stopped', 'value' => number_format($model->stopped)],
                    ['label' => 'Peak rate', 'value' => sprintf('%d a minute', $profile['peak_rpm'])],
                    ['attribute' => 'first_seen_at', 'format' => 'datetime'],
                    ['attribute' => 'last_seen_at', 'format' => 'datetime'],
                    'times_seen',
                    ['attribute' => 'blocklist_id', 'format' => 'raw', 'value' => $model->blocklistEntry ? Html::a(Html::encode($model->blocklistEntry->reference), $model->blocklistEntry->getViewUrl(), ['class' => 'gw-ref']) . ' ' . Badge::blockState($model->blocklistEntry->getState()) : null],
                    ['attribute' => 'incident_id', 'format' => 'raw', 'value' => $model->incident ? Html::a(Html::encode($model->incident->reference), $model->incident->getViewUrl(), ['class' => 'gw-ref']) . ' ' . Html::encode($model->incident->title) : null],
                    ['attribute' => 'resolved_at', 'format' => 'raw', 'visible' => $model->resolved_at !== null, 'value' => $fmt->asDatetime($model->resolved_at) . ' by ' . Html::encode($model->resolver->full_name ?? 'system')],
                ],
            ]) ?>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-radar" aria-hidden="true"></i> Intelligence</h2>
            <?php if ($model->intel_checked_at): ?>
                <dl class="gw-dl">
                    <dt>Assessment</dt><dd><?= Badge::intelRating($model->intel_rating, $model->intel_score) ?></dd>
                    <dt>Checked</dt><dd><?= $fmt->asDatetime($model->intel_checked_at) ?></dd>
                </dl>
            <?php else: ?>
                <p class="gw-empty">Not checked against the intel sources yet.</p>
            <?php endif; ?>
            <?php if ($url = $model->getIndicatorUrl()): ?>
                <p class="mb-0 mt-3"><?= Html::a('Open investigation', $url, ['class' => 'gw-panel__link']) ?></p>
            <?php endif; ?>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-person-badge" aria-hidden="true"></i> Clients</h2>
            <dl class="gw-dl gw-edge-agents">
                <?php foreach (array_slice($profile['agents'], 0, 8, true) as $agent => $n): ?>
                    <dt class="gw-mono"><?= number_format((int) $n) ?></dt><dd><?= $agent === '' ? '<span class="gw-muted">(no user agent)</span>' : Html::encode(StringHelper::truncate((string) $agent, 140)) ?></dd>
                <?php endforeach; ?>
                <?php if ($profile['methods']): ?>
                    <dt>Methods</dt><dd class="gw-mono"><?= Html::encode(implode(' · ', array_map(static fn($m, $n) => "$m $n", array_keys($profile['methods']), $profile['methods']))) ?></dd>
                <?php endif; ?>
            </dl>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">Record</h2>
            <?= $this->render('@app/views/partials/_record-meta', ['model' => $model]) ?>
        </section>
    </div>
</div>
<?php $details = ob_get_clean(); ?>

<?= Tabs::widget([
    'options' => ['class' => 'gw-tabs'],
    'tabContentOptions' => ['class' => 'gw-tab-content'],
    'items' => [
        ['label' => 'Details', 'content' => $details, 'options' => ['id' => 'tab-details']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => EdgeFinding::entityType(), 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ],
]) ?>
