<?php
/**
 * @var yii\web\View $this
 * @var app\models\DomainRecord $model a risk or decision
 * @var app\models\RecordReview[] $reviews
 * @var bool $canEdit
 * @var app\models\RecordReview|null $review form model for "Record a review"
 * @var string $dateAttribute the record's review date attribute
 */

use app\helpers\Badge;
use app\models\User;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
$due = $model->$dateAttribute;
?>
<div class="row g-4">
    <div class="col-xl-<?= $canEdit ? 8 : 12 ?>">
        <section class="gw-panel gw-panel--flush">
            <h2 class="gw-panel__title">Review history <span class="gw-panel__count"><?= count($reviews) ?></span></h2>
            <?php if (!$reviews): ?>
                <p class="gw-empty">No reviews recorded yet. Record one each time the <?= Html::encode(strtolower($model::entityLabel())) ?> is reviewed, so it is clear who reviewed it and when.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table gw-coverage__table gw-reviews">
                        <thead><tr><th scope="col">Reviewed on</th><th scope="col">Outcome</th><th scope="col">Reviewed by</th><th scope="col">Next review</th><?= $canEdit ? '<th scope="col"><span class="visually-hidden">Actions</span></th>' : '' ?></tr></thead>
                        <tbody>
                        <?php foreach ($reviews as $r): ?>
                            <tr>
                                <td class="gw-mono">
                                    <?= $fmt->asDate($r->reviewed_on) ?>
                                    <?php if ($r->due_on): ?>
                                        <div class="gw-muted gw-small">Due <?= $fmt->asDate($r->due_on) ?><?= $r->getWasLate() ? ' ' . Badge::flag('overdue', 'Late') : '' ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= nl2br(Html::encode($r->outcome)) ?></td>
                                <td>
                                    <?= Html::encode($r->reviewedBy->full_name ?? '—') ?>
                                    <?php if ($r->created_by !== null && (int) $r->created_by !== (int) $r->reviewed_by_id): ?>
                                        <div class="gw-muted gw-small">Recorded by <?= Html::encode($r->creator->full_name ?? 'system') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="gw-mono"><?= $r->next_review_date ? $fmt->asDate($r->next_review_date) : '<span class="gw-null">&mdash;</span>' ?></td>
                                <?php if ($canEdit): ?>
                                    <td class="text-end"><?= $r->canRemove() ? Html::a('<i class="bi bi-trash" aria-hidden="true"></i>', ['/review/remove', 'id' => $r->id], [
                                        'class' => 'gw-icon-btn', 'title' => 'Remove this review', 'aria-label' => 'Remove this review',
                                        'data-method' => 'post', 'data-confirm' => 'Remove this review? The review date stays as it is and the record\'s history keeps the review.',
                                    ]) : '' ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php if ($canEdit): ?>
    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-calendar-check" aria-hidden="true"></i> Record a review</h2>
            <p class="gw-small gw-muted">
                <?= $due ? 'Review due ' . $fmt->asDate($due) . ($model->isReviewOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '') . '.' : 'No review date set.' ?>
            </p>
            <?php $form = ActiveForm::begin(['id' => 'review-form', 'action' => ['/review/record', 'entity' => $model::entityType(), 'id' => $model->id]]); ?>
            <?= $form->errorSummary($review, ['class' => 'alert alert-danger gw-error-summary']) ?>
            <div class="row">
                <div class="col-sm-6"><?= $form->field($review, 'reviewed_on')->input('date', ['max' => date('Y-m-d')]) ?></div>
                <div class="col-sm-6"><?= $form->field($review, 'next_review_date', ['enableClientValidation' => false])->input('date') ?></div>
            </div>
            <?= $form->field($review, 'reviewed_by_id')->dropDownList(User::options($review->reviewed_by_id)) ?>
            <?= $form->field($review, 'outcome')->textarea(['rows' => 4, 'maxlength' => true]) ?>
            <?= Html::submitButton('Record review', ['class' => 'btn btn-primary gw-btn']) ?>
            <?php ActiveForm::end(); ?>
        </section>
    </div>
    <?php endif; ?>
</div>
