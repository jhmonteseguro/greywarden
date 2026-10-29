<?php
/**
 * @var yii\web\View $this
 * @var app\modules\risk\models\Risk $model
 */

use app\helpers\RiskMatrix;
use app\models\Lookup;
use app\models\User;
use app\modules\attack\models\AttackTechnique;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\widgets\ActiveForm;
use yii\helpers\Html;
use yii\helpers\Json;

$bands = Json::encode(RiskMatrix::bands());
?>
<?php $form = ActiveForm::begin(['id' => 'risk-form', 'options' => ['class' => 'gw-form gw-form--risk']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Identification</legend>
    <div class="row">
        <div class="col-md-8"><?= $form->field($model, 'title')->textInput(['maxlength' => true]) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'status')->dropDownList(Risk::statusOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'category_id')->dropDownList(Lookup::options(Lookup::TYPE_RISK_CATEGORY, $model->category_id), ['prompt' => '— Select —']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'owner_id')->dropDownList(User::options($model->owner_id), ['prompt' => '— Select —']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'date_identified')->input('date') ?></div>
        <div class="col-12"><?= $form->field($model, 'scope')->textInput(['maxlength' => true, 'placeholder' => 'e.g. Finance file server, all laptops, customer portal']) ?></div>
        <div class="col-12"><?= $form->field($model, 'description')->textarea(['rows' => 3]) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'threat_source')->textarea(['rows' => 2]) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'vulnerability')->textarea(['rows' => 2]) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Inherent risk (before controls)</legend>
    <?= $this->render('_scoring-guide') ?>
    <div class="row align-items-end">
        <div class="col-md-4"><?= $form->field($model, 'inherent_likelihood')->dropDownList(RiskMatrix::axisOptions('likelihood'), ['prompt' => '—']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'inherent_impact')->dropDownList(RiskMatrix::axisOptions('impact'), ['prompt' => '—']) ?></div>
        <div class="col-md-4 mb-3">
            <div class="gw-score-preview" data-gw-score data-likelihood="#risk-inherent_likelihood" data-impact="#risk-inherent_impact" data-bands='<?= Html::encode($bands) ?>'>
                <span class="gw-score-preview__label">Inherent score</span>
                <span class="gw-badge gw-badge--neutral gw-score-preview__value">—</span>
            </div>
        </div>
        <div class="col-12"><?= $form->field($model, 'existing_controls')->textarea(['rows' => 3]) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Controls</legend>
    <?= $form->field($model, 'controlIds')->listBox(Control::selectOptions($model->controlIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect', 'data-gw-picker' => 'Filter controls, e.g. CTL-0003 or MFA'])
        ->label('Controls from the Control Register')
        ->hint('Implemented controls are the existing controls behind the residual score; planned ones are the treatment. The risk is flagged when one of them is retired, not effective or overdue for testing.') ?>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Treatment</legend>
    <div class="row">
        <div class="col-md-4"><?= $form->field($model, 'treatment')->dropDownList(Risk::treatmentOptions(), ['prompt' => '— Not decided —']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'target_treatment_date')->input('date') ?></div>
        <div class="col-md-4"><?= $form->field($model, 'next_review_date')->input('date') ?></div>
        <div class="col-12"><?= $form->field($model, 'treatment_plan')->textarea(['rows' => 3]) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Residual risk (current)</legend>
    <p class="form-text gw-field__hint">The risk today, counting only controls that are implemented and working. If nothing reduces it yet, set it equal to the inherent score.</p>
    <div class="row align-items-end">
        <div class="col-md-4"><?= $form->field($model, 'residual_likelihood')->dropDownList(RiskMatrix::axisOptions('likelihood'), ['prompt' => '— Not assessed —']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'residual_impact')->dropDownList(RiskMatrix::axisOptions('impact'), ['prompt' => '— Not assessed —']) ?></div>
        <div class="col-md-4 mb-3">
            <div class="gw-score-preview" data-gw-score data-likelihood="#risk-residual_likelihood" data-impact="#risk-residual_impact" data-bands='<?= Html::encode($bands) ?>'>
                <span class="gw-score-preview__label">Residual score</span>
                <span class="gw-badge gw-badge--neutral gw-score-preview__value">—</span>
            </div>
        </div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Target risk (after treatment)</legend>
    <p class="form-text gw-field__hint">The score expected once the treatment plan and planned controls are in place. Re-score the residual when they are implemented and tested.</p>
    <div class="row align-items-end">
        <div class="col-md-4"><?= $form->field($model, 'target_likelihood')->dropDownList(RiskMatrix::axisOptions('likelihood'), ['prompt' => '— Not set —']) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'target_impact')->dropDownList(RiskMatrix::axisOptions('impact'), ['prompt' => '— Not set —']) ?></div>
        <div class="col-md-4 mb-3">
            <div class="gw-score-preview" data-gw-score data-likelihood="#risk-target_likelihood" data-impact="#risk-target_impact" data-bands='<?= Html::encode($bands) ?>'>
                <span class="gw-score-preview__label">Target score</span>
                <span class="gw-badge gw-badge--neutral gw-score-preview__value">—</span>
            </div>
        </div>
    </div>
</fieldset>

<fieldset class="gw-fieldset" data-gw-depends="#risk-status" data-gw-value="accepted">
    <legend class="gw-fieldset__title">Risk acceptance</legend>
    <div class="row">
        <div class="col-12"><?= $form->field($model, 'acceptance_justification')->textarea(['rows' => 3]) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'accepted_by_id')->dropDownList(User::options($model->accepted_by_id), ['prompt' => '— Current user —']) ?></div>
        <div class="col-md-6"><?= $form->field($model, 'accepted_at')->input('date') ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">MITRE ATT&amp;CK</legend>
    <?= $form->field($model, 'techniqueIds')->listBox(AttackTechnique::selectOptions($model->techniqueIds ?? []), ['multiple' => true, 'size' => 10, 'class' => 'form-select gw-multiselect', 'data-gw-picker' => 'Filter techniques, e.g. T1486 or ransomware'])
        ->label('Threat techniques')
        ->hint('Adversary techniques that could realise this risk. They drive the ATT&CK matrix and the mitigation coverage shown on the risk.') ?>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Links</legend>
    <div class="row">
        <div class="col-md-6 col-xl-3"><?= $form->field($model, 'deviceIds')->listBox(Device::selectOptions($model->deviceIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect']) ?></div>
        <div class="col-md-6 col-xl-3"><?= $form->field($model, 'cloudAssetIds')->listBox(CloudAsset::selectOptions($model->cloudAssetIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect', 'data-gw-picker' => 'Filter cloud assets…']) ?></div>
        <div class="col-md-6 col-xl-3"><?= $form->field($model, 'incidentIds')->listBox(Incident::selectOptions($model->incidentIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect']) ?></div>
        <div class="col-md-6 col-xl-3"><?= $form->field($model, 'decisionIds')->listBox(Decision::selectOptions($model->decisionIds ?? []), ['multiple' => true, 'size' => 8, 'class' => 'form-select gw-multiselect']) ?></div>
    </div>
    <p class="form-text gw-field__hint">Hold Ctrl / Cmd to select multiple.</p>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton($model->isNewRecord ? 'Create risk' : 'Save changes', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
