<?php
/**
 * @var yii\web\View $this
 * @var app\modules\cloud\models\CloudAccount $model
 */

use app\models\User;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\components\CloudPosture;
use app\modules\cloud\models\CloudAccount;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use app\modules\supplier\models\Supplier;
use app\widgets\ActiveForm;
use yii\helpers\Html;

// "AWS account ID: 111122223333 · Microsoft Azure: Subscription ID ..." as one hint
$identifiers = implode(' · ', array_filter(array_map(
    static fn($p) => $p === 'other' ? null : CloudCatalogue::providerLabel($p) . ': ' . CloudCatalogue::identifierLabel($p),
    array_keys(CloudCatalogue::providerOptions())
)));
?>
<?php $form = ActiveForm::begin(['id' => 'cloud-account-form', 'options' => ['class' => 'gw-form gw-form--cloud-account']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Account</legend>
    <div class="row">
        <div class="col-md-4"><?= $form->field($model, 'provider')->dropDownList(CloudCatalogue::providerOptions(), ['prompt' => '— Select —']) ?></div>
        <div class="col-md-8"><?= $form->field($model, 'name')->textInput(['maxlength' => true, 'placeholder' => 'e.g. Production (AWS)']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'account_identifier')->textInput(['maxlength' => true, 'class' => 'form-control gw-mono'])->hint($identifiers) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'owner_id')->dropDownList(User::options($model->owner_id), ['prompt' => '— Unassigned —']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'supplier_id')->dropDownList(Supplier::selectOptions($model->supplier_id ? [$model->supplier_id] : []), ['prompt' => '— Not linked —']) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Lifecycle &amp; classification</legend>
    <div class="row">
        <div class="col-md-3"><?= $form->field($model, 'environment')->dropDownList(CloudAccount::environmentOptions()) ?></div>
        <div class="col-md-3"><?= $form->field($model, 'status')->dropDownList(CloudAccount::statusOptions()) ?></div>
        <div class="col-md-3"><?= $form->field($model, 'criticality')->dropDownList(Device::criticalityOptions()) ?></div>
        <div class="col-md-3"><?= $form->field($model, 'data_classification')->dropDownList(Device::classificationOptions()) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset gw-cloud-checks">
    <legend class="gw-fieldset__title">Account checks</legend>
    <?php if (!CloudChecks::forAccounts()): ?>
        <p class="gw-muted">No account-level cloud checks are in force. Give a control in the <?= Html::a('Control Register', ['/control/control/index']) ?> a cloud check that applies to cloud accounts to add one.</p>
    <?php endif; ?>
    <div class="row">
        <?php foreach (CloudChecks::forAccounts() as $id => $check): ?>
            <div class="col-md-4">
                <?= $form->field($model, "checks[$id]")->dropDownList(['' => 'Not assessed'] + array_intersect_key(DevicePosture::resultLabels(), [CloudPosture::RESULT_PASS => 1, CloudPosture::RESULT_FAIL => 1]))
                    ->label(Html::encode($check->title) . ' <span class="gw-muted gw-mono">' . Html::encode($check->reference) . '</span>', ['encode' => false]) ?>
            </div>
        <?php endforeach; ?>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Notes</legend>
    <?= $form->field($model, 'notes')->textarea(['rows' => 3])->label(false) ?>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton($model->isNewRecord ? 'Add account' : 'Save changes', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
