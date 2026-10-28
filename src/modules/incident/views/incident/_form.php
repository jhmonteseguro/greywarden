<?php
/**
 * @var yii\web\View $this
 * @var app\modules\incident\models\Incident $model
 */

use app\helpers\Dt;
use app\models\Lookup;
use app\models\User;
use app\modules\attack\models\AttackTechnique;
use app\modules\control\models\Control;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$dt = static fn(string $attr) => ['value' => Dt::toInput($model->$attr), 'step' => 60];
?>
<?php $form = ActiveForm::begin(['id' => 'incident-form', 'options' => ['class' => 'gw-form gw-form--incident']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Incident</legend>
    <div class="row">
        <div class="col-md-8"><?= $form->field($model, 'title')->textInput(['maxlength' => true]) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'category_id')->dropDownList(Lookup::options(Lookup::TYPE_INCIDENT_CATEGORY, $model->category_id), ['prompt' => '— Select —']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'severity')->dropDownList(Incident::severityOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'status')->dropDownList(Incident::statusOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'assigned_to_id')->dropDownList(User::options($model->assigned_to_id), ['prompt' => '— Unassigned —']) ?></div>
        <div class="col-12"><?= $form->field($model, 'description')->textarea(['rows' => 4]) ?></div>        <div class="col-12">
            <fieldset class="mb-3 gw-field gw-impact">
                <legend class="form-label gw-field__label">Security impact</legend>
                <div class="d-flex flex-wrap column-gap-4">
                    <?php foreach (array_keys(Incident::IMPACT_ATTRIBUTES) as $attribute): ?>
                        <?= $form->field($model, $attribute, ['options' => ['class' => 'mb-1 gw-field']])->checkbox() ?>
                    <?php endforeach; ?>
                </div>
                <p class="form-text gw-field__hint">Tick every property the incident affected or put at risk: data disclosed (confidentiality), data or systems altered (integrity), service lost or degraded (availability).</p>
            </fieldset>
        </div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Timing &amp; reporting</legend>
    <div class="row">
        <div class="col-md-4"><?= $form->field($model, 'occurred_at')->input('datetime-local', $dt('occurred_at')) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'detected_at')->input('datetime-local', $dt('detected_at')) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'reported_at')->input('datetime-local', $dt('reported_at')) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'reported_by')->textInput(['maxlength' => true]) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'contained_at')->input('datetime-local', $dt('contained_at'))->hint('Set automatically when status moves to Contained or later.') ?></div>
        <div class="col-md-4"><?= $form->field($model, 'closed_at')->input('datetime-local', $dt('closed_at'))->hint('Set automatically when the incident is closed.') ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Personal data &amp; regulator</legend>
    <?= $form->field($model, 'personal_data_involved')->checkbox() ?>
    <div data-gw-depends="#incident-personal_data_involved" data-gw-value="1">
        <?= $form->field($model, 'regulator_notification_required')->checkbox()->hint(sprintf('Deadline is %d hours from detection.', Incident::deadlineHours())) ?>
        <div class="row" data-gw-depends="#incident-regulator_notification_required" data-gw-value="1">
            <div class="col-md-4"><?= $form->field($model, 'regulator_notified_at')->input('datetime-local', $dt('regulator_notified_at')) ?></div>
            <div class="col-md-4"><?= $form->field($model, 'regulator_reference')->textInput(['maxlength' => true]) ?></div>
            <div class="col-md-4"><?= $form->field($model, 'subjects_notified_at')->input('datetime-local', $dt('subjects_notified_at')) ?></div>
        </div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Response</legend>
    <?= $form->field($model, 'containment_actions')->textarea(['rows' => 3]) ?>
    <?= $form->field($model, 'recovery_actions')->textarea(['rows' => 3]) ?>
    <?= $form->field($model, 'root_cause')->textarea(['rows' => 2]) ?>
    <?= $form->field($model, 'lessons_learned')->textarea(['rows' => 2]) ?>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">MITRE ATT&amp;CK</legend>
    <?= $form->field($model, 'techniqueIds')->listBox(AttackTechnique::selectOptions($model->techniqueIds ?? []), ['multiple' => true, 'size' => 10, 'class' => 'form-select gw-multiselect', 'data-gw-picker' => 'Filter techniques, e.g. T1566 or phishing'])
        ->label('Techniques observed')
        ->hint('Adversary techniques seen in this incident. Prefer the sub-technique when it is known (T1566.001 rather than T1566).') ?>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Controls</legend>
    <div class="row">
        <?php foreach (Incident::controlRoleProperties() as $role => $attribute): ?>
            <div class="col-md-4"><?= $form->field($model, $attribute)->listBox(Control::selectOptions($model->$attribute ?? []), [
                'multiple' => true, 'size' => 6, 'class' => 'form-select gw-multiselect', 'data-gw-picker' => 'Filter controls…',
            ]) ?></div>
        <?php endforeach; ?>
    </div>
    <p class="form-text gw-field__hint">Lessons learned for the Control Register: which controls worked, which failed, and which were added afterwards.</p>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Links</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($model, 'deviceIds')->listBox(Device::selectOptions($model->deviceIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'riskIds')->listBox(Risk::selectOptions($model->riskIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect']) ?></div>
    </div>
    <p class="form-text gw-field__hint">Hold Ctrl / Cmd to select multiple.</p>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton($model->isNewRecord ? 'Log incident' : 'Save changes', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
