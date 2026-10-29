<?php
/**
 * PDF body for a supplier (components/RecordPdf).
 * @var yii\web\View $this
 * @var app\modules\supplier\models\Supplier $model
 */

use app\helpers\Badge;
use app\models\Attachment;
use app\models\RecordNote;
use app\models\RecordReview;
use app\modules\cloud\models\CloudAccount;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
$posture = $model->getPosture();
$flags = [];
if ($model->isReviewOverdue()) {
    $flags[] = Badge::flag('overdue', 'Review overdue');
}
if ($model->isRenewalDue()) {
    $flags[] = $model->getNoticeDeadline() < date('Y-m-d') ? Badge::flag('overdue', 'Notice deadline passed') : Badge::flag('due-soon', 'Notice period closing');
}
if ($model->getAssuranceDue()) {
    $flags[] = Badge::flag('expiring', 'Assurance to renew');
}
$this->params['pdf.register'] = 'Supplier Register';
$this->params['pdf.badges'] = Badge::status('supplier-status', $model->status, Supplier::statusOptions()) . ' '
    . Badge::tier($model->tier) . ' ' . Badge::posture($posture['state']) . ' ' . implode(' ', $flags);
$unknown = '<span class="null">Unknown</span>';
?>
<h2>Summary</h2>
<?= $this->render('@app/views/pdf/_facts', ['facts' => array_filter([
    'Tier' => Html::encode($model->getTierExplanation()),
    'Business owner' => Html::encode($model->owner->full_name ?? ''),
    'Category' => Html::encode($model->category->name ?? ''),
    'Legal entity' => Html::encode((string) $model->legal_name),
    'Website' => Html::encode((string) $model->website),
    'Criticality' => Badge::criticality($model->criticality),
    'Most sensitive data' => Badge::render('classification', $model->data_classification, Device::classificationOptions()[$model->data_classification] ?? null)
        . ($model->personal_data ? ' · personal data' : ''),
    'Access to our systems' => Html::encode($model->getAccessLabel()),
    'Data locations' => Html::encode((string) $model->data_locations),
    'Next review' => $model->next_review_date ? $fmt->asDate($model->next_review_date) : null,
])]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'What they provide', 'text' => $model->services]) ?>

<h2>Contract</h2>
<?= $this->render('@app/views/pdf/_facts', ['facts' => [
    'Contract start' => $model->contract_start ? $fmt->asDate($model->contract_start) : $unknown,
    'Renewal / end date' => $model->contract_end ? $fmt->asDate($model->contract_end) : $unknown,
    'Notice period' => $model->notice_days !== null ? Html::encode($model->notice_days . ' days')
        . ($model->contract_end ? ' (give notice by ' . $fmt->asDate($model->getNoticeDeadline()) . ')' : '') : $unknown,
    'Data processing agreement' => $model->dpa_status ? Html::encode(Supplier::dpaOptions()[$model->dpa_status]) : ($model->personal_data ? $unknown : 'Not needed'),
    'Breach notification' => $model->breach_notice_hours !== null ? Html::encode('Within ' . $model->breach_notice_hours . ' hours') : $unknown,
    'Right to audit' => $model->right_to_audit ? Html::encode(Supplier::yesNoOptions()[$model->right_to_audit]) : $unknown,
    'Security contact' => $model->security_contact ? Html::encode($model->security_contact) : $unknown,
]]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Exit plan', 'text' => $model->exit_plan]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Notes', 'text' => $model->notes]) ?>

<h2>Assurance</h2>
<?php if (!$model->assurances): ?>
    <p class="null">No assurance recorded.</p>
<?php else: ?>
    <table class="list">
        <thead><tr><th>Type</th><th>Details</th><th>Issued</th><th>Expires</th></tr></thead>
        <tbody>
        <?php foreach ($model->assurances as $a): ?>
            <tr>
                <td><?= Html::encode($a->getTypeLabel()) ?></td>
                <td><?= Html::encode((string) $a->description) ?><?= $a->evidence ? '<div class="muted small">' . Html::encode($a->evidence) . '</div>' : '' ?></td>
                <td class="nowrap"><?= $a->issued_on ? $fmt->asDate($a->issued_on) : '—' ?></td>
                <td class="nowrap"><?= $a->expires_on ? $fmt->asDate($a->expires_on) : 'No expiry' ?><?= $a->isExpired() ? ' ' . Badge::flag('expired', 'Expired') : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Supplier checks</h2>
<?php if (!$posture['items']): ?>
    <p class="null">No supplier check in force applies to this supplier.</p>
<?php else: ?>
    <table class="list">
        <thead><tr><th>Check</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($posture['items'] as $item): ?>
            <tr>
                <td><?= Html::encode($item['check']->reference . ' ' . $item['check']->title) ?><?= $item['detail'] ? '<div class="muted small">' . Html::encode($item['detail']) . '</div>' : '' ?></td>
                <td><?= Badge::checkResult($item['result']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?= $this->render('@app/views/pdf/_reviews', ['reviews' => RecordReview::listFor($model)]) ?>
<?= $this->render('@app/views/pdf/_notes', ['notes' => RecordNote::listFor($model, true)]) ?>

<h2>Related records</h2>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Cloud accounts', 'models' => $model->cloudAccounts, 'extraLabel' => 'Provider',
    'extra' => static fn(CloudAccount $a) => Html::encode($a->getProviderLabel())]) ?>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Related risks', 'models' => $model->risks, 'extraLabel' => 'Current score',
    'extra' => static fn(Risk $r) => Badge::score($r->getCurrentScore())]) ?>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Incidents involving them', 'models' => $model->incidents, 'extraLabel' => 'Severity',
    'extra' => static fn(Incident $i) => Badge::severity($i->severity)]) ?>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Related decisions', 'models' => $model->decisions, 'extraLabel' => 'Status',
    'extra' => static fn(Decision $d) => Badge::status('decision-status', $d->status, Decision::statusOptions())]) ?>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Controls that rely on them', 'models' => $model->controls, 'extraLabel' => 'Status',
    'extra' => static fn(Control $c) => Badge::status('control-status', $c->status, Control::statusOptions())]) ?>

<?= $this->render('@app/views/pdf/_attachments', ['attachments' => Attachment::listFor($model)]) ?>
