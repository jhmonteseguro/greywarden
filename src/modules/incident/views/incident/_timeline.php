<?php
/**
 * Incident timeline (newest first) with inline add form.
 * @var yii\web\View $this
 * @var app\modules\incident\models\Incident $model
 * @var app\modules\incident\models\IncidentTimeline $entry
 */

use app\components\Rbac;
use app\helpers\Dt;
use app\modules\incident\models\IncidentTimeline;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
$entries = $model->getTimeline()->with('creator')->all();
/** entry type => bootstrap icon for the timeline marker */
$icons = ['note' => 'chat-left-text', 'action' => 'lightning-charge', 'evidence' => 'paperclip', 'communication' => 'megaphone', 'status' => 'arrow-left-right'];
?>
<div class="row g-4">
    <?php if (Rbac::canManage() && !$model->isArchived): ?>
    <div class="col-xl-4 order-xl-2">
        <section class="gw-panel gw-timeline-form">
            <h2 class="gw-panel__title">Add entry</h2>
            <?php $form = ActiveForm::begin([
                'action' => ['add-entry', 'id' => $model->id],
                'id' => 'timeline-form',
                'enableClientValidation' => true,
            ]); ?>
            <?= $form->field($entry, 'entry_type')->dropDownList(IncidentTimeline::userTypeOptions()) ?>
            <?= $form->field($entry, 'entry_at')->input('datetime-local', ['value' => Dt::toInput($entry->entry_at), 'step' => 60]) ?>
            <?= $form->field($entry, 'body')->textarea(['rows' => 4, 'placeholder' => 'What happened / what was done?']) ?>
            <?= Html::submitButton('<i class="bi bi-plus-lg"></i> Add to timeline', ['class' => 'btn btn-primary gw-btn']) ?>
            <?php ActiveForm::end(); ?>
        </section>
    </div>
    <?php endif; ?>
    <div class="col">
        <?php if (!$entries): ?>
            <div class="gw-empty-state">
                <i class="bi bi-signpost-split" aria-hidden="true"></i>
                <p>No timeline entries yet.</p>
            </div>
        <?php else: ?>
            <ol class="gw-timeline">
                <?php foreach ($entries as $e): ?>
                    <li class="gw-timeline__entry gw-timeline__entry--<?= Html::encode($e->entry_type) ?>">
                        <div class="gw-timeline__marker" aria-hidden="true"><i class="bi bi-<?= $icons[$e->entry_type] ?? 'dot' ?>"></i></div>
                        <div class="gw-timeline__body">
                            <div class="gw-timeline__meta">
                                <span class="gw-timeline__type"><?= Html::encode($e->getTypeLabel()) ?></span>
                                <time class="gw-timeline__time" datetime="<?= Html::encode($e->entry_at) ?>"><?= $fmt->asDatetime($e->entry_at) ?></time>
                                <span class="gw-timeline__author"><i class="bi bi-person" aria-hidden="true"></i> <?= Html::encode($e->creator->full_name ?? 'system') ?></span>
                            </div>
                            <div class="gw-timeline__text"><?= nl2br(Html::encode($e->body)) ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
</div>
