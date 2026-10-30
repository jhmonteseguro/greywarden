<?php
/**
 * New report: pick the period. The figures are worked out when it is saved.
 * @var yii\web\View $this
 * @var app\modules\report\models\Report $model
 */

use app\modules\report\components\ReportPeriod;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = 'New management report';
$this->params['breadcrumbs'][] = ['label' => 'Management Reports', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
$presets = array_map(static fn(array $p) => $p['label'], ReportPeriod::presets()) + [ReportPeriod::CUSTOM => 'Custom dates (below)'];
$radio = static fn($index, $label, $name, $checked, $value) => Html::tag('div',
    Html::radio($name, $checked, ['value' => $value, 'class' => 'form-check-input', 'id' => $id = 'report-preset-' . $value])
    . Html::label(Html::encode($label), $id, ['class' => 'form-check-label']), ['class' => 'form-check']);
?>
<?php $form = ActiveForm::begin(['id' => 'report-form', 'options' => ['class' => 'gw-form gw-form--report']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Period</legend>
    <?= $form->field($model, 'preset')->radioList($presets, ['item' => $radio])->label(false) ?>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Custom dates</legend>
    <p class="form-text gw-field__hint">Only used with <em>Custom dates</em>. A period can end today at the latest and covers at most <?= ReportPeriod::MAX_DAYS ?> days.</p>
    <div class="row">
        <div class="col-md-4"><?= $form->field($model, 'period_type')->dropDownList(ReportPeriod::typeOptions()) ?></div>
        <div class="col-md-4"><?= $form->field($model, 'period_start')->input('date') ?></div>
        <div class="col-md-4"><?= $form->field($model, 'period_end', ['enableClientValidation' => false])->input('date', ['max' => date('Y-m-d')]) ?></div>
    </div>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Title</legend>
    <?= $form->field($model, 'title')->textInput(['maxlength' => true, 'placeholder' => 'Security posture report · October 2026'])->label(false) ?>
</fieldset>

<p class="gw-muted gw-small">The figures are worked out from the registers when you create the report and then frozen with it. You can write the management summary and regenerate the figures until the report is approved.</p>
<div class="gw-form__actions">
    <?= Html::submitButton('Create report', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', ['index'], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
