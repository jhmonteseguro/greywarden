<?php
/**
 * Admin › Settings: branding, the compliance frameworks switched on and the
 * wording of incident PDF reports (separate forms, so each saves on its own).
 * @var yii\web\View $this
 * @var app\modules\admin\models\BrandingForm $model
 * @var app\modules\admin\models\ComplianceForm $compliance
 * @var app\modules\admin\models\IncidentReportForm $incidentReport
 */

use app\components\Branding;
use app\modules\admin\models\ComplianceForm;
use app\modules\admin\models\IncidentReportForm;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\incident\components\IncidentWording;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = 'Settings';
$this->params['breadcrumbs'][] = 'Admin';
$this->params['breadcrumbs'][] = 'Settings';
$hasLogo = Branding::logoName() !== null;
// Bootstrap switches / checkboxes for checkboxList()
$check = static fn(string $class) => static fn($index, $label, $name, $checked, $value) => Html::tag('div',
    Html::checkbox($name, $checked, ['value' => $value, 'class' => 'form-check-input', 'id' => $id = Html::getInputIdByName($name) . '-' . $value])
    . Html::label(Html::encode($label), $id, ['class' => 'form-check-label']), ['class' => $class]);
?>
<?php $form = ActiveForm::begin(['id' => 'branding-form', 'options' => ['class' => 'gw-form gw-form--settings', 'enctype' => 'multipart/form-data']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Branding</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($model, 'app_name')->textInput(['maxlength' => true, 'placeholder' => Branding::productName()]) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'organisation')->textInput(['maxlength' => true, 'placeholder' => 'e.g. Example Ltd']) ?></div>
    </div>
    <div class="gw-logo-setting">
        <div class="gw-logo-setting__preview" aria-hidden="true">
            <?= $this->render('@app/views/layouts/_logo', ['class' => 'gw-logo-setting__image']) ?>
        </div>
        <div class="gw-logo-setting__fields">
            <?= $form->field($model, 'logoFile')->fileInput(['accept' => '.png,.jpg,.jpeg,image/png,image/jpeg']) ?>
            <?php if ($hasLogo): ?>
                <?= $form->field($model, 'remove_logo')->checkbox() ?>
            <?php endif; ?>
        </div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Sign-in page</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($model, 'tagline')->textInput(['maxlength' => true]) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'login_notice')->textInput(['maxlength' => true]) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">PDF exports</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($model, 'pdf_classification')->textInput(['maxlength' => true]) ?></div>
    </div>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton('Save settings', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', ['index'], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>

<?php $form = ActiveForm::begin(['id' => 'compliance-form', 'options' => ['class' => 'gw-form gw-form--settings']]); ?>
<?= $form->errorSummary($compliance, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset" id="compliance">
    <legend class="gw-fieldset__title">Compliance frameworks</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($compliance, 'frameworks')->checkboxList(ComplianceForm::frameworkOptions(), ['item' => $check('form-check form-switch')]) ?></div>
        <div class="col-md-6"><?= $form->field($compliance, 'soc2_categories')->checkboxList(FrameworkCatalogue::soc2OptionalCategories(), ['item' => $check('form-check')]) ?></div>
    </div>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton('Save frameworks', ['class' => 'btn btn-primary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>

<?php $form = ActiveForm::begin(['id' => 'incident-report-form', 'options' => ['class' => 'gw-form gw-form--settings']]); ?>
<?= $form->errorSummary($incidentReport, ['class' => 'alert alert-danger gw-error-summary']) ?>

<?php foreach (['incident-reports' => [IncidentWording::KIND_STATUS, 'Incident reports: what each status means'],
                'incident-report-severity' => [IncidentWording::KIND_SEVERITY, 'Incident reports: what each severity means']] as $id => [$kind, $legend]): ?>
<fieldset class="gw-fieldset" id="<?= $id ?>">
    <legend class="gw-fieldset__title"><?= Html::encode($legend) ?></legend>
    <p class="form-text gw-field__hint">Printed in plain English next to the incident's <?= $kind ?> in incident PDF reports, for management and non-technical readers. Clear a box to go back to the default wording.</p>
    <div class="row">
        <?php foreach (IncidentReportForm::attributesOf($kind) as $attribute): ?>
            <div class="col-md-6"><?= $form->field($incidentReport, $attribute)->textarea(['rows' => 2, 'maxlength' => IncidentReportForm::MAX_LENGTH]) ?></div>
        <?php endforeach; ?>
    </div>
</fieldset>
<?php endforeach; ?>

<div class="gw-form__actions">
    <?= Html::submitButton('Save incident report wording', ['class' => 'btn btn-primary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
