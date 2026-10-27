<?php
/**
 * @var yii\web\View $this
 * @var app\models\Lookup $model
 */

use app\models\Lookup;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = $model->isNewRecord ? 'Add lookup value' : 'Edit “' . $model->name . '”';
$this->params['breadcrumbs'][] = 'Admin';
$this->params['breadcrumbs'][] = ['label' => 'Lookups', 'url' => ['index', 'type' => $model->type]];
$this->params['breadcrumbs'][] = $model->isNewRecord ? 'New' : 'Edit';
?>
<section class="gw-panel gw-panel--narrow">
    <?php $form = ActiveForm::begin(['id' => 'lookup-form']); ?>
    <?= $form->field($model, 'type')->dropDownList(Lookup::types(), ['disabled' => !$model->isNewRecord]) ?>
    <?= $form->field($model, 'name')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'description')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'sort_order')->input('number', ['min' => 0]) ?>
    <?= $form->field($model, 'is_active')->checkbox() ?>
    <div class="gw-form__actions">
        <?= Html::submitButton('Save', ['class' => 'btn btn-primary gw-btn']) ?>
        <?= Html::a('Cancel', ['index', 'type' => $model->type], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
    </div>
    <?php ActiveForm::end(); ?>
</section>
