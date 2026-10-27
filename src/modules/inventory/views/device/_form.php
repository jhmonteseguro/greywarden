<?php
/**
 * @var yii\web\View $this
 * @var app\modules\inventory\models\Device $model
 */

use app\models\Lookup;
use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use app\widgets\ActiveForm;
use yii\helpers\Html;

?>
<?php $form = ActiveForm::begin(['id' => 'device-form', 'options' => ['class' => 'gw-form gw-form--device']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Identity</legend>
    <div class="row">
        <div class="col-md-4"><?= $form->field($model, 'device_type')->dropDownList(Device::typeOptions(), ['prompt' => '— Select —']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'ownership')->inline()->radioList(Device::ownershipOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'asset_tag')->textInput(['maxlength' => true, 'placeholder' => 'Auto (DEV-0001)']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'name')->textInput(['maxlength' => true, 'placeholder' => 'e.g. Jane\'s iPhone 15']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'hostname')->textInput(['maxlength' => true, 'placeholder' => 'e.g. lt-lon-104']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'manufacturer')->textInput(['maxlength' => true]) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'model')->textInput(['maxlength' => true]) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'serial_number')->textInput(['maxlength' => true]) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'os')->textInput(['maxlength' => true, 'placeholder' => 'e.g. Windows 11 Enterprise']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'os_version')->textInput(['maxlength' => true, 'placeholder' => 'e.g. 23H2']) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Ownership &amp; network</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($model, 'assigned_to')->textInput(['maxlength' => true]) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'assigned_email')->input('email', ['maxlength' => true, 'placeholder' => 'jane.doe@example.com']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'department_id')->dropDownList(Lookup::options(Lookup::TYPE_DEPARTMENT, $model->department_id), ['prompt' => '— None —']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'location_id')->dropDownList(Lookup::options(Lookup::TYPE_LOCATION, $model->location_id), ['prompt' => '— None —']) ?></div>
        <div class="col-md-8"><?= $form->field($model, 'ip_addresses')->textInput(['maxlength' => true, 'placeholder' => '10.0.0.15, fe80::1']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'mac_address')->textInput(['maxlength' => 17, 'placeholder' => '00:1A:2B:3C:4D:5E']) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Lifecycle &amp; classification</legend>
    <div class="row">
        <div class="col-md-4"><?= $form->field($model, 'status')->dropDownList(Device::statusOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'criticality')->dropDownList(Device::criticalityOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'data_classification')->dropDownList(Device::classificationOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'purchase_date')->input('date') ?></div>
        <div class="col-md-4"><?= $form->field($model, 'warranty_expiry')->input('date') ?></div>
        <div class="col-md-4"><?= $form->field($model, 'eol_date')->input('date') ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset gw-device-checks">
    <legend class="gw-fieldset__title">Device checks</legend>
    <?php if (!DeviceChecks::all()): ?>
        <p class="gw-muted">No device checks are in force. Give a control in the <?= Html::a('Control Register', ['/control/control/index']) ?> a device check to add one.</p>
    <?php endif; ?>
    <div class="row">
        <?php foreach (DeviceChecks::manual() as $id => $check): ?>
            <div class="col-md-4" data-gw-check-types="<?= Html::encode(implode(',', $check->types ?? [])) ?>" data-gw-check-ownership="<?= Html::encode($check->ownership) ?>">
                <?= $form->field($model, "checks[$id]")->dropDownList(['' => 'Not assessed'] + array_intersect_key(DevicePosture::resultLabels(), [DevicePosture::RESULT_PASS => 1, DevicePosture::RESULT_FAIL => 1]))
                    ->label(Html::encode($check->title) . ' <span class="gw-muted gw-mono">' . Html::encode($check->reference) . '</span>', ['encode' => false]) ?>
            </div>
        <?php endforeach; ?>
        <?php $patch = DeviceChecks::patchAge(); ?>
        <div class="col-md-4"><?= $form->field($model, 'last_patched_at')->input('date', ['max' => date('Y-m-d')])->hint($patch
            ? implode(' ', array_map(static fn($c) => sprintf('%s fails if older than %d days.', $c->reference, $c->maxAgeDays), $patch))
            : 'No patch-age check is in force.') ?></div>
    </div>
    <p class="form-text gw-field__hint mb-0">Only the checks that apply to this device type and ownership are shown. Checks are defined in the Control Register.</p>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Notes</legend>
    <?= $form->field($model, 'notes')->textarea(['rows' => 3])->label(false) ?>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton($model->isNewRecord ? 'Add device' : 'Save changes', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
