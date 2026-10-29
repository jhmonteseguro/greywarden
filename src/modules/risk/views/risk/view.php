<?php
/**
 * @var yii\web\View $this
 * @var app\modules\risk\models\Risk $model
 */

use app\helpers\Badge;
use app\helpers\RiskMatrix;
use app\components\Rbac;
use app\models\Attachment;
use app\models\RecordNote;
use app\models\RecordReview;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;
use app\widgets\Attachments;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use app\widgets\LinkedRecords;
use app\widgets\Notes;
use app\widgets\RecordActions;
use app\widgets\Reviews;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;

$this->title = $model->title;
$this->params['breadcrumbs'][] = ['label' => 'Risk Register', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->reference;
$flags = [];
if ($model->isReviewOverdue()) {
    $flags[] = Badge::flag('overdue', 'Review overdue');
}
if ($model->isTreatmentOverdue()) {
    $flags[] = Badge::flag('overdue', 'Treatment overdue');
}
$controlIssues = $model->getControlIssues();
if ($controlIssues) {
    $flags[] = Badge::flag('weak-control', 'Control issue');
}
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->reference) . '</span> '
    . Badge::status('risk-status', $model->status, Risk::statusOptions()) . ' ' . implode(' ', $flags);
$this->params['actions'] = RecordActions::widget(['model' => $model, 'pdf' => true]);
$fmt = Yii::$app->formatter;
$reviews = RecordReview::listFor($model);
$lastReview = $reviews[0] ?? null;

$devices = $model->devices;
$cloudAssets = $model->cloudAssets;
$suppliers = $model->suppliers;
$incidents = $model->incidents;
$decisions = $model->decisions;
$techniques = $model->techniques;
$controls = $model->controls;
$inPlace = array_filter($controls, static fn(Control $c) => $c->status === Control::STATUS_IMPLEMENTED && !$c->getIsArchived());
$planned = array_filter($controls, static fn(Control $c) => in_array($c->status, Control::PENDING_STATUSES, true) && !$c->getIsArchived());
$other = array_diff_key($controls, $inPlace, $planned);
$controlExtra = static fn(Control $c) => Badge::status('control-status', $c->status, Control::statusOptions()) . ' ' . Badge::effectiveness($c->effectiveness)
    . (($w = $c->weaknesses()) ? ' ' . Badge::flag('weak-control', ucfirst(implode(', ', $w))) : '');

ob_start(); ?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Risk details</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'reference', 'contentOptions' => ['class' => 'gw-mono']],
                    ['attribute' => 'category_id', 'value' => $model->category->name ?? null],
                    ['attribute' => 'owner_id', 'value' => $model->owner->full_name ?? null],
                    'scope',
                    'date_identified:date',
                    ['label' => 'ATT&CK techniques', 'format' => 'raw', 'value' => TechniqueTags::widget(['items' => $techniques, 'compact' => true, 'emptyText' => 'None mapped'])],
                ],
            ]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Description', 'text' => $model->description]) ?>
            <div class="row">
                <div class="col-md-6"><?= $this->render('@app/views/partials/_text-block', ['label' => 'Threat source', 'text' => $model->threat_source]) ?></div>
                <div class="col-md-6"><?= $this->render('@app/views/partials/_text-block', ['label' => 'Vulnerability', 'text' => $model->vulnerability]) ?></div>
            </div>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Existing controls', 'text' => $model->existing_controls]) ?>
        </section>

        <section class="gw-panel gw-risk-controls">
            <header class="gw-panel__header">
                <h2 class="gw-panel__title"><i class="bi bi-shield-shaded" aria-hidden="true"></i> Controls <span class="gw-panel__count"><?= count($controls) ?></span></h2>
                <div class="gw-panel__tools"><?= Html::a('Control Register', ['/control/control/index'], ['class' => 'gw-panel__link']) ?></div>
            </header>
            <?php if ($controlIssues): ?>
                <div class="alert alert-warning gw-callout" role="status"><i class="bi bi-shield-exclamation" aria-hidden="true"></i>
                    The residual score relies on <?= count($controlIssues) ?> control(s) that are retired, not effective or overdue. Review the assessment.</div>
            <?php endif; ?>
            <?php if (!$controls): ?>
                <p class="gw-empty">No controls from the Control Register are linked yet.<?= Rbac::canManage() && !$model->getIsArchived() ? ' ' . Html::a('Link controls', ['update', 'id' => $model->id]) . '.' : '' ?></p>
            <?php else: ?>
                <div class="row g-3">
                    <div class="col-lg-6"><?= LinkedRecords::widget(['title' => 'In place', 'icon' => 'shield-check', 'models' => array_values($inPlace), 'extra' => $controlExtra, 'emptyText' => 'No implemented control.']) ?></div>
                    <div class="col-lg-6"><?= LinkedRecords::widget(['title' => 'Planned', 'icon' => 'hourglass-split', 'models' => array_values($planned), 'extra' => $controlExtra, 'emptyText' => 'No planned control.']) ?></div>
                    <?php if ($other): ?>
                        <div class="col-12"><?= LinkedRecords::widget(['title' => 'Retired or archived', 'icon' => 'shield-slash', 'models' => array_values($other), 'extra' => $controlExtra]) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="gw-panel">
            <h2 class="gw-panel__title">Treatment</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'treatment', 'format' => 'raw', 'value' => Badge::render('treatment', $model->treatment, $model->getTreatmentLabel())],
                    ['attribute' => 'target_treatment_date', 'format' => 'raw', 'value' => $fmt->asDate($model->target_treatment_date) . ($model->isTreatmentOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '')],
                    ['attribute' => 'next_review_date', 'format' => 'raw', 'value' => $fmt->asDate($model->next_review_date) . ($model->isReviewOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '')],
                    ['label' => 'Last reviewed', 'format' => 'raw', 'value' => $lastReview
                        ? $fmt->asDate($lastReview->reviewed_on) . ' by ' . Html::encode($lastReview->reviewedBy->full_name ?? '—')
                        : '<span class="gw-null">Never</span>'],
                ],
            ]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Treatment plan / planned controls', 'text' => $model->treatment_plan]) ?>
        </section>

        <?php if ($model->status === Risk::STATUS_ACCEPTED || $model->acceptance_justification): ?>
        <section class="gw-panel gw-panel--acceptance">
            <h2 class="gw-panel__title">Risk acceptance</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'accepted_by_id', 'value' => $model->acceptedBy->full_name ?? null],
                    'accepted_at:date',
                ],
            ]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Acceptance justification', 'text' => $model->acceptance_justification]) ?>
        </section>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <section class="gw-panel gw-scorecard">
            <h2 class="gw-panel__title">Assessment</h2>
            <div class="gw-scorecard__grid">
                <div class="gw-scorecard__item gw-scorecard__item--inherent">
                    <span class="gw-scorecard__label">Inherent</span>
                    <span class="gw-scorecard__score gw-scorecard__score--<?= $model->getInherentRating() ?>"><?= (int) $model->inherent_score ?></span>
                    <?= Badge::rating($model->getInherentRating()) ?>
                    <span class="gw-scorecard__axes">L<?= (int) $model->inherent_likelihood ?> × I<?= (int) $model->inherent_impact ?></span>
                </div>
                <div class="gw-scorecard__item gw-scorecard__item--residual">
                    <span class="gw-scorecard__label">Residual</span>
                    <?php if ($model->residual_score): ?>
                        <span class="gw-scorecard__score gw-scorecard__score--<?= $model->getResidualRating() ?>"><?= (int) $model->residual_score ?></span>
                        <?= Badge::rating($model->getResidualRating()) ?>
                        <span class="gw-scorecard__axes">L<?= (int) $model->residual_likelihood ?> × I<?= (int) $model->residual_impact ?></span>
                    <?php else: ?>
                        <span class="gw-scorecard__score gw-scorecard__score--none">—</span>
                        <span class="gw-muted">Not assessed</span>
                    <?php endif; ?>
                </div>
                <div class="gw-scorecard__item gw-scorecard__item--target">
                    <span class="gw-scorecard__label">Target</span>
                    <?php if ($model->target_score): ?>
                        <span class="gw-scorecard__score gw-scorecard__score--<?= $model->getTargetRating() ?>"><?= (int) $model->target_score ?></span>
                        <?= Badge::rating($model->getTargetRating()) ?>
                        <span class="gw-scorecard__axes">L<?= (int) $model->target_likelihood ?> × I<?= (int) $model->target_impact ?></span>
                    <?php else: ?>
                        <span class="gw-scorecard__score gw-scorecard__score--none">—</span>
                        <span class="gw-muted">Not set</span>
                    <?php endif; ?>
                </div>
            </div>
            <p class="gw-scorecard__legend">
                Likelihood: <?= Html::encode(RiskMatrix::likelihoodLabels()[$model->inherent_likelihood] ?? '') ?> ·
                Impact: <?= Html::encode(RiskMatrix::impactLabels()[$model->inherent_impact] ?? '') ?>
            </p>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">Record</h2>
            <?= $this->render('@app/views/partials/_record-meta', ['model' => $model]) ?>
        </section>
    </div>
</div>
<?php $details = ob_get_clean();

ob_start(); ?>
<div class="row g-4">
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Affected devices', 'icon' => 'pc-display', 'models' => $devices,
        'extra' => static fn(Device $d) => Badge::status('device-status', $d->status, Device::statusOptions())]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Affected cloud assets', 'icon' => 'cloud', 'models' => $cloudAssets,
        'extra' => static fn(CloudAsset $a) => Badge::status('cloud-status', $a->status, CloudAsset::statusOptions())]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Related suppliers', 'icon' => 'buildings', 'models' => $suppliers,
        'extra' => static fn(Supplier $s) => Badge::tier($s->tier) . ' ' . Badge::status('supplier-status', $s->status, Supplier::statusOptions())]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Related incidents', 'icon' => 'fire', 'models' => $incidents,
        'extra' => static fn(Incident $i) => Badge::severity($i->severity) . ' ' . Badge::status('incident-status', $i->status, Incident::statusOptions())]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Related decisions', 'icon' => 'journal-check', 'models' => $decisions,
        'extra' => static fn(Decision $d) => Badge::status('decision-status', $d->status, Decision::statusOptions())]) ?></div>
</div>
<?php $links = ob_get_clean(); ?>

<?php $attachments = Attachment::listFor($model);
$notes = RecordNote::listFor($model); ?>
<?= $this->render('@app/views/partials/_archived-notice', ['model' => $model]) ?>

<?= Tabs::widget([
    'options' => ['class' => 'gw-tabs'],
    'tabContentOptions' => ['class' => 'gw-tab-content'],
    'items' => [
        ['label' => 'Details', 'content' => $details, 'options' => ['id' => 'tab-details']],
        ['label' => 'ATT&CK (' . count($techniques) . ')', 'content' => $this->render('@app/modules/attack/views/partials/_record-tab', [
            'techniques' => $techniques,
            'intro' => 'Adversary techniques that could realise this risk.',
            'canEdit' => Rbac::canManage() && !$model->getIsArchived(),
            'editUrl' => ['update', 'id' => $model->id],
        ]), 'options' => ['id' => 'tab-attack']],
        ['label' => 'Reviews (' . count($reviews) . ')', 'content' => Reviews::widget(['model' => $model, 'reviews' => $reviews]), 'options' => ['id' => 'tab-reviews']],
        ['label' => 'Notes (' . count($notes) . ')', 'content' => Notes::widget(['model' => $model, 'notes' => $notes, 'attachments' => $attachments]), 'options' => ['id' => 'tab-notes']],
        ['label' => 'Links (' . (count($devices) + count($cloudAssets) + count($suppliers) + count($incidents) + count($decisions)) . ')', 'content' => $links, 'options' => ['id' => 'tab-links']],
        ['label' => 'Files (' . count($attachments) . ')', 'content' => Attachments::widget(['model' => $model, 'attachments' => $attachments]), 'options' => ['id' => 'tab-files']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => 'risk', 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ],
]) ?>
