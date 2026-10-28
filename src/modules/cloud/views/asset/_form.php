<?php
/**
 * @var yii\web\View $this
 * @var app\modules\cloud\models\CloudAsset $model
 */

use app\models\User;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\components\CloudPosture;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$computed = array_filter(CloudChecks::forAssets(), static fn($c) => !$c->isManual());
?>
<?php $form = ActiveForm::begin(['id' => 'cloud-asset-form', 'options' => ['class' => 'gw-form gw-form--cloud-asset']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Resource</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($model, 'account_id')->dropDownList(CloudAccount::selectOptions($model->account_id ? [$model->account_id] : []), ['prompt' => '— Select —'])
            ->hint(CloudAccount::find()->active()->exists() ? null : 'Add the ' . Html::a('cloud account', ['/cloud/account/create']) . ' first.') ?></div>
        <div class="col-md-6"><?= $form->field($model, 'resource_type')->dropDownList(CloudCatalogue::groupedTypeOptions(), ['prompt' => '— Select —']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'name')->textInput(['maxlength' => true, 'placeholder' => 'e.g. customer-exports bucket']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'region')->textInput(['maxlength' => true, 'placeholder' => 'e.g. eu-west-2']) ?></div>
        <div class="col-12"><?= $form->field($model, 'resource_id')->textInput(['maxlength' => true, 'class' => 'form-control gw-mono', 'placeholder' => 'e.g. arn:aws:s3:::customer-exports']) ?></div>
        <div class="col-md-6" data-gw-depends="#cloudasset-resource_type" data-gw-value="<?= Html::encode(implode(',', CloudCatalogue::osTypes())) ?>"><?= $form->field($model, 'os')->textInput(['maxlength' => true, 'placeholder' => 'e.g. Ubuntu']) ?></div>
        <div class="col-md-6" data-gw-depends="#cloudasset-resource_type" data-gw-value="<?= Html::encode(implode(',', CloudCatalogue::osTypes())) ?>"><?= $form->field($model, 'os_version')->textInput(['maxlength' => true, 'placeholder' => 'e.g. 24.04 LTS']) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Exposure &amp; data</legend>
    <div class="row">
        <div class="col-12"><?= $form->field($model, 'internet_facing')->checkbox() ?></div>
        <div class="col-md-6"><?= $form->field($model, 'public_ips')->textInput(['maxlength' => true, 'placeholder' => '203.0.113.10, 2001:db8::10']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'public_endpoints')->textInput(['maxlength' => true, 'placeholder' => 'portal.example.com, https://api.example.com']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'encryption_at_rest')->dropDownList(CloudAsset::encryptionOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'data_classification')->dropDownList(Device::classificationOptions()) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Ownership &amp; lifecycle</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($model, 'owner_id')->dropDownList(User::options($model->owner_id), ['prompt' => '— Unassigned —']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'environment')->dropDownList(CloudAccount::environmentOptions(), ['prompt' => '— Same as the account —']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'status')->dropDownList(CloudAsset::statusOptions()) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'criticality')->dropDownList(Device::criticalityOptions()) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset gw-cloud-checks">
    <legend class="gw-fieldset__title">Cloud checks</legend>
    <?php if (!CloudChecks::forAssets()): ?>
        <p class="gw-muted">No cloud checks are in force. Give a control in the <?= Html::a('Control Register', ['/control/control/index']) ?> a cloud check to add one.</p>
    <?php endif; ?>
    <div class="row">
        <?php foreach (CloudChecks::manualForAssets() as $id => $check): ?>
            <div class="col-md-4" data-gw-cloud-types="<?= Html::encode(implode(',', $check->assetTypes() ?? [])) ?>">
                <?= $form->field($model, "checks[$id]")->dropDownList(['' => 'Not assessed'] + array_intersect_key(DevicePosture::resultLabels(), [CloudPosture::RESULT_PASS => 1, CloudPosture::RESULT_FAIL => 1]))
                    ->label(Html::encode($check->title) . ' <span class="gw-muted gw-mono">' . Html::encode($check->reference) . '</span>', ['encode' => false]) ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="form-text gw-field__hint mb-0">Only the checks that apply to this resource type are shown. Checks are defined in the Control Register<?=
        $computed ? '; ' . Html::encode(implode(', ', array_map(static fn($c) => $c->reference, $computed))) . ' are worked out from the fields above' : '' ?>.</p>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Notes</legend>
    <?= $form->field($model, 'notes')->textarea(['rows' => 3])->label(false) ?>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton($model->isNewRecord ? 'Add asset' : 'Save changes', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
