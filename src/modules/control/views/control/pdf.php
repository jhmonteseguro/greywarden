<?php
/**
 * PDF body for a control (components/RecordPdf).
 * @var yii\web\View $this
 * @var app\modules\control\models\Control $model
 */

use app\helpers\Badge;
use app\models\Attachment;
use app\models\RecordNote;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
$flags = [];
if ($testFlag = $model->testFlag()) {
    $flags[] = $testFlag;
}
if ($model->isTargetOverdue()) {
    $flags[] = Badge::flag('overdue', 'Past target date');
}
$this->params['pdf.register'] = 'Control Register';
$this->params['pdf.badges'] = Badge::status('control-status', $model->status, Control::statusOptions()) . ' '
    . Badge::effectiveness($model->effectiveness) . ' ' . implode(' ', $flags);

$tests = $model->tests;
$incidents = $model->getIncidentsByRole();
$frameworks = [];
foreach (FrameworkCatalogue::enabled() as $framework) {
    $frameworks[FrameworkCatalogue::shortLabel($framework)] = $model->requirementsOf($framework);
}
$frameworks['ATT&CK mitigations'] = $model->mitigations;
?>
<h2>Summary</h2>
<?= $this->render('@app/views/pdf/_facts', ['facts' => array_filter([
    'Owner' => Html::encode($model->owner->full_name ?? ''),
    'Type' => Html::encode($model->getTypeLabel() . ' · ' . $model->getNatureLabel()),
    'Target date' => $model->target_date ? $fmt->asDate($model->target_date) . ($model->isTargetOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '') : null,
    'Implemented on' => $model->implemented_on ? $fmt->asDate($model->implemented_on) : null,
    'Test frequency' => Html::encode($model->getFrequencyLabel() ?? 'No schedule'),
    'Last tested' => $model->last_tested_on ? $fmt->asDate($model->last_tested_on) : null,
    'Next test due' => $model->next_test_due ? $fmt->asDate($model->next_test_due) . ' ' . $model->testFlag() : null,
    'Device check' => $model->device_check ? Html::encode($model->getDeviceScopeLabel()) : false,
], static fn($v) => $v !== false)]) ?>
<?php if ($weak = $model->weaknesses()): ?>
    <p class="highlight">Risks relying on this control should be reviewed: it is <?= Html::encode(implode(', ', $weak)) ?>.</p>
<?php endif; ?>

<h2>Control</h2>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Description', 'text' => $model->description]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Implementation', 'text' => $model->implementation]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Evidence', 'text' => $model->evidence]) ?>
<?php if ($evidenceFiles = $model->evidenceAttachments): ?>
    <p class="muted small">Evidence files: <?= Html::encode(implode(', ', array_column($evidenceFiles, 'original_name'))) ?></p>
<?php endif; ?>

<?= $this->render('@app/views/pdf/_notes', ['notes' => RecordNote::listFor($model, true)]) ?>

<h2>Test history</h2>
<?php if (!$tests): ?>
    <p class="null">No tests recorded yet. Effectiveness stays "Not tested" until the first one.</p>
<?php else: ?>
    <table class="list">
        <thead><tr><th>Tested on</th><th>Result</th><th>Method &amp; findings</th><th>Tested by</th></tr></thead>
        <tbody>
        <?php foreach ($tests as $t): ?>
            <tr>
                <td class="nowrap"><?= $fmt->asDate($t->tested_on) ?></td>
                <td><?= Badge::effectiveness($t->result) ?></td>
                <td>
                    <?= $t->method ? '<strong>' . Html::encode($t->method) . '</strong><br>' : '' ?>
                    <?= $t->notes ? nl2br(Html::encode($t->notes)) : '' ?>
                    <?= $t->evidence ? '<div class="muted small">Evidence: ' . Html::encode($t->evidence) . '</div>' : '' ?>
                </td>
                <td><?= Html::encode($t->testedBy->full_name ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Frameworks</h2>
<?php foreach ($frameworks as $title => $items): ?>
    <?= $this->render('@app/views/pdf/_records', ['title' => $title, 'models' => $items, 'emptyText' => 'Not mapped.']) ?>
<?php endforeach; ?>

<h2>Related records</h2>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Risks it mitigates', 'models' => $model->risks, 'extraLabel' => 'Current score',
    'extra' => static fn(Risk $r) => Badge::score($r->getCurrentScore()) . ' ' . Badge::status('risk-status', $r->status, Risk::statusOptions())]) ?>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Related decisions', 'models' => $model->decisions, 'extraLabel' => 'Status',
    'extra' => static fn(Decision $d) => Badge::status('decision-status', $d->status, Decision::statusOptions())]) ?>
<?php foreach (Incident::controlRoleOptions() as $role => $label): ?>
    <?php if ($incidents[$role]): ?>
        <?= $this->render('@app/views/pdf/_records', ['title' => 'Incidents: ' . lcfirst($label), 'models' => $incidents[$role], 'extraLabel' => 'Severity',
            'extra' => static fn(Incident $i) => Badge::severity($i->severity)]) ?>
    <?php endif; ?>
<?php endforeach; ?>

<?= $this->render('@app/views/pdf/_attachments', ['attachments' => Attachment::listFor($model)]) ?>
