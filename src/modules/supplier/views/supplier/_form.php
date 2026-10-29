<?php
/**
 * @var yii\web\View $this
 * @var app\modules\supplier\models\Supplier $model
 */

use app\models\Lookup;
use app\models\User;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use app\modules\supplier\components\SupplierChecks;
use app\modules\supplier\components\SupplierPosture;
use app\modules\supplier\models\Supplier;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$results = ['' => 'Not assessed'] + array_intersect_key(DevicePosture::resultLabels(), [SupplierPosture::RESULT_PASS => 1, SupplierPosture::RESULT_FAIL => 1]);
?>
<?php $form = ActiveForm::begin(['id' => 'supplier-form', 'options' => ['class' => 'gw-form gw-form--supplier']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Supplier</legend>
    <div class="row">
        <div class="col-md-8"><?= $form->field($model, 'name')->textInput(['maxlength' => true, 'placeholder' => 'e.g. Amazon Web Services']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'status')->dropDownList(Supplier::statusOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'category_id')->dropDownList(Lookup::options(Lookup::TYPE_SUPPLIER_CATEGORY, $model->category_id), ['prompt' => '— Select —']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'owner_id')->dropDownList(User::options($model->owner_id), ['prompt' => '— Unassigned —']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'website')->textInput(['maxlength' => true, 'placeholder' => 'https://']) ?></div>
        <div class="col-md-8"><?= $form->field($model, 'legal_name')->textInput(['maxlength' => true]) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'company_number')->textInput(['maxlength' => true, 'class' => 'form-control gw-mono']) ?></div>
        <div class="col-12"><?= $form->field($model, 'services')->textarea(['rows' => 2, 'placeholder' => 'e.g. Hosting for the customer portal and its databases']) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Exposure</legend>
    <div class="row">
        <div class="col-md-4"><?= $form->field($model, 'criticality')->dropDownList(Device::criticalityOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'data_classification')->dropDownList(Device::classificationOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'access_level')->dropDownList(Supplier::accessOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'personal_data')->checkbox() ?></div>
        <div class="col-md-8"><?= $form->field($model, 'data_locations')->textInput(['maxlength' => true]) ?></div>
    </div>
    <p class="form-text gw-field__hint mb-0">The tier is worked out from these when you save: high or critical criticality, restricted data or privileged access make
        a supplier Tier 1; medium criticality, confidential or personal data, or user access make it Tier 2; anything else is Tier 3.</p>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Contract</legend>
    <div class="row">
        <div class="col-md-4"><?= $form->field($model, 'contract_start')->input('date') ?></div>
        <div class="col-md-4"><?= $form->field($model, 'contract_end')->input('date') ?></div>
        <div class="col-md-4"><?= $form->field($model, 'notice_days')->input('number', ['min' => 0, 'max' => 3650]) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'dpa_status')->dropDownList(Supplier::dpaOptions(), ['prompt' => 'Unknown']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'breach_notice_hours')->input('number', ['min' => 0, 'max' => 8760]) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'right_to_audit')->dropDownList(Supplier::yesNoOptions(), ['prompt' => 'Unknown']) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Incidents &amp; exit</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($model, 'security_contact')->textInput(['maxlength' => true, 'placeholder' => 'e.g. security@example.com, +44 20 7946 0000']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'status_page_url')->textInput(['maxlength' => true, 'placeholder' => 'https://']) ?></div>
        <div class="col-12"><?= $form->field($model, 'exit_plan')->textarea(['rows' => 3]) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Review</legend>
    <div class="row">
        <div class="col-md-4"><?= $form->field($model, 'next_review_date')->input('date') ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset gw-supplier-checks">
    <legend class="gw-fieldset__title">Supplier checks</legend>
    <?php if (!SupplierChecks::manual()): ?>
        <p class="gw-muted">No supplier check recorded per supplier is in force. Give a control in the <?= Html::a('Control Register', ['/control/control/index']) ?> a supplier check to add one.</p>
    <?php endif; ?>
    <div class="row">
        <?php foreach (SupplierChecks::manual() as $id => $check): ?>
            <div class="col-md-4">
                <?= $form->field($model, "checks[$id]")->dropDownList($results)
                    ->label(Html::encode($check->title) . ' <span class="gw-muted gw-mono">' . Html::encode($check->reference) . '</span>', ['encode' => false])
                    ->hint($check->tiers ? 'Only ' . Html::encode(implode(', ', array_map([Supplier::class, 'tierShortLabel'], $check->tiers))) . ' suppliers' : null) ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (count(SupplierChecks::all()) > count(SupplierChecks::manual())): ?>
        <p class="form-text gw-field__hint mb-0">DPA and assurance checks are read from the DPA answer above and the Assurance tab.</p>
    <?php endif; ?>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Notes</legend>
    <?= $form->field($model, 'notes')->textarea(['rows' => 3])->label(false) ?>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton($model->isNewRecord ? 'Add supplier' : 'Save changes', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
