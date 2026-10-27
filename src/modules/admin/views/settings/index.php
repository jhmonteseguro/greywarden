<?php
/**
 * Admin › Settings: branding.
 * @var yii\web\View $this
 * @var app\modules\admin\models\BrandingForm $model
 */

use app\components\Branding;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = 'Settings';
$this->params['breadcrumbs'][] = 'Admin';
$this->params['breadcrumbs'][] = 'Settings';
$hasLogo = Branding::logoName() !== null;
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
