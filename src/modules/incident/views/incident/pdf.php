<?php
/**
 * PDF report of an incident (components/RecordPdf), written to be read by
 * management and non-technical staff: an interim report while the incident is
 * open, the final report once it is closed. Plain-English summary first, then
 * the response, controls and full timeline; the technical detail is an appendix.
 * @var yii\web\View $this
 * @var app\modules\incident\models\Incident $model
 */

use app\helpers\Badge;
use app\helpers\Dt;
use app\models\Attachment;
use app\modules\cloud\models\CloudAsset;
use app\modules\decision\models\Decision;
use app\modules\incident\components\IncidentWording;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
$open = $model->isOpen();
$this->params['pdf.register'] = $open ? 'Interim incident report' : 'Final incident report';
$this->params['pdf.badges'] = Badge::severity($model->severity) . ' ' . Badge::status('incident-status', $model->status, Incident::statusOptions());

$summary = $model->management_summary ?: $model->description;
$impacts = IncidentWording::impacts($model);
$controlsByRole = $model->getControlsByRole();
$timeline = $model->getTimeline()->with(['creator', 'attachment'])->orderBy(['entry_at' => SORT_ASC, 'id' => SORT_ASC])->all();
$notYet = $open ? 'Not yet' : null;
$glance = static fn(string $value, string $meaning = '') => '<div class="glance__value">' . Html::encode($value) . '</div>'
    . ($meaning !== '' ? '<div class="glance__meaning">' . Html::encode($meaning) . '</div>' : '');
/** Long lists of linked records are cut short; the register has them all */
$limit = 25;
?>
<?php if ($open): ?>
    <div class="notice notice--interim"><strong>Interim report</strong> &nbsp;This incident is still open. The report shows the position at the time printed above; details may change as the response continues.</div>
<?php else: ?>
    <div class="notice"><strong>Final report</strong> &nbsp;This incident was closed on <?= $fmt->asDatetime($model->closed_at) ?>.</div>
<?php endif; ?>

<h2>At a glance</h2>
<?= $this->render('@app/views/pdf/_text', ['label' => 'What happened', 'text' => $summary, 'highlight' => true]) ?>
<table class="glance">
    <tr><th>Where things stand</th><td><?= $glance($model->getStatusLabel(), IncidentWording::status($model->status)) ?></td></tr>
    <tr><th>How serious it is</th><td><?= $glance($model->getSeverityLabel(), IncidentWording::severity($model->severity)) ?></td></tr>
    <tr><th>What was affected</th><td>
        <?php if ($impacts): ?>
            <?php foreach ($impacts as $text): ?><div><?= Html::encode($text) ?></div><?php endforeach; ?>
        <?php else: ?>
            <span class="null"><?= $open ? 'Not assessed yet.' : 'No effect on the confidentiality, integrity or availability of information was recorded.' ?></span>
        <?php endif; ?>
    </td></tr>
    <tr><th>Personal data</th><td><?= Html::encode(IncidentWording::regulator($model)) ?></td></tr>
    <tr><th>Type of incident</th><td><?= Html::encode($model->category->name ?? '') ?></td></tr>
    <tr><th>Incident lead</th><td><?= $model->assignedTo ? Html::encode($model->assignedTo->full_name) : '<span class="null">Not assigned yet.</span>' ?></td></tr>
</table>

<h2>Key dates</h2>
<?= $this->render('@app/views/pdf/_facts', ['facts' => [
    'Started' => $model->occurred_at ? $fmt->asDatetime($model->occurred_at) : 'Not known',
    'Found' => $fmt->asDatetime($model->detected_at),
    'Reported' => $model->reported_at ? $fmt->asDatetime($model->reported_at) . ($model->reported_by ? ' by ' . Html::encode($model->reported_by) : '') : null,
    'Stopped from spreading' => $model->contained_at ? $fmt->asDatetime($model->contained_at) : $notYet,
    'Closed' => $model->closed_at ? $fmt->asDatetime($model->closed_at) : $notYet,
]]) ?>
<table class="scores scores--spaced">
    <tr>
        <?php foreach ([
            ['Time to detect', $model->getTimeToDetect(), 'From when it started to when it was found'],
            ['Time to contain', $model->getTimeToContain(), 'From when it was found to when it was stopped from spreading'],
            ['Time to close', $model->getTimeToClose(), 'From when it was found to when it was closed'],
        ] as [$label, $seconds, $explain]): ?>
            <td>
                <div class="scores__label"><?= $label ?></div>
                <div class="scores__value"><?= $seconds === null ? '—' : Html::encode(Dt::duration($seconds)) ?></div>
                <div class="scores__axes"><?= $explain ?></div>
            </td>
        <?php endforeach; ?>
    </tr>
</table>

<h2>What has been done</h2>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Stopping it from spreading (containment)', 'text' => $model->containment_actions, 'emptyText' => $open ? 'Not recorded yet.' : 'Not recorded.']) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Removing the cause and recovering', 'text' => $model->recovery_actions, 'emptyText' => $open ? 'Not recorded yet.' : 'Not recorded.']) ?>

<h2>Why it happened and what we learned</h2>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Why it happened (root cause)', 'text' => $model->root_cause,
    'emptyText' => $open ? 'Not determined yet: the investigation is continuing.' : 'Not recorded.']) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'What we learned', 'text' => $model->lessons_learned,
    'emptyText' => $open ? 'To be completed when the incident is reviewed.' : 'Not recorded.']) ?>

<h2>Our security controls</h2>
<?php if (array_filter($controlsByRole)): ?>
    <p class="muted">The safeguards in the Control Register that played a part in this incident.</p>
    <?php foreach ([Incident::CONTROL_DETECTED => 'What detected or contained it', Incident::CONTROL_FAILED => 'What failed or was bypassed',
                       Incident::CONTROL_RESPONSE => 'What is being added or improved'] as $role => $title): ?>
        <?= $this->render('@app/views/pdf/_records', ['title' => $title, 'models' => $controlsByRole[$role], 'emptyText' => 'None recorded.']) ?>
    <?php endforeach; ?>
<?php else: ?>
    <p class="null">No controls have been linked to this incident<?= $open ? ' yet' : '' ?>.</p>
<?php endif; ?>

<h2>Timeline</h2>
<?php if (!$timeline): ?>
    <p class="null">No timeline entries.</p>
<?php else: ?>
    <table class="list timeline">
        <thead><tr><th>When</th><th>Type</th><th>Entry</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($timeline as $entry): ?>
            <tr>
                <td class="timeline__when"><?= $fmt->asDatetime($entry->entry_at) ?></td>
                <td class="timeline__type"><?= Html::encode($entry->getTypeLabel()) ?></td>
                <td>
                    <?= nl2br(Html::encode($entry->body)) ?>
                    <?php if ($name = $entry->getFileName()): ?>
                        <div class="muted small">File: <?= Html::encode($name) ?><?= $entry->getIsFileRemoved() ? ' (since removed)' : '' ?></div>
                    <?php endif; ?>
                </td>
                <td class="timeline__by"><?= Html::encode($entry->creator->full_name ?? 'system') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div class="appendix">
<h2>Technical detail</h2>
<p class="muted">Reference information for the security team and auditors.</p>
<?php if ($model->management_summary): ?>
    <?= $this->render('@app/views/pdf/_text', ['label' => 'Technical description', 'text' => $model->description]) ?>
<?php endif; ?>
<?php if ($model->personal_data_involved): ?>
    <h3>Regulator</h3>
    <?= $this->render('@app/views/pdf/_facts', ['facts' => [
        'Notification required' => $model->regulator_notification_required ? 'Yes' : 'No',
        'Deadline' => $model->getRegulatorDeadline() ? $fmt->asDatetime($model->getRegulatorDeadline()->format('Y-m-d H:i:s')) : null,
        'Regulator notified' => $model->regulator_notified_at ? $fmt->asDatetime($model->regulator_notified_at) : null,
        'Regulator reference' => Html::encode((string) $model->regulator_reference),
        'Data subjects notified' => $model->subjects_notified_at ? $fmt->asDatetime($model->subjects_notified_at) : null,
    ]]) ?>
<?php endif; ?>
<?php foreach ([
    ['Affected devices', $model->devices, 'Criticality', static fn(Device $d) => Badge::criticality($d->criticality)],
    ['Affected cloud assets', $model->cloudAssets, 'Criticality', static fn(CloudAsset $a) => Badge::criticality($a->criticality)],
    ['ATT&CK techniques observed', $model->techniques, null, null],
    ['Related risks', $model->risks, 'Current score', static fn(Risk $r) => Badge::score($r->getCurrentScore())],
    ['Related decisions', $model->decisions, 'Status', static fn(Decision $d) => Badge::status('decision-status', $d->status, Decision::statusOptions())],
] as [$title, $models, $extraLabel, $extra]): ?>
    <?= $this->render('@app/views/pdf/_records', ['title' => $title, 'models' => array_slice($models, 0, $limit), 'extraLabel' => $extraLabel, 'extra' => $extra]) ?>
    <?php if (count($models) > $limit): ?>
        <p class="muted small">And <?= count($models) - $limit ?> more; the register lists them all.</p>
    <?php endif; ?>
<?php endforeach; ?>

<?= $this->render('@app/views/pdf/_attachments', ['attachments' => Attachment::listFor($model)]) ?>
</div>
