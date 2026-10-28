<?php
/**
 * PDF body for a risk (components/RecordPdf).
 * @var yii\web\View $this
 * @var app\modules\risk\models\Risk $model
 */

use app\helpers\Badge;
use app\helpers\RiskMatrix;
use app\models\Attachment;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
$flags = [];
if ($model->isReviewOverdue()) {
    $flags[] = Badge::flag('overdue', 'Review overdue');
}
if ($model->isTreatmentOverdue()) {
    $flags[] = Badge::flag('overdue', 'Treatment overdue');
}
$this->params['pdf.register'] = 'Risk Register';
$this->params['pdf.badges'] = Badge::status('risk-status', $model->status, Risk::statusOptions()) . ' ' . implode(' ', $flags);

$scores = [
    'Inherent' => [$model->inherent_score, $model->inherent_likelihood, $model->inherent_impact, 'Before controls', 'Not assessed'],
    'Residual (current)' => [$model->residual_score, $model->residual_likelihood, $model->residual_impact, 'With controls in place', 'Not assessed'],
    'Target' => [$model->target_score, $model->target_likelihood, $model->target_impact, 'After treatment', 'Not set'],
];
$bands = [];
$min = 1;
foreach (RiskMatrix::bands() as $band) {
    $bands[] = sprintf('%s %d–%d', $band['label'], $min, $band['max']);
    $min = $band['max'] + 1;
}
$likelihood = RiskMatrix::likelihoodLabels();
$impact = RiskMatrix::impactLabels();
$controlStatus = static fn(Control $c) => Badge::status('control-status', $c->status, Control::statusOptions()) . ' ' . Badge::effectiveness($c->effectiveness);
?>
<h2>Assessment</h2>
<table class="scores">
    <tr>
        <?php foreach ($scores as $label => [$score, $l, $i, $hint, $none]): $rating = RiskMatrix::rating($score ? (int) $score : null); ?>
            <td>
                <div class="scores__label"><?= Html::encode($label) ?></div>
                <?php if ($score): ?>
                    <div class="scores__value rating--<?= Html::encode($rating) ?>"><?= (int) $score ?>
                        <span class="scores__rating"><?= Html::encode(RiskMatrix::ratingLabel($rating)) ?></span></div>
                    <div class="scores__axes">Likelihood <?= (int) $l ?> <?= Html::encode($likelihood[$l] ?? '') ?> × Impact <?= (int) $i ?> <?= Html::encode($impact[$i] ?? '') ?></div>
                <?php else: ?>
                    <div class="scores__value null">—</div>
                    <div class="scores__axes"><?= Html::encode($none) ?></div>
                <?php endif; ?>
                <div class="scores__axes muted"><?= Html::encode($hint) ?></div>
            </td>
        <?php endforeach; ?>
    </tr>
</table>
<p class="muted small">5 × 5 scale, score = likelihood × impact. Ratings: <?= Html::encode(implode(', ', $bands)) ?>.</p>

<h2>Risk details</h2>
<?= $this->render('@app/views/pdf/_facts', ['facts' => [
    'Category' => Html::encode($model->category->name ?? ''),
    'Owner' => Html::encode($model->owner->full_name ?? ''),
    'Scope' => Html::encode((string) $model->scope),
    'Identified' => $fmt->asDate($model->date_identified),
]]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'ATT&CK techniques',
    'text' => implode("\n", array_map(static fn($t) => $t->external_id . '  ' . $t->name, $model->techniques)) ?: null]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Description', 'text' => $model->description]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Threat source', 'text' => $model->threat_source]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Vulnerability', 'text' => $model->vulnerability]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Existing controls', 'text' => $model->existing_controls]) ?>

<h2>Treatment</h2>
<?= $this->render('@app/views/pdf/_facts', ['facts' => [
    'Treatment' => Html::encode((string) $model->getTreatmentLabel()),
    'Target date' => $model->target_treatment_date ? $fmt->asDate($model->target_treatment_date) . ($model->isTreatmentOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '') : null,
    'Next review' => $model->next_review_date ? $fmt->asDate($model->next_review_date) . ($model->isReviewOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '') : null,
]]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Treatment plan / planned controls', 'text' => $model->treatment_plan, 'highlight' => true]) ?>

<?php if ($model->status === Risk::STATUS_ACCEPTED || $model->acceptance_justification): ?>
    <h2>Risk acceptance</h2>
    <?= $this->render('@app/views/pdf/_facts', ['facts' => [
        'Accepted by' => Html::encode($model->acceptedBy->full_name ?? ''),
        'Accepted on' => $model->accepted_at ? $fmt->asDate($model->accepted_at) : null,
    ]]) ?>
    <?= $this->render('@app/views/pdf/_text', ['label' => 'Acceptance justification', 'text' => $model->acceptance_justification]) ?>
<?php endif; ?>

<h2>Controls and related records</h2>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Controls', 'models' => $model->controls, 'extraLabel' => 'Status', 'extra' => $controlStatus,
    'emptyText' => 'No controls from the Control Register are linked.']) ?>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Related decisions', 'models' => $model->decisions, 'extraLabel' => 'Status',
    'extra' => static fn(Decision $d) => Badge::status('decision-status', $d->status, Decision::statusOptions())]) ?>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Related incidents', 'models' => $model->incidents, 'extraLabel' => 'Severity / status',
    'extra' => static fn(Incident $i) => Badge::severity($i->severity) . ' ' . Badge::status('incident-status', $i->status, Incident::statusOptions())]) ?>
<?php if ($model->devices): ?>
    <?= $this->render('@app/views/pdf/_records', ['title' => 'Affected devices', 'models' => $model->devices]) ?>
<?php endif; ?>
<?php if ($model->cloudAssets): ?>
    <?= $this->render('@app/views/pdf/_records', ['title' => 'Affected cloud assets', 'models' => $model->cloudAssets]) ?>
<?php endif; ?>

<?= $this->render('@app/views/pdf/_attachments', ['attachments' => Attachment::listFor($model)]) ?>
