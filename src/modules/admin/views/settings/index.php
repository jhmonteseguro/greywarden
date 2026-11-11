<?php
/**
 * Admin › Settings: branding, the compliance frameworks switched on, the
 * wording of incident PDF reports, management reports, edge monitoring and
 * the backup directory (separate forms, so each saves on its own).
 * @var yii\web\View $this
 * @var app\modules\admin\models\BrandingForm $model
 * @var app\modules\admin\models\ComplianceForm $compliance
 * @var app\modules\admin\models\IncidentReportForm $incidentReport
 * @var app\modules\admin\models\ReportForm $report
 * @var app\modules\admin\models\EdgeForm $edge
 * @var app\modules\admin\models\BackupForm $backup
 * @var app\modules\admin\models\SecurityForm $security
 */

use app\components\Branding;
use app\components\Mfa;
use app\modules\admin\models\BackupForm;
use app\modules\admin\models\ComplianceForm;
use app\modules\admin\models\IncidentReportForm;
use app\modules\admin\models\ReportForm;
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

<?php $form = ActiveForm::begin(['id' => 'security-form', 'options' => ['class' => 'gw-form gw-form--settings']]); ?>
<?= $form->errorSummary($security, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset" id="security">
    <legend class="gw-fieldset__title">Sign-in security</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($security, 'mfa_required')->dropDownList(Mfa::requiredOptions()) ?></div>
    </div>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton('Save sign-in security', ['class' => 'btn btn-primary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>

<?php $form = ActiveForm::begin(['id' => 'report-settings-form', 'options' => ['class' => 'gw-form gw-form--settings']]); ?>
<?= $form->errorSummary($report, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset" id="management-reports">
    <legend class="gw-fieldset__title">Management reports</legend>
    <div class="row">
        <div class="col-md-6"><?= $form->field($report, 'risk_appetite')->dropDownList(ReportForm::appetiteOptions()) ?></div>
    </div>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton('Save management report settings', ['class' => 'btn btn-primary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>

<?php $form = ActiveForm::begin(['id' => 'edge-form', 'options' => ['class' => 'gw-form gw-form--settings']]); ?>
<?= $form->errorSummary($edge, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset" id="edge">
    <legend class="gw-fieldset__title">Edge monitoring</legend>
    <p class="form-text gw-field__hint">How Threat Intel › Edge Findings reads your Cloudflare traffic.</p>
    <div class="row">
        <div class="col-md-6"><?= $form->field($edge, 'ignore_paths')->textarea(['rows' => 4, 'placeholder' => "/wp-admin\n/api/"]) ?></div>
        <div class="col-md-6"><?= $form->field($edge, 'allowlist')->textarea(['rows' => 4, 'placeholder' => "203.0.113.10   # office\nAS64500        # uptime monitor"]) ?></div>
    </div>
    <div class="row">
        <div class="col-md-4"><?= $form->field($edge, 'min_score')->input('number', ['min' => 1, 'max' => 99]) ?></div>
        <div class="col-md-4"><?= $form->field($edge, 'block_days')->input('number', ['min' => 1, 'max' => 365]) ?></div>
        <div class="col-md-4"><?= $form->field($edge, 'volume_rpm')->input('number', ['min' => 1]) ?></div>
    </div>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton('Save edge monitoring', ['class' => 'btn btn-primary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>

<?php $form = ActiveForm::begin(['id' => 'backup-form', 'options' => ['class' => 'gw-form gw-form--settings']]); ?>
<?= $form->errorSummary($backup, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset" id="backups">
    <legend class="gw-fieldset__title">Backups</legend>
    <div class="row">
        <div class="col-md-8"><?= $form->field($backup, 'backup_path')->textInput(['maxlength' => BackupForm::MAX_LENGTH, 'placeholder' => BackupForm::DEFAULT_PATH, 'spellcheck' => 'false', 'autocomplete' => 'off']) ?></div>
    </div>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton('Save backup settings', ['class' => 'btn btn-primary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
