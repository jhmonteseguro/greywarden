<?php
/**
 * @var yii\web\View $this
 * @var app\modules\decision\models\Decision $model
 */

use app\helpers\Badge;
use app\helpers\RiskMatrix;
use app\models\Attachment;
use app\models\RecordNote;
use app\models\RecordReview;
use app\modules\attack\components\Coverage;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use app\widgets\Attachments;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use app\widgets\LinkedRecords;
use app\widgets\Notes;
use app\widgets\RecordActions;
use app\widgets\Reviews;
use yii\bootstrap5\Tabs;
use yii\db\Query;
use yii\helpers\Html;

$this->title = $model->title;
$this->params['breadcrumbs'][] = ['label' => 'Decision Log', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->reference;
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->reference) . '</span> '
    . Badge::status('decision-status', $model->status, Decision::statusOptions())
    . ($model->isReviewOverdue() ? ' ' . Badge::flag('overdue', 'Review overdue') : '');
$this->params['actions'] = RecordActions::widget(['model' => $model, 'pdf' => true]);

$fmt = Yii::$app->formatter;
$reviews = RecordReview::listFor($model);
$lastReview = $reviews[0] ?? null;
$risks = $model->risks;
$incidents = $model->incidents;
$supersedes = $model->supersedes;
$controls = $model->controls;
$cloudAssets = $model->cloudAssets;
$mitigations = $model->mitigations;
if ($mitigations) {
    // Techniques each mitigation addresses, and how many of them are seen in incidents / open risks
    $seen = Coverage::counts(Coverage::LAYER_INCIDENTS) + Coverage::counts(Coverage::LAYER_RISKS);
    $addressed = [];
    foreach ((new Query())->select(['mitigation_id', 'technique_id'])->from('attack_mitigation_technique')
                 ->where(['mitigation_id' => array_map(static fn($m) => $m->id, $mitigations)])->all() as $row) {
        $addressed[$row['mitigation_id']]['all'] = ($addressed[$row['mitigation_id']]['all'] ?? 0) + 1;
        $addressed[$row['mitigation_id']]['seen'] = ($addressed[$row['mitigation_id']]['seen'] ?? 0) + (isset($seen[$row['technique_id']]) ? 1 : 0);
    }
}
$counts = in_array($model->status, [Decision::STATUS_APPROVED, Decision::STATUS_IMPLEMENTED], true);

ob_start(); ?>
<?php if ($model->supersededBy): ?>
    <div class="alert alert-info gw-callout" role="status">
        <i class="bi bi-arrow-right-circle"></i> Superseded by
        <?= Html::a('<span class="gw-ref">' . Html::encode($model->supersededBy->reference) . '</span> · ' . Html::encode($model->supersededBy->title), $model->supersededBy->getViewUrl(), ['class' => 'gw-callout__link']) ?>
    </div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Decision record</h2>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Context / problem statement', 'text' => $model->context]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Options considered', 'text' => $model->options_considered]) ?>
            <div class="gw-textblock gw-textblock--highlight">
                <h3 class="gw-textblock__label">Decision</h3>
                <div class="gw-textblock__body"><?= nl2br(Html::encode($model->decision_text)) ?></div>
            </div>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Rationale', 'text' => $model->rationale]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Impact / consequences', 'text' => $model->impact]) ?>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Summary</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'reference', 'contentOptions' => ['class' => 'gw-mono']],
                    'decision_date:date',
                    ['attribute' => 'category_id', 'value' => $model->category->name ?? null],
                    'decision_makers',
                    'stakeholders:ntext',
                    ['attribute' => 'review_date', 'format' => 'raw', 'value' => $fmt->asDate($model->review_date) . ($model->isReviewOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '')],
                    ['label' => 'Last reviewed', 'format' => 'raw', 'value' => $lastReview
                        ? $fmt->asDate($lastReview->reviewed_on) . ' by ' . Html::encode($lastReview->reviewedBy->full_name ?? '—')
                        : '<span class="gw-null">Never</span>'],
                    ['attribute' => 'superseded_by_id', 'format' => 'raw', 'value' => $model->supersededBy ? Html::a(Html::encode($model->supersededBy->reference), $model->supersededBy->getViewUrl(), ['class' => 'gw-ref']) : null],
                ],
            ]) ?>
        </section>
        <section class="gw-panel gw-attack-mitigations">
            <h2 class="gw-panel__title"><i class="bi bi-shield-check" aria-hidden="true"></i> ATT&amp;CK mitigations
                <span class="gw-panel__count"><?= count($mitigations) ?></span></h2>
            <?php if (!$mitigations): ?>
                <p class="gw-empty">No ATT&amp;CK mitigations recorded for this decision.</p>
            <?php else: ?>
                <ul class="gw-attack-mitigations__list">
                    <?php foreach ($mitigations as $m): $a = $addressed[$m->id] ?? ['all' => 0, 'seen' => 0]; ?>
                        <li>
                            <?= TechniqueTags::tag($m) ?>
                            <span class="gw-attack-mitigations__meta"><?= $a['all'] ?> technique(s)<?= !empty($a['seen']) ? ' · <strong>' . $a['seen'] . '</strong> seen in incidents or risks' : '' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="form-text gw-field__hint mb-0"><?= $counts
                    ? 'This decision counts these mitigations as planned. They count as in place once an implemented control records them.'
                    : 'Counts these mitigations as planned once the decision is approved.' ?></p>
            <?php endif; ?>
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
    <div class="col-lg-6"><?= LinkedRecords::widget(['title' => 'Controls', 'icon' => 'shield-shaded', 'models' => $controls,
        'emptyText' => 'This decision does not introduce or change a control.',
        'extra' => static fn(Control $c) => Badge::status('control-status', $c->status, Control::statusOptions()) . ' ' . Badge::effectiveness($c->effectiveness)]) ?></div>
    <div class="col-lg-6"><?= LinkedRecords::widget(['title' => 'Related risks', 'icon' => 'exclamation-triangle', 'models' => $risks,
        'extra' => static fn(Risk $r) => Badge::score($r->getCurrentScore())]) ?></div>
    <div class="col-lg-6"><?= LinkedRecords::widget(['title' => 'Related incidents', 'icon' => 'fire', 'models' => $incidents,
        'extra' => static fn(Incident $i) => Badge::severity($i->severity)]) ?></div>
    <div class="col-lg-6"><?= LinkedRecords::widget(['title' => 'Related cloud assets', 'icon' => 'cloud', 'models' => $cloudAssets,
        'extra' => static fn(CloudAsset $a) => Badge::status('cloud-status', $a->status, CloudAsset::statusOptions())]) ?></div>
    <div class="col-lg-6"><?= LinkedRecords::widget(['title' => 'Supersedes', 'icon' => 'arrow-left-right', 'models' => $supersedes,
        'emptyText' => 'This decision does not supersede another.']) ?></div>
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
        ['label' => 'Reviews (' . count($reviews) . ')', 'content' => Reviews::widget(['model' => $model, 'reviews' => $reviews]), 'options' => ['id' => 'tab-reviews']],
        ['label' => 'Notes (' . count($notes) . ')', 'content' => Notes::widget(['model' => $model, 'notes' => $notes, 'attachments' => $attachments]), 'options' => ['id' => 'tab-notes']],
        ['label' => 'Links (' . (count($controls) + count($risks) + count($incidents) + count($cloudAssets) + count($supersedes)) . ')', 'content' => $links, 'options' => ['id' => 'tab-links']],
        ['label' => 'Files (' . count($attachments) . ')', 'content' => Attachments::widget(['model' => $model, 'attachments' => $attachments]), 'options' => ['id' => 'tab-files']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => 'decision', 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ],
]) ?>
