<?php
/**
 * @var yii\web\View $this
 * @var app\modules\intel\models\BlocklistEntry $model
 */

use app\helpers\Dt;
use app\modules\incident\models\Incident;
use app\modules\intel\components\Cloudflare;
use app\modules\intel\models\BlocklistEntry;
use app\widgets\ActiveForm;
use yii\helpers\Html;
?>
<?php $form = ActiveForm::begin(['id' => 'blocklist-form', 'options' => ['class' => 'gw-form gw-form--blocklist']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Indicator</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($model, 'value')->textInput(['maxlength' => 64, 'class' => 'form-control gw-mono', 'autofocus' => $model->isNewRecord, 'placeholder' => '203.0.113.7 · 198.51.100.0/24 · AS64496']) ?></div>
        <div class="col-md-3"><?= $form->field($model, 'severity')->dropDownList(BlocklistEntry::severityOptions()) ?></div>
        <div class="col-md-3"><?= $form->field($model, 'direction')->dropDownList(BlocklistEntry::directionOptions()) ?></div>
        <div class="col-12"><?= $form->field($model, 'reason')->textarea(['rows' => 3, 'placeholder' => 'Why is this blocked? e.g. brute-force source in INC-0012; Feodo Tracker C2.']) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Scope</legend>
    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'expires_at')->input('datetime-local', ['value' => Dt::toInput($model->expires_at), 'step' => 60]) ?>
            <div class="gw-intel-quick gw-intel-quick--form" role="group" aria-label="Quick expiry">
                <?php foreach (['24 h' => 24, '7 days' => 168, '30 days' => 720, '90 days' => 2160, 'Permanent' => 0] as $label => $hours): ?>
                    <button type="button" class="gw-intel-quick__btn" data-gw-expiry="<?= $hours ?>" data-gw-target="#blocklistentry-expires_at"><?= $label ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-md-6"><?= $form->field($model, 'incident_id')->dropDownList(Incident::selectOptions(array_filter([$model->incident_id])), ['prompt' => '— None —']) ?></div>
        <div class="col-12"><?= $form->field($model, 'cloudflare')->checkbox(['disabled' => !Cloudflare::isConfigured()]) ?></div>
    </div>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton($model->isNewRecord ? '<i class="bi bi-slash-circle"></i> Block' : 'Save changes', ['class' => 'btn ' . ($model->isNewRecord ? 'btn-danger' : 'btn-primary') . ' gw-btn']) ?>
    <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
