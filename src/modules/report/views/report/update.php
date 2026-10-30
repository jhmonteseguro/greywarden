<?php
/**
 * The author's part of a draft report: title, overall status and management summary.
 * @var yii\web\View $this
 * @var app\modules\report\models\Report $model
 */

use app\helpers\Badge;
use app\modules\report\components\ReportBuilder;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = 'Edit ' . $model->reference;
$this->params['breadcrumbs'][] = ['label' => 'Management Reports', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->reference, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Edit';
$figures = $model->getFigures();
$meanings = ReportBuilder::ragMeanings();
$radio = static fn($index, $label, $name, $checked, $value) => Html::tag('div',
    Html::radio($name, $checked, ['value' => $value, 'class' => 'form-check-input', 'id' => $id = 'report-rag-' . $value])
    . Html::label(Badge::render('rag', $value, $label) . ' <span class="gw-muted">' . Html::encode($meanings[$value]) . '</span>', $id, ['class' => 'form-check-label']),
    ['class' => 'form-check']);
?>
<?php $form = ActiveForm::begin(['id' => 'report-form', 'options' => ['class' => 'gw-form gw-form--report']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title"><?= Html::encode($model->getPeriodName()) ?></legend>
    <?= $form->field($model, 'title')->textInput(['maxlength' => true])->hint(false) ?>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Overall status</legend>
    <?php if (!empty($figures['suggested_rag'])): ?>
        <p class="form-text gw-field__hint">The figures suggest <?= Badge::render('rag', $figures['suggested_rag'], ReportBuilder::ragOptions()[$figures['suggested_rag']]) ?>
            <?= $figures['rag_reasons'] ? Html::encode('because of: ' . implode('; ', $figures['rag_reasons']) . '.') : 'as nothing needs attention.' ?></p>
    <?php endif; ?>
    <?= $form->field($model, 'rag')->radioList(ReportBuilder::ragOptions(), ['item' => $radio])->label(false) ?>
</fieldset>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Management summary</legend>
    <?= $form->field($model, 'management_summary')->textarea(['rows' => 7, 'maxlength' => $model::SUMMARY_MAX])->label(false) ?>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton('Save changes', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
