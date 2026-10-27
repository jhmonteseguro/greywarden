<?php
/**
 * @var yii\web\View $this
 * @var app\modules\decision\models\Decision $model
 */

use app\models\Lookup;
use app\modules\attack\models\AttackMitigation;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use app\widgets\ActiveForm;
use yii\helpers\Html;

?>
<?php $form = ActiveForm::begin(['id' => 'decision-form', 'options' => ['class' => 'gw-form gw-form--decision']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Decision</legend>
    <div class="row">
        <div class="col-md-8"><?= $form->field($model, 'title')->textInput(['maxlength' => true]) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'status')->dropDownList(Decision::statusOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'decision_date')->input('date') ?></div>
        <div class="col-md-4"><?= $form->field($model, 'category_id')->dropDownList(Lookup::options(Lookup::TYPE_DECISION_CATEGORY, $model->category_id), ['prompt' => '— Select —']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'review_date')->input('date') ?></div>
        <div class="col-md-6"><?= $form->field($model, 'decision_makers')->textInput(['maxlength' => true, 'placeholder' => 'e.g. CISO, IT Director']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'stakeholders')->textInput(['placeholder' => 'Who was consulted?']) ?></div>
        <div class="col-12" data-gw-depends="#decision-status" data-gw-value="superseded">
            <?= $form->field($model, 'superseded_by_id')->dropDownList($model->supersededByOptions(), ['prompt' => '— Select decision —']) ?>
        </div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Record</legend>
    <?= $form->field($model, 'context')->textarea(['rows' => 3]) ?>
    <?= $form->field($model, 'options_considered')->textarea(['rows' => 3]) ?>
    <?= $form->field($model, 'decision_text')->textarea(['rows' => 3]) ?>
    <?= $form->field($model, 'rationale')->textarea(['rows' => 3]) ?>
    <?= $form->field($model, 'impact')->textarea(['rows' => 2]) ?>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">MITRE ATT&amp;CK</legend>
    <?= $form->field($model, 'mitigationIds')->listBox(AttackMitigation::selectOptions($model->mitigationIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect', 'data-gw-picker' => 'Filter mitigations, e.g. M1032 or MFA'])
        ->label('Mitigations adopted')
        ->hint('ATT&CK mitigations this decision commits to. Approved decisions count them as planned; record the control that implements them in the Control Register to count them as in place.') ?>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Links</legend>
    <div class="row">
        <div class="col-md-4"><?= $form->field($model, 'controlIds')->listBox(Control::selectOptions($model->controlIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect'])
            ->hint('Controls this decision introduces or changes.') ?></div>
        <div class="col-md-4"><?= $form->field($model, 'riskIds')->listBox(Risk::selectOptions($model->riskIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'incidentIds')->listBox(Incident::selectOptions($model->incidentIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect']) ?></div>
    </div>
    <p class="form-text gw-field__hint">Hold Ctrl / Cmd to select multiple.</p>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton($model->isNewRecord ? 'Create decision' : 'Save changes', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
