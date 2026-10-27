<?php
/**
 * Analyst notes on the indicator (newest first) with an add form.
 * @var yii\web\View $this
 * @var app\modules\intel\models\IntelIndicator $model
 * @var app\modules\intel\models\IntelNote[] $notes
 * @var app\modules\intel\models\IntelNote $note
 */

use app\components\Rbac;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
?>
<div class="row g-4">
    <?php if (Rbac::canManage()): ?>
        <div class="col-xl-4 order-xl-2">
            <section class="gw-panel gw-timeline-form">
                <h2 class="gw-panel__title"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add note</h2>
                <?php $form = ActiveForm::begin(['action' => ['/intel/investigate/note'], 'id' => 'intel-note-form']); ?>
                <?= Html::hiddenInput('indicator', $model->value) ?>
                <?= $form->field($note, 'body')->textarea(['rows' => 5, 'placeholder' => 'Context, sightings, who owns it, why it was blocked…'])->label('Note') ?>
                <?= Html::submitButton('<i class="bi bi-plus-lg"></i> Add note', ['class' => 'btn btn-primary gw-btn']) ?>
                <?php ActiveForm::end(); ?>
                <p class="form-text gw-field__hint mt-2">Notes are permanent and visible to every user, like incident timeline entries.</p>
            </section>
        </div>
    <?php endif; ?>
    <div class="col">
        <?php if (!$notes): ?>
            <div class="gw-empty-state">
                <i class="bi bi-sticky" aria-hidden="true"></i>
                <p>No notes about <?= Html::encode($model->value) ?> yet.</p>
            </div>
        <?php else: ?>
            <ol class="gw-timeline">
                <?php foreach ($notes as $n): ?>
                    <li class="gw-timeline__entry gw-timeline__entry--note">
                        <div class="gw-timeline__marker" aria-hidden="true"><i class="bi bi-sticky"></i></div>
                        <div class="gw-timeline__body">
                            <div class="gw-timeline__meta">
                                <span class="gw-timeline__type">Note</span>
                                <time class="gw-timeline__time" datetime="<?= Html::encode($n->created_at) ?>"><?= $fmt->asDatetime($n->created_at) ?></time>
                                <span class="gw-timeline__author"><i class="bi bi-person" aria-hidden="true"></i> <?= Html::encode($n->creator->full_name ?? 'system') ?></span>
                            </div>
                            <div class="gw-timeline__text"><?= nl2br(Html::encode($n->body)) ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
</div>
