<?php
/**
 * The body of a management report, rendered from its frozen figures. Shared by
 * the report page (inside .gw-report) and the PDF (inside views/pdf/document),
 * so it sticks to markup dompdf can lay out: tables and blocks, no flex/grid.
 *
 * @var yii\web\View $this
 * @var app\modules\report\models\Report $model
 * @var bool $pdf true in the PDF: references are plain text, not links
 */

use app\helpers\Badge;
use app\helpers\Dt;
use app\helpers\RiskMatrix;
use app\models\AuditLog;
use app\modules\control\models\Control;
use app\modules\incident\components\IncidentMetrics;
use app\modules\incident\models\Incident;
use app\modules\report\components\ReportBuilder;
use app\modules\supplier\models\Supplier;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
$d = $model->getFigures();
$period = $d['period'];
$e = static fn($text) => Html::encode((string) $text);
$ref = static fn(string $reference) => $pdf ? '<span class="ref">' . Html::encode($reference) . '</span>'
    : Html::a(Html::encode($reference), ['/search/index', 'q' => $reference], ['class' => 'ref']);
$date = static fn(?string $value) => $value ? $fmt->asDate($value) : '<span class="null">&mdash;</span>';
$none = static fn(string $text = 'None.') => '<p class="null">' . Html::encode($text) . '</p>';
$plural = static fn(int $n, string $one, ?string $many = null) => $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
$more = static fn(int $total, array $list) => $total > count($list) ? '<p class="muted small">And ' . ($total - count($list)) . ' more; the register lists them all.</p>' : '';
$vsText = ['start' => 'at the start', 'previous' => 'in the previous period', 'report' => 'in the last report'];
$sameText = ['start' => 'No change since the start', 'previous' => 'Same as the previous period', 'report' => 'Same as the last report'];
/** "▲ 2 from 5 at the start", toned good / bad by the direction that is better */
$delta = static function (array $tile) use ($vsText, $sameText): string {
    $value = $tile['value'];
    $compare = $tile['compare'];
    if ($value === null || $compare === null) {
        return '<span class="tile__delta tile__delta--none">' . ($tile['vs'] === 'report' ? 'No earlier report to compare' : 'No comparison') . '</span>';
    }
    $unit = $tile['unit'] ?? '';
    $diff = $value - $compare;
    if ($diff === 0) {
        return '<span class="tile__delta tile__delta--flat">' . $sameText[$tile['vs']] . '</span>';
    }
    $good = ($diff < 0) === ($tile['better'] === 'down');
    return '<span class="tile__delta tile__delta--' . ($good ? 'good' : 'bad') . '">' . ($diff > 0 ? '▲' : '▼') . ' ' . abs($diff) . ($unit === '%' ? ' pts' : '')
        . ' from ' . $compare . $unit . ' ' . $vsText[$tile['vs']] . '</span>';
};
/** Two columns side by side on screen; one after the other in the PDF, where a table cell cannot break across pages */
$split = $pdf ? [static fn(string $class = '') => '', '', ''] : [
    static fn(string $class = '') => '<table class="split"><tr><td' . ($class !== '' ? ' class="' . $class . '"' : '') . '>', '</td><td>', '</td></tr></table>',
];
$ratingCell = static fn(?int $score) => $score === null ? '<span class="null">&mdash;</span>' : Badge::score($score);
$controlStatuses = Control::statusOptions();
$severities = Incident::severityOptions();
$ratings = RiskMatrix::ratingOptions();
$entityLabels = AuditLog::entityLabels() + ['edge-finding' => 'Edge finding', 'requirement' => 'Framework requirement'];
?>
<div class="report">

<?php if (!$pdf): ?>
    <h2 class="report__first">At a glance</h2>
<?php else: ?>
    <h2>At a glance</h2>
<?php endif; ?>
<table class="rag-box rag-box--<?= $e($model->rag ?: 'none') ?>">
    <tr>
        <td class="rag-box__status">
            <div class="rag-box__label">Overall status</div>
            <div class="rag-box__value"><?= $model->rag ? $e($model->getRagLabel()) : 'Not set' ?></div>
        </td>
        <td class="rag-box__summary">
            <?php if (trim((string) $model->management_summary) !== ''): ?>
                <?= nl2br($e($model->management_summary)) ?>
            <?php else: ?>
                <span class="null">No management summary yet.</span>
            <?php endif; ?>
            <?php if ($model->rag): ?><div class="muted small rag-box__meaning"><?= $e(ReportBuilder::ragMeanings()[$model->rag] ?? '') ?></div><?php endif; ?>
        </td>
    </tr>
</table>

<table class="tiles">
    <?php foreach (array_chunk($d['headline'], 4) as $row): ?>
        <tr>
            <?php foreach ($row as $tile): ?>
                <td class="tile" data-key="<?= $e($tile['key']) ?>">
                    <div class="tile__label"><?= $e($tile['label']) ?></div>
                    <div class="tile__value"><?= $tile['value'] === null ? '—' : $e($tile['value'] . ($tile['unit'] ?? '')) ?></div>
                    <div class="tile__compare"><?= $delta($tile) ?></div>
                    <?php if (!empty($tile['note'])): ?><div class="tile__note"><?= $e($tile['note']) ?></div><?php endif; ?>
                </td>
            <?php endforeach; ?>
            <?php for ($i = count($row); $i < 4; $i++): ?><td class="tile tile--empty"></td><?php endfor; ?>
        </tr>
    <?php endforeach; ?>
</table>

<?php if ($d['rag_reasons']): ?>
    <h3>Points to note</h3>
    <ul class="points">
        <?php foreach ($d['rag_reasons'] as $reason): ?><li><?= $e(ucfirst($reason)) ?>.</li><?php endforeach; ?>
    </ul>
    <p class="muted small">From the figures, they suggest an overall status of <strong><?= $e(ReportBuilder::ragOptions()[$d['suggested_rag']]) ?></strong>.</p>
<?php else: ?>
    <p class="muted">Nothing in the figures needs attention: no risks above appetite, open incidents or overdue reviews and tests.</p>
<?php endif; ?>

<!-- ============================== Risks ============================== -->
<?php $r = $d['risks']; ?>
<h2 id="report-risks">1. Risk</h2>
<p class="muted">Risk appetite: residual scores up to <strong><?= (int) $d['appetite'] ?></strong> (<?= $e($d['appetite_rating']) ?>) are accepted; anything above is outside appetite. Scores are the residual score, or the inherent score where none is assessed.</p>
<table class="list list--numbers">
    <thead><tr><th>Open risks</th><?php foreach ($ratings as $label): ?><th class="num"><?= $e($label) ?></th><?php endforeach; ?><th class="num">Total</th><th class="num">Above appetite</th><th class="num">Above target</th></tr></thead>
    <tbody>
    <?php foreach (['start' => 'Start of period', 'end' => 'End of period'] as $key => $label): $s = $r[$key]; ?>
        <tr>
            <th scope="row"><?= $label ?></th>
            <?php foreach (array_keys($ratings) as $rating): ?><td class="num"><?= (int) ($s['ratings'][$rating] ?? 0) ?></td><?php endforeach; ?>
            <td class="num"><strong><?= (int) $s['open'] ?></strong></td>
            <td class="num"><?= (int) $s['above_appetite'] ?></td>
            <td class="num"><?= (int) $s['above_target'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p class="small muted"><?= $plural($r['added_count'], 'risk') ?> added, <?= $plural($r['closed_count'], 'risk') ?> closed, <?= count($r['rose']) ?> rose and <?= count($r['fell']) ?> fell during the period.</p>

<?= $split[0]('split__narrow') ?>
            <h3>Residual heatmap, end of period</h3>
            <table class="heatmap">
                <?php for ($l = RiskMatrix::MAX; $l >= RiskMatrix::MIN; $l--): ?>
                    <tr>
                        <th class="heatmap__axis"><?= $l ?></th>
                        <?php for ($i = RiskMatrix::MIN; $i <= RiskMatrix::MAX; $i++): $n = (int) ($r['heatmap'][$l][$i] ?? 0); ?>
                            <td class="heatmap__cell heatmap__cell--<?= $e(RiskMatrix::rating($l * $i)) ?><?= $n ? ' heatmap__cell--filled' : '' ?>"><?= $n ?: '' ?></td>
                        <?php endfor; ?>
                    </tr>
                <?php endfor; ?>
                <tr><th></th><?php for ($i = RiskMatrix::MIN; $i <= RiskMatrix::MAX; $i++): ?><th class="heatmap__axis"><?= $i ?></th><?php endfor; ?></tr>
            </table>
            <p class="small muted">Likelihood (up) by impact (across).</p>
<?= $split[1] ?>
            <h3>Top risks</h3>
            <?php if (!$r['top']): ?><?= $none('No open risks.') ?><?php else: ?>
            <table class="list">
                <thead><tr><th>Risk</th><th>Owner</th><th>Inherent</th><th>Residual</th><th>Target</th></tr></thead>
                <tbody>
                <?php foreach ($r['top'] as $row): ?>
                    <tr>
                        <td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?><div class="small muted"><?= $e($row['status']) ?><?= $row['treatment'] ? ' · ' . $e($row['treatment']) : '' ?></div></td>
                        <td><?= $e($row['owner'] ?? '—') ?></td>
                        <td><?= $ratingCell($row['inherent']) ?></td>
                        <td><?= $ratingCell($row['residual']) ?></td>
                        <td><?= $ratingCell($row['target']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
<?= $split[2] ?>

<h3>Risks above appetite</h3>
<?php if (!$r['above_appetite']): ?><?= $none('No open risk is above appetite.') ?><?php else: ?>
<table class="list">
    <thead><tr><th>Risk</th><th>Owner</th><th>Score</th><th class="num">Over appetite by</th><th>Target</th><th>Treatment</th></tr></thead>
    <tbody>
    <?php foreach ($r['above_appetite'] as $row): ?>
        <tr>
            <td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td>
            <td><?= $e($row['owner'] ?? '—') ?></td>
            <td><?= $ratingCell($row['score']) ?></td>
            <td class="num"><?= (int) $row['score'] - (int) $d['appetite'] ?></td>
            <td><?= $ratingCell($row['target']) ?><?= $row['target'] !== null && $row['target'] > $d['appetite'] ? '<div class="small muted">Target is above appetite too</div>' : '' ?></td>
            <td><?= $e($row['treatment'] ?? '—') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h3>Changes during the period</h3>
<?php $changes = array_merge(
    array_map(static fn($row) => $row + ['change' => 'Added', 'detail' => ''], $r['added']),
    array_map(static fn($row) => $row + ['change' => 'Rose', 'detail' => 'from ' . $row['from']], $r['rose']),
    array_map(static fn($row) => $row + ['change' => 'Fell', 'detail' => 'from ' . $row['from']], $r['fell']),
    array_map(static fn($row) => $row + ['change' => $row['how'], 'detail' => ''], $r['closed']),
); ?>
<?php if (!$changes): ?><?= $none('No risks were added, closed or re-scored.') ?><?php else: ?>
<table class="list">
    <thead><tr><th>Change</th><th>Risk</th><th>Score now</th><th>Owner</th></tr></thead>
    <tbody>
    <?php foreach ($changes as $row): ?>
        <tr>
            <td class="nowrap"><span class="change change--<?= $e(strtolower($row['change'])) ?>"><?= $e($row['change']) ?></span> <span class="small muted"><?= $e($row['detail']) ?></span></td>
            <td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td>
            <td><?= $ratingCell($row['score']) ?></td>
            <td><?= $e($row['owner'] ?? '—') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?= $split[0]() ?>
            <h3>Reviews completed (<?= (int) $r['reviews']['count'] ?>)</h3>
            <?php if (!$r['reviews']['list']): ?><?= $none('No risk reviews were recorded.') ?><?php else: ?>
            <table class="list">
                <tbody>
                <?php foreach ($r['reviews']['list'] as $row): ?>
                    <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?><div class="small muted"><?= $fmt->asDate($row['reviewed_on']) ?><?= $row['by'] ? ' by ' . $e($row['by']) : '' ?><?= $row['late'] ? ' · late' : '' ?></div></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
<?= $split[1] ?>
            <h3>Reviews overdue at the end (<?= (int) $r['end']['review_overdue'] ?>)</h3>
            <?php if (!$r['review_overdue']): ?><?= $none() ?><?php else: ?>
            <table class="list">
                <tbody>
                <?php foreach ($r['review_overdue'] as $row): ?>
                    <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?><div class="small muted">Due <?= $fmt->asDate($row['due']) ?><?= $row['owner'] ? ' · ' . $e($row['owner']) : '' ?></div></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        <?= $split[2] ?>
<?php if ($r['treatment_overdue']): ?>
    <h3>Treatment past its target date (<?= count($r['treatment_overdue']) ?>)</h3>
    <table class="list">
        <tbody>
        <?php foreach ($r['treatment_overdue'] as $row): ?>
            <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td><td class="nowrap">Target <?= $fmt->asDate($row['due']) ?></td><td><?= $e($row['owner'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- ============================== Incidents ============================== -->
<?php $i = $d['incidents']; ?>
<h2 id="report-incidents">2. Incidents</h2>
<table class="list list--numbers">
    <thead><tr><th>Severity</th><th class="num">Opened</th><th class="num">Closed</th><th class="num">Open at the end</th></tr></thead>
    <tbody>
    <?php foreach ($severities as $key => $label): $s = $i['by_severity'][$key] ?? ['opened' => 0, 'closed' => 0, 'open_end' => 0]; ?>
        <tr><th scope="row"><?= Badge::severity($key) ?></th><td class="num"><?= (int) $s['opened'] ?></td><td class="num"><?= (int) $s['closed'] ?></td><td class="num"><?= (int) $s['open_end'] ?></td></tr>
    <?php endforeach; ?>
    <tr class="total"><th scope="row">Total</th><td class="num"><?= (int) $i['opened'] ?></td><td class="num"><?= (int) $i['closed'] ?></td><td class="num"><?= (int) $i['open_end'] ?></td></tr>
    </tbody>
</table>
<p class="small muted">Previous period: <?= $plural((int) $i['opened_previous'], 'incident') ?> opened, <?= (int) $i['closed_previous'] ?> closed. Open at the start: <?= (int) $i['open_start'] ?>.
    <?php if ($i['personal_data']): ?><strong><?= $plural((int) $i['personal_data'], 'incident') ?> opened in the period involved personal data.</strong><?php endif; ?>
    <?php if ($i['regulator']['required']): ?>Regulator notifications: <?= (int) $i['regulator']['required'] ?> required, <?= (int) $i['regulator'][IncidentMetrics::STATE_MET] ?> on time,
        <?= (int) $i['regulator'][IncidentMetrics::STATE_LATE] ?> late, <?= (int) $i['regulator'][IncidentMetrics::STATE_OVERDUE] ?> overdue, <?= (int) $i['regulator'][IncidentMetrics::STATE_PENDING] ?> not yet due.<?php endif; ?>
</p>

<?php if ($i['times']['count']): ?>
<table class="scores">
    <tr>
        <?php foreach ([['Time to detect', 'detect', 'From when it started to when it was found'], ['Time to contain', 'contain', 'From when it was found to when it was stopped from spreading'],
                           ['Time to close', 'close', 'From when it was found to when it was closed']] as [$label, $key, $explain]): ?>
            <td>
                <div class="scores__label">Average <?= strtolower($label) ?></div>
                <div class="scores__value"><?= $i['times'][$key] === null ? '—' : $e(Dt::duration($i['times'][$key])) ?></div>
                <div class="scores__axes"><?= $explain ?></div>
            </td>
        <?php endforeach; ?>
    </tr>
</table>
<p class="small muted">Averages over the <?= $plural((int) $i['times']['count'], 'incident') ?> closed in the period.</p>
<?php endif; ?>

<h3>Incidents in the period</h3>
<?php if (!$i['list']): ?><?= $none('No incidents were open during the period.') ?><?php else: ?>
<table class="list">
    <thead><tr><th>Incident</th><th>Severity</th><th>Found</th><th>Where it stands</th></tr></thead>
    <tbody>
    <?php foreach ($i['list'] as $row): ?>
        <tr>
            <td><?= $ref($row['ref']) ?> <strong><?= $e($row['title']) ?></strong>
                <?php if ($row['summary']): ?><div class="small"><?= $e($row['summary']) ?></div><?php endif; ?>
                <?php if ($row['personal_data']): ?><div class="small"><strong>Personal data involved.</strong></div><?php endif; ?></td>
            <td><?= Badge::severity($row['severity']) ?></td>
            <td class="nowrap"><?= $fmt->asDate($row['detected_at']) ?></td>
            <td><?= $row['open_end'] ? 'Open: ' . $e(Incident::statusOptions()[$row['status']] ?? $row['status']) : 'Closed ' . ($row['closed_at'] ? $fmt->asDate($row['closed_at']) : '') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?= $more((int) $i['list_count'], $i['list']) ?>
<?php endif; ?>

<?php if ($i['lessons']): ?>
    <h3>Lessons learned</h3>
    <table class="list">
        <tbody>
        <?php foreach ($i['lessons'] as $row): ?>
            <tr><td class="ref"><?= $ref($row['ref']) ?></td><td><?= nl2br($e($row['text'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php if ($i['failed_controls']): ?>
    <h3>Controls that failed or were bypassed</h3>
    <table class="list">
        <tbody>
        <?php foreach ($i['failed_controls'] as $row): ?>
            <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td><td class="nowrap"><?= implode(', ', array_map($ref, $row['incidents'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- ============================== Controls ============================== -->
<?php $c = $d['controls']; ?>
<h2 id="report-controls">3. Controls and compliance</h2>
<table class="list list--numbers">
    <thead><tr><th>Controls</th><?php foreach ($controlStatuses as $label): ?><th class="num"><?= $e($label) ?></th><?php endforeach; ?><th class="num">Rated effective</th><th class="num">Tests overdue</th><th class="num">Past target date</th></tr></thead>
    <tbody>
    <?php foreach (['start' => 'Start of period', 'end' => 'End of period'] as $key => $label): $s = $c[$key]; ?>
        <tr>
            <th scope="row"><?= $label ?></th>
            <?php foreach (array_keys($controlStatuses) as $status): ?><td class="num"><?= (int) ($s['statuses'][$status] ?? 0) ?></td><?php endforeach; ?>
            <td class="num"><?= $s['effective_pct'] === null ? '—' : (int) $s['effective_pct'] . '%' ?> <span class="small muted">(<?= (int) $s['effective'] ?> of <?= (int) $s['in_force'] ?>)</span></td>
            <td class="num"><?= (int) $s['test_overdue'] ?></td>
            <td class="num"><?= (int) $s['target_overdue'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p class="small muted">"Rated effective" counts controls in force (implementing or implemented) whose latest test found them effective.
    <?= (int) $c['end']['weak'] ?> rated partly effective or ineffective, <?= (int) $c['end']['not_tested'] ?> not tested yet.</p>

<?php $tests = $c['tests']; ?>
<h3>Control tests in the period (<?= (int) $tests['count'] ?>; <?= (int) $tests['previous'] ?> in the previous period)</h3>
<?php if (!$tests['count']): ?><?= $none('No control tests were recorded.') ?><?php else: ?>
<p><?php foreach ($tests['results'] as $result => $n): ?><?= Badge::effectiveness($result) ?> <?= (int) $n ?> &nbsp; <?php endforeach; ?></p>
<table class="list">
    <thead><tr><th>Tested</th><th>Control</th><th>Result</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($tests['list'] as $row): ?>
        <tr><td class="nowrap"><?= $fmt->asDate($row['tested_on']) ?></td><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td><td><?= Badge::effectiveness($row['result']) ?></td><td><?= $e($row['by'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?= $more((int) $tests['count'], $tests['list']) ?>
<?php endif; ?>

<?php if ($c['changes']): ?>
    <h3>Status changes (<?= (int) $c['changes_count'] ?>)</h3>
    <table class="list">
        <tbody>
        <?php foreach ($c['changes'] as $row): ?>
            <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td><td class="nowrap"><?= $row['from'] ? $e($row['from']) . ' → ' : 'New: ' ?><strong><?= $e($row['to']) ?></strong></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?= $more((int) $c['changes_count'], $c['changes']) ?>
<?php endif; ?>

<?= $split[0]() ?>
            <h3>Tests overdue at the end (<?= (int) $c['end']['test_overdue'] ?>)</h3>
            <?php if (!$c['test_overdue']): ?><?= $none() ?><?php else: ?>
            <table class="list"><tbody>
                <?php foreach ($c['test_overdue'] as $row): ?>
                    <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?><div class="small muted">Due <?= $fmt->asDate($row['due']) ?><?= $row['owner'] ? ' · ' . $e($row['owner']) : '' ?></div></td></tr>
                <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
<?= $split[1] ?>
            <h3>Planned controls past their target date (<?= (int) $c['end']['target_overdue'] ?>)</h3>
            <?php if (!$c['target_overdue']): ?><?= $none() ?><?php else: ?>
            <table class="list"><tbody>
                <?php foreach ($c['target_overdue'] as $row): ?>
                    <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?><div class="small muted"><?= $e($row['status']) ?> · target <?= $fmt->asDate($row['due']) ?><?= $row['owner'] ? ' · ' . $e($row['owner']) : '' ?></div></td></tr>
                <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        <?= $split[2] ?>

<?php if ($c['frameworks']): ?>
    <h3>Framework coverage (as at generation)</h3>
    <table class="list list--numbers">
        <thead><tr><th>Framework</th><th class="num">Met</th><th class="num">Partly met</th><th class="num">Planned</th><th class="num">Gaps</th><th class="num">Coverage</th><th class="num">Last report</th></tr></thead>
        <tbody>
        <?php foreach ($c['frameworks'] as $fw): ?>
            <tr>
                <th scope="row"><?= $e($fw['title']) ?></th>
                <td class="num"><?= (int) $fw['met'] ?></td><td class="num"><?= (int) $fw['partial'] ?></td><td class="num"><?= (int) $fw['planned'] ?></td><td class="num"><?= (int) $fw['gap'] ?></td>
                <td class="num"><strong><?= (int) $fw['percent'] ?>%</strong> <span class="small muted">of <?= (int) $fw['applicable'] ?></span></td>
                <td class="num"><?= $fw['previous'] === null ? '<span class="null">&mdash;</span>' : (int) $fw['previous'] . '%' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- ============================== Decisions ============================== -->
<?php $dec = $d['decisions']; ?>
<h2 id="report-decisions">4. Decisions</h2>
<h3>Risk acceptances (<?= count($r['accepted']) ?>)</h3>
<?php if (!$r['accepted']): ?><?= $none('No risks were accepted in the period.') ?><?php else: ?>
<p class="muted">Risks the organisation chose to live with during the period, either without treatment (Accepted) or as the residual risk left after treatment (Treated). Management should be aware of, and agree with, each of these.</p>
<table class="list">
    <thead><tr><th>Risk</th><th>Score</th><th>Accepted by</th><th>Why</th></tr></thead>
    <tbody>
    <?php foreach ($r['accepted'] as $row): ?>
        <tr>
            <td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?><div class="small muted"><?= $e($row['status']) ?><?= $row['treatment'] ? ' · ' . $e($row['treatment']) : '' ?></div></td>
            <td><?= $ratingCell($row['score']) ?></td>
            <td><?= $e($row['accepted_by'] ?? '—') ?><?= $row['accepted_at'] ? '<div class="small muted">' . $fmt->asDate($row['accepted_at']) . '</div>' : '' ?></td>
            <td class="small"><?= $row['justification'] ? $e($row['justification']) : '<span class="null">No justification recorded.</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h3>Decisions made (<?= (int) $dec['made_count'] ?>)</h3>
<?php if (!$dec['made']): ?><?= $none('No decisions were logged in the period.') ?><?php else: ?>
<table class="list">
    <thead><tr><th>Date</th><th>Decision</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($dec['made'] as $row): ?>
        <tr>
            <td class="nowrap"><?= $fmt->asDate($row['date']) ?></td>
            <td><?= $ref($row['ref']) ?> <strong><?= $e($row['title']) ?></strong><?= $row['decision'] ? '<div class="small">' . $e($row['decision']) . '</div>' : '' ?>
                <?= $row['makers'] ? '<div class="small muted">Decided by ' . $e($row['makers']) . '</div>' : '' ?></td>
            <td><?= $e($row['status']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?= $more((int) $dec['made_count'], $dec['made']) ?>
<?php endif; ?>
<p class="small muted"><?= $plural((int) $dec['proposed'], 'decision') ?> awaiting approval at the end; <?= $plural((int) $dec['reviews']['count'], 'decision review') ?> completed in the period.</p>
<?php if ($dec['review_overdue']): ?>
    <h3>Decision reviews overdue at the end (<?= (int) $dec['review_overdue_count'] ?>)</h3>
    <table class="list"><tbody>
        <?php foreach ($dec['review_overdue'] as $row): ?>
            <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td><td class="nowrap">Due <?= $fmt->asDate($row['due']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
<?php endif; ?>

<!-- ============================== Assets ============================== -->
<?php $a = $d['assets']; ?>
<?php if ($a['devices'] || $a['cloud'] || $a['edge']): ?>
<h2 id="report-assets">5. Assets and exposure</h2>
<p class="muted small">As at <?= $fmt->asDate(min($period['end'], substr($d['generated_at'], 0, 10))) ?>: check results are kept as they are now, so these compare with the last report rather than the start of the period.</p>
<?php if ($a['devices']): $dv = $a['devices']; ?>
    <h3>Devices</h3>
    <?= $this->render('@app/views/pdf/_facts', ['facts' => [
        'In service' => (int) $dv['total'] . ($dv['byod'] ? ' <span class="muted small">(' . (int) $dv['byod'] . ' personal / BYOD)</span>' : ''),
        'Passing every check' => ($dv['pct'] === null ? '—' : (int) $dv['pct'] . '%') . ($dv['previous_pct'] !== null ? ' <span class="muted small">(' . (int) $dv['previous_pct'] . '% in the last report)</span>' : ''),
        'Failing a check' => (string) (int) $dv['failing'],
        'Not assessed' => (string) (int) $dv['not_assessed'],
        'Not seen recently' => (string) (int) $dv['stale'],
        'Operating system out of support' => (string) (int) $dv['past_eol'],
    ]]) ?>
<?php endif; ?>
<?php if ($a['cloud']): $cl = $a['cloud']; ?>
    <h3>Cloud</h3>
    <?= $this->render('@app/views/pdf/_facts', ['facts' => [
        'Accounts in service' => (string) (int) $cl['accounts'],
        'Assets in service' => (string) (int) $cl['assets'],
        'Internet-facing assets' => (string) (int) $cl['internet_facing'],
        'Passing every check' => ($cl['pct'] === null ? '—' : (int) $cl['pct'] . '%') . ($cl['previous_pct'] !== null ? ' <span class="muted small">(' . (int) $cl['previous_pct'] . '% in the last report)</span>' : ''),
        'Failing a check' => (string) (int) $cl['failing'],
        'Not assessed' => (string) (int) $cl['not_assessed'],
    ]]) ?>
<?php endif; ?>
<?php if ($a['edge']): $ed = $a['edge']; ?>
    <h3>Edge (internet traffic to our sites)</h3>
    <?= $this->render('@app/views/pdf/_facts', ['facts' => [
        'Suspicious sources found' => (int) $ed['findings'] . ($ed['high'] ? ' <span class="muted small">(' . (int) $ed['high'] . ' high priority)</span>' : ''),
        'Blocked' => (string) (int) $ed['blocked'],
        'Raised as incidents' => (string) (int) $ed['escalated'],
        'Dismissed' => (string) (int) $ed['dismissed'],
        'Still to look at' => (string) (int) $ed['open'],
        'Blocklist entries added' => (string) (int) $ed['blocks_added'],
    ]]) ?>
    <?php if ($ed['top_asns']): ?>
        <p class="small">Networks most often behind them: <?= implode(', ', array_map(static fn($x) => 'AS' . (int) $x['asn'] . ' (' . (int) $x['count'] . ')', $ed['top_asns'])) ?>.</p>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<!-- ============================== Suppliers ============================== -->
<?php if ($sp = $d['suppliers']): ?>
<h2 id="report-suppliers">6. Suppliers</h2>
<?= $this->render('@app/views/pdf/_facts', ['facts' => [
    'In service' => (int) $sp['in_service'] . ' <span class="muted small">(' . implode(', ', array_map(static fn($t, $n) => 'Tier ' . $t . ': ' . $n, array_keys($sp['tiers']), $sp['tiers'])) . ')</span>',
    'Passing every check' => ($sp['pct'] === null ? '—' : (int) $sp['pct'] . '%') . ($sp['previous_pct'] !== null ? ' <span class="muted small">(' . (int) $sp['previous_pct'] . '% in the last report)</span>' : ''),
    'Failing a check' => (string) (int) $sp['failing'],
    'Reviews overdue' => (string) (int) $sp['review_overdue'],
    'Being onboarded' => (string) (int) $sp['onboarding'],
]]) ?>
<h3>Critical and important suppliers without current assurance</h3>
<?php if (!$sp['assurance']): ?><?= $none('Every tier 1 and 2 supplier has current assurance.') ?><?php else: ?>
<table class="list"><tbody>
    <?php foreach ($sp['assurance'] as $row): ?>
        <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td><td><?= Badge::tier($row['tier']) ?></td><td><?= $e($row['issue']) ?></td></tr>
    <?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php if ($sp['dpa']): ?>
    <h3>Personal data without a signed data processing agreement</h3>
    <table class="list"><tbody>
        <?php foreach ($sp['dpa'] as $row): ?>
            <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td><td><?= Badge::tier($row['tier']) ?></td><td><?= $e($row['dpa']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
<?php endif; ?>
<?php if ($sp['contracts']): ?>
    <h3>Contracts ending in the 90 days after the period</h3>
    <table class="list"><tbody>
        <?php foreach ($sp['contracts'] as $row): ?>
            <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td><td class="nowrap">Ends <?= $date($row['ends']) ?></td><td class="nowrap">Notice by <?= $date($row['notice_by']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
<?php endif; ?>
<?php if ($sp['incidents']): ?>
    <h3>Incidents involving suppliers</h3>
    <table class="list"><tbody>
        <?php foreach ($sp['incidents'] as $row): ?>
            <tr><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td><td><?= $ref($row['incident']) ?> <?= $e($row['incident_title']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
<?php endif; ?>
<?php endif; ?>

<!-- ============================== Ahead ============================== -->
<?php $ah = $d['ahead']; ?>
<h2 id="report-ahead">Looking ahead: the next <?= (int) $period['lookahead_days'] ?> days</h2>
<p class="muted">Due between <?= $fmt->asDate($ah['from']) ?> and <?= $fmt->asDate($ah['to']) ?>.</p>
<?php if (!$ah['list']): ?><?= $none('Nothing falls due.') ?><?php else: ?>
<table class="list">
    <thead><tr><th>Due</th><th>What</th><th>Record</th><th>Owner</th></tr></thead>
    <tbody>
    <?php foreach ($ah['list'] as $row): ?>
        <tr><td class="nowrap"><?= $fmt->asDate($row['due']) ?></td><td class="nowrap"><?= $e($row['kind']) ?></td><td><?= $ref($row['ref']) ?> <?= $e($row['title']) ?></td><td><?= $e($row['owner'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?= $more((int) $ah['count'], $ah['list']) ?>
<?php endif; ?>

<!-- ============================== Appendix ============================== -->
<div class="appendix">
<h2 id="report-appendix">Appendix</h2>
<h3>Activity in the registers</h3>
<?php $act = $d['activity']; ?>
<?php if (!$act['total']): ?><?= $none('No changes were recorded in the period.') ?><?php else: ?>
<?= $split[0]() ?>
            <table class="list list--numbers">
                <thead><tr><th>Register</th><th class="num">Changes</th></tr></thead>
                <tbody>
                <?php foreach ($act['by_type'] as $type => $n): ?><tr><td><?= $e($entityLabels[$type] ?? $type) ?></td><td class="num"><?= (int) $n ?></td></tr><?php endforeach; ?>
                <tr class="total"><td>Total</td><td class="num"><?= (int) $act['total'] ?></td></tr>
                </tbody>
            </table>
<?= $split[1] ?>
            <table class="list list--numbers">
                <thead><tr><th>Who</th><th class="num">Changes</th></tr></thead>
                <tbody>
                <?php foreach ($act['by_user'] as $row): ?><tr><td><?= $e($row['name']) ?></td><td class="num"><?= (int) $row['count'] ?></td></tr><?php endforeach; ?>
                </tbody>
            </table>
        <?= $split[2] ?>
<?php endif; ?>

<h3>How the figures are worked out</h3>
<ul class="small method">
    <li>The period runs from <?= $fmt->asDate($period['start']) ?> to <?= $fmt->asDate($period['end']) ?> inclusive; the previous period, for comparison, from <?= $fmt->asDate($period['previous_start']) ?> to <?= $fmt->asDate($period['previous_end']) ?>.</li>
    <li>Risks, controls, decisions and suppliers at the start and end of the period are rebuilt from the audit trail, so later edits do not change them. Archived records count as removed.</li>
    <li>Incidents count by when they were found and closed; control tests and reviews by the date recorded on them.</li>
    <li>Risk appetite is set in Admin › Settings. A risk is above target when its residual score is higher than the target score set on it.</li>
    <li>Device, cloud and supplier check results and framework coverage are only kept as they are now, so they show the position when the figures were generated and compare with the last report<?= $d['previous_report'] ? ' (' . $e($d['previous_report']) . ')' : '' ?>.</li>
    <li>The figures were generated on <?= $fmt->asDatetime($d['generated_at']) ?> and are frozen with the report. Long lists show the first <?= ReportBuilder::LIST_LIMIT ?> entries.</li>
</ul>

<h3>Sign-off</h3>
<?php if ($model->isApproved()): ?>
    <?= $this->render('@app/views/pdf/_facts', ['facts' => [
        'Approved by' => $e($model->approvedBy->full_name ?? '—'),
        'Approved on' => $fmt->asDatetime($model->approved_at),
        'Note' => $model->approval_note ? nl2br($e($model->approval_note)) : null,
    ]]) ?>
<?php else: ?>
    <p class="null">Draft: not approved yet.</p>
<?php endif; ?>
</div>
</div>
