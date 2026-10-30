<?php
/**
 * A management report in the browser: sign-off panel, then the report body
 * (shared with the PDF) and its history.
 * @var yii\web\View $this
 * @var app\modules\report\models\Report $model
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\report\components\ReportPeriod;
use app\modules\report\models\Report;
use app\widgets\AuditHistory;
use app\widgets\RecordActions;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
$this->title = $model->title;
$this->params['breadcrumbs'][] = ['label' => 'Management Reports', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->reference;
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->reference) . '</span> '
    . Badge::status('report-status', $model->status, Report::statusOptions()) . ' '
    . ($model->rag ? Badge::render('rag', $model->rag, $model->getRagLabel()) . ' ' : '')
    . '<span class="gw-muted">' . Html::encode(ReportPeriod::range($model->period_start, $model->period_end)) . '</span>';
$live = !$model->getIsArchived();
$canManage = Rbac::canManage() && $live;
$regenerate = $canManage && $model->isDraft() ? Html::a('<i class="bi bi-arrow-repeat"></i> Regenerate figures', ['regenerate', 'id' => $model->id], [
    'class' => 'btn btn-outline-secondary gw-btn gw-btn--regenerate',
    'data-method' => 'post',
    'data-confirm' => 'Work the figures out again from the registers as they are now? The current figures are replaced.',
]) : '';
$this->params['actions'] = RecordActions::widget(['model' => $model, 'pdf' => true, 'edit' => $model->isDraft(), 'before' => $regenerate]);
$blockers = $model->approvalBlockers();

ob_start(); ?>
<?= $this->render('@app/views/partials/_archived-notice', ['model' => $model]) ?>
<section class="gw-panel gw-report-signoff gw-report-signoff--<?= Html::encode($model->status) ?>">
    <?php if ($model->isApproved()): ?>
        <h2 class="gw-panel__title"><i class="bi bi-patch-check" aria-hidden="true"></i> Approved</h2>
        <p class="mb-1">Approved by <strong><?= Html::encode($model->approvedBy->full_name ?? '—') ?></strong> on <?= $fmt->asDatetime($model->approved_at) ?>. The report is read-only.</p>
        <?php if ($model->approval_note): ?><p class="gw-muted mb-1"><?= nl2br(Html::encode($model->approval_note)) ?></p><?php endif; ?>
        <?php if (Rbac::canDelete() && $live): ?>
            <?= Html::a('<i class="bi bi-arrow-counterclockwise"></i> Withdraw approval', ['withdraw', 'id' => $model->id], [
                'class' => 'btn btn-outline-warning gw-btn btn-sm mt-2', 'data-method' => 'post',
                'data-confirm' => 'Withdraw the approval? The report becomes a draft again and can be changed.',
            ]) ?>
        <?php endif; ?>
    <?php else: ?>
        <h2 class="gw-panel__title"><i class="bi bi-pencil" aria-hidden="true"></i> Draft</h2>
        <p class="mb-2">Figures generated <?= $fmt->asDatetime($model->generated_at) ?><?= $model->generator ? ' by ' . Html::encode($model->generator->full_name) : '' ?>.
            Write the management summary, check the overall status, then approve the report to sign it off.</p>
        <?php if ($canManage): ?>
            <?php if ($blockers): ?>
                <p class="gw-muted gw-small mb-0">Before it can be approved: <?= Html::encode(implode(' ', $blockers)) ?></p>
            <?php else: ?>
                <?= Html::beginForm(['approve', 'id' => $model->id], 'post', ['class' => 'gw-report-approve']) ?>
                <div class="mb-2 gw-field">
                    <label class="form-label gw-field__label" for="approval-note">Approval note <span class="gw-muted">(optional)</span></label>
                    <?= Html::textarea('approval_note', '', ['id' => 'approval-note', 'class' => 'form-control', 'rows' => 2, 'maxlength' => Report::NOTE_MAX,
                        'placeholder' => 'e.g. Reviewed with the board on 3 November']) ?>
                </div>
                <?= Html::submitButton('<i class="bi bi-patch-check"></i> Approve report', ['class' => 'btn btn-success gw-btn',
                    'data-confirm' => 'Approve this report? It becomes read-only.']) ?>
                <?= Html::endForm() ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($model->hasReadableFigures()): ?>
    <div class="gw-report">
        <?= $this->render('_body', ['model' => $model, 'pdf' => false]) ?>
    </div>
<?php else: ?>
    <p class="gw-empty">The figures of this report were made by another version of Greywarden and cannot be shown<?= $model->isDraft() ? '. Regenerate them.' : '.' ?></p>
<?php endif; ?>
<?php $report = ob_get_clean(); ?>

<?= Tabs::widget([
    'options' => ['class' => 'gw-tabs'],
    'tabContentOptions' => ['class' => 'gw-tab-content'],
    'items' => [
        ['label' => 'Report', 'content' => $report, 'options' => ['id' => 'tab-report']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => 'report', 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ],
]) ?>
