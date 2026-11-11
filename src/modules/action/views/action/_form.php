<?php
/**
 * @var yii\web\View $this
 * @var app\modules\action\models\Action $model
 */

use app\modules\action\models\Action;
use app\widgets\ActiveForm;
use yii\helpers\Html;

?>
<?php $form = ActiveForm::begin(['id' => 'action-form', 'options' => ['class' => 'gw-form gw-form--action']]); ?>
<?= $form->errorSummary($model, ['class' => 'alert alert-danger gw-error-summary']) ?>

<fieldset class="gw-fieldset">
    <legend class="gw-fieldset__title">Action</legend>
    <div class="row">
        <div class="col-12"><?= $form->field($model, 'title')->textInput(['maxlength' => true, 'placeholder' => 'e.g. Turn on MFA for the remaining 4 admin accounts']) ?></div>
        <div class="col-md-6 col-xl-3"><?= $form->field($model, 'owner_id')->dropDownList($model->ownerOptions(), ['prompt' => '— Select —']) ?></div>
        <div class="col-md-6 col-xl-3"><?= $form->field($model, 'due_date')->input('date') ?></div>
        <div class="col-md-6 col-xl-3"><?= $form->field($model, 'priority')->dropDownList(Action::priorityOptions()) ?></div>
        <div class="col-md-6 col-xl-3"><?= $form->field($model, 'status')->dropDownList(Action::statusOptions()) ?></div>
        <div class="col-12"><?= $form->field($model, 'recordKey')->dropDownList($model->recordOptions(), ['prompt' => '— Standalone (an audit or test finding) —']) ?></div>
        <div class="col-12"><?= $form->field($model, 'description')->textarea(['rows' => 4]) ?></div>
        <div class="col-12" data-gw-depends="#action-status" data-gw-value="done,cancelled">
            <?= $form->field($model, 'outcome')->textarea(['rows' => 3]) ?>
        </div>
    </div>
</fieldset>

<div class="gw-form__actions">
    <?= Html::submitButton($model->isNewRecord ? 'Create action' : 'Save changes', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', $model->isNewRecord ? ($model->getRecord()?->getViewUrl() ?? ['index']) : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
