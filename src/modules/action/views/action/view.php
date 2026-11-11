<?php
/**
 * @var yii\web\View $this
 * @var app\modules\action\models\Action $model
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\Attachment;
use app\models\RecordNote;
use app\modules\action\models\Action;
use app\widgets\Attachments;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use app\widgets\Notes;
use app\widgets\RecordActions;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;

$this->title = $model->title;
$this->params['breadcrumbs'][] = ['label' => 'Actions', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->reference;
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->reference) . '</span> '
    . Badge::status('action-status', $model->status, Action::statusOptions()) . ' '
    . Badge::status('action-priority', $model->priority, Action::priorityOptions())
    . ($model->isOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : ($model->isDueSoon() ? ' ' . Badge::flag('pending', 'Due soon') : ''));
$this->params['actions'] = RecordActions::widget(['model' => $model]);
$fmt = Yii::$app->formatter;
$record = $model->getRecord();
$canClose = Rbac::canManage() && $model->isOpen() && !$model->getIsArchived();

ob_start(); ?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">What needs doing</h2>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Description', 'text' => $model->description]) ?>
            <?php if (!$model->isOpen()): ?>
                <div class="gw-textblock gw-textblock--highlight">
                    <h3 class="gw-textblock__label"><?= $model->status === Action::STATUS_DONE ? 'Outcome' : 'Why it was cancelled' ?></h3>
                    <div class="gw-textblock__body"><?= nl2br(Html::encode((string) $model->outcome)) ?></div>
                </div>
            <?php endif; ?>
        </section>
        <?php if ($canClose): ?>
            <section class="gw-panel">
                <h2 class="gw-panel__title">Close this action</h2>
                <?= Html::beginForm(['close', 'id' => $model->id], 'post', ['class' => 'gw-form', 'id' => 'action-close-form']) ?>
                    <div class="mb-3 gw-field">
                        <label class="form-label gw-field__label" for="action-outcome">Outcome</label>
                        <textarea id="action-outcome" name="outcome" class="form-control" rows="3" required maxlength="20000"
                                  placeholder="What was done, and where the evidence is (a ticket, a file on the Files tab...)"></textarea>
                    </div>
                    <div class="gw-form__actions">
                        <?= Html::submitButton('<i class="bi bi-check2-circle"></i> Mark done', ['class' => 'btn btn-primary gw-btn', 'name' => 'status', 'value' => Action::STATUS_DONE]) ?>
                        <?= Html::submitButton('Cancel the action', ['class' => 'btn btn-outline-secondary gw-btn', 'name' => 'status', 'value' => Action::STATUS_CANCELLED]) ?>
                    </div>
                <?= Html::endForm() ?>
            </section>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Summary</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'reference', 'contentOptions' => ['class' => 'gw-mono']],
                    ['attribute' => 'owner_id', 'value' => $model->owner->full_name ?? null],
                    ['attribute' => 'due_date', 'format' => 'raw', 'value' => $model->due_date
                        ? $fmt->asDate($model->due_date) . ($model->isOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '') : null],
                    ['attribute' => 'record_type', 'format' => 'raw', 'value' => $record
                        ? Html::encode($record::entityLabel()) . ' ' . Html::a(Html::encode($record->getReferenceValue()), $record->getViewUrl(), ['class' => 'gw-ref'])
                            . '<div class="gw-muted gw-small">' . Html::encode($record->getTitleValue()) . '</div>'
                        : ($model->isRecordDeleted() ? '<span class="gw-null">' . Html::encode($model->getDeletedRecordLabel()) . '</span>'
                            : '<span class="gw-null">Standalone</span>')],
                    ['attribute' => 'completed_at', 'format' => 'raw', 'visible' => !$model->isOpen(), 'value' => $fmt->asDatetime($model->completed_at)
                        . ($model->completer ? ' by ' . Html::encode($model->completer->full_name) : '')],
                ],
            ]) ?>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">Record</h2>
            <?= $this->render('@app/views/partials/_record-meta', ['model' => $model]) ?>
        </section>
    </div>
</div>
<?php $details = ob_get_clean(); ?>

<?php $attachments = Attachment::listFor($model);
$notes = RecordNote::listFor($model); ?>
<?= $this->render('@app/views/partials/_archived-notice', ['model' => $model]) ?>

<?= Tabs::widget([
    'options' => ['class' => 'gw-tabs'],
    'tabContentOptions' => ['class' => 'gw-tab-content'],
    'items' => [
        ['label' => 'Details', 'content' => $details, 'options' => ['id' => 'tab-details']],
        ['label' => 'Notes (' . count($notes) . ')', 'content' => Notes::widget(['model' => $model, 'notes' => $notes, 'attachments' => $attachments]), 'options' => ['id' => 'tab-notes']],
        ['label' => 'Files (' . count($attachments) . ')', 'content' => Attachments::widget(['model' => $model, 'attachments' => $attachments]), 'options' => ['id' => 'tab-files']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => 'action', 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ],
]) ?>
