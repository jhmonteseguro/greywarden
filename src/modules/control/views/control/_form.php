<?php
/**
 * @var yii\web\View $this
 * @var app\modules\control\models\Control $model
 */

use app\models\User;
use app\modules\attack\models\AttackMitigation;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\models\Control;
use app\modules\control\models\FrameworkRequirement;
use app\modules\decision\models\Decision;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$picker = static fn(string $placeholder) => ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect', 'data-gw-picker' => $placeholder];
$pickerLabels = [
    FrameworkCatalogue::ISO27001 => 'ISO 27001 Annex A controls',
    FrameworkCatalogue::CYBER_ESSENTIALS => 'Cyber Essentials requirements',
    FrameworkCatalogue::SOC2 => 'SOC 2 Trust Services Criteria',
    FrameworkCatalogue::NIST_CSF => 'NIST CSF 2.0 outcomes',
];
$pickerHints = [
    FrameworkCatalogue::ISO27001 => 'Filter Annex A, e.g. A.8.7 or malware',
    FrameworkCatalogue::CYBER_ESSENTIALS => 'Filter requirements, e.g. CE-UA or MFA',
    FrameworkCatalogue::SOC2 => 'Filter criteria, e.g. CC6.1 or access',
    FrameworkCatalogue::NIST_CSF => 'Filter outcomes, e.g. PR.AA or backups',
];
?>
<?php $form = ActiveForm::begin(['id' => 'control-form', 'options' => ['class' => 'gw-form gw-form--control']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Control</legend>
    <div class="row">
        <div class="col-md-8"><?= $form->field($model, 'title')->textInput(['maxlength' => true, 'placeholder' => 'e.g. MFA on all cloud services']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'owner_id')->dropDownList(User::options($model->owner_id), ['prompt' => '— Unassigned —']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'status')->dropDownList(Control::statusOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'control_type')->dropDownList(Control::typeOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'nature')->dropDownList(Control::natureOptions()) ?></div>
        <div class="col-md-4" data-gw-depends="#control-status" data-gw-value="planned,implementing"><?= $form->field($model, 'target_date')->input('date') ?></div>
        <div class="col-md-4" data-gw-depends="#control-status" data-gw-value="implementing,implemented,retired"><?= $form->field($model, 'implemented_on')->input('date', ['max' => date('Y-m-d')])
            ->hint('Defaults to today when the control is marked implemented.') ?></div>
        <div class="col-md-4"><?= $form->field($model, 'test_frequency_months')->dropDownList(Control::frequencyOptions(), ['prompt' => '— No schedule —']) ?></div>
        <div class="col-12"><?= $form->field($model, 'description')->textarea(['rows' => 3]) ?></div>
        <div class="col-12"><?= $form->field($model, 'implementation')->textarea(['rows' => 3]) ?></div>
        <div class="col-12"><?= $form->field($model, 'evidence')->textarea(['rows' => 2]) ?></div>
    </div>
    <p class="form-text gw-field__hint mb-0">Effectiveness comes from the tests recorded on the control's Testing tab.</p>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Device check</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($model, 'device_check')->dropDownList(Control::deviceCheckOptions(), ['prompt' => '— Not checked on devices —']) ?></div>
        <div class="col-md-6" data-gw-depends="#control-device_check" data-gw-value="patch_age"><?= $form->field($model, 'check_max_age_days')->input('number', ['min' => 1, 'max' => 365])
            ->hint('Cyber Essentials requires high and critical updates within 14 days.') ?></div>
        <div class="col-12" data-gw-depends="#control-device_check" data-gw-value="manual,patch_age">
            <?= $form->field($model, 'deviceTypeList')->inline()->checkboxList(Device::typeOptions()) ?>
            <?= $form->field($model, 'device_ownership')->inline()->radioList(Control::deviceOwnershipOptions()) ?>
        </div>
    </div>
</fieldset>

<?php if ($frameworks = FrameworkCatalogue::enabled()): ?>
<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Frameworks</legend>
    <div class="row">
        <?php foreach ($frameworks as $i => $framework): ?>
            <div class="col-md-6"><?= $form->field($model, 'requirementIds')
                ->listBox(FrameworkRequirement::selectOptions($framework), $picker($pickerHints[$framework]) + ['id' => 'control-requirementids-' . FrameworkCatalogue::slug($framework)]
                    + ($i > 0 ? ['unselect' => null] : []))
                ->label($pickerLabels[$framework]) ?></div>
        <?php endforeach; ?>
    </div>
    <p class="form-text gw-field__hint">The framework pages under Compliance are built from these mappings.</p>
</fieldset>
<?php endif; ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">MITRE ATT&amp;CK</legend>
    <?= $form->field($model, 'mitigationIds')->listBox(AttackMitigation::selectOptions($model->mitigationIds ?? []), $picker('Filter mitigations, e.g. M1032 or MFA'))
        ->label('Mitigations implemented')
        ->hint('Once the control is implemented, these mitigations count as in place for the techniques they address.') ?>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Links</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($model, 'riskIds')->listBox(Risk::selectOptions($model->riskIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'decisionIds')->listBox(Decision::selectOptions($model->decisionIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect']) ?></div>
    </div>
    <p class="form-text gw-field__hint">Hold Ctrl / Cmd to select multiple. Incidents link to controls from the incident record.</p>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton($model->isNewRecord ? 'Create control' : 'Save changes', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
