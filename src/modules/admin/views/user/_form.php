<?php
/**
 * @var yii\web\View $this
 * @var app\modules\admin\models\UserForm $model
 */

use app\components\Rbac;
use app\models\User;
use app\widgets\ActiveForm;
use yii\helpers\Html;

?>
<section class="gw-panel gw-panel--narrow">
<?php $form = ActiveForm::begin(['id' => 'user-form']); ?>
<div class="row">
    <div class="col-md-6"><?= $form->field($model, 'full_name') ?></div>
    <div class="col-md-6"><?= $form->field($model, 'job_title') ?></div>
    <div class="col-md-6"><?= $form->field($model, 'username')->textInput(['autocomplete' => 'off']) ?></div>
    <div class="col-md-6"><?= $form->field($model, 'email')->input('email') ?></div>
    <div class="col-md-6"><?= $form->field($model, 'role')->dropDownList(Rbac::roleLabels()) ?></div>
    <div class="col-md-6"><?= $form->field($model, 'status')->dropDownList(User::statusLabels()) ?></div>
    <?php if ($model->isNew): ?>
        <div class="col-md-6"><?= $form->field($model, 'password')->passwordInput(['autocomplete' => 'new-password']) ?></div>
    <?php endif; ?>
    <div class="col-12"><?= $form->field($model, 'must_change_password')->checkbox() ?></div>
</div>
<div class="gw-form__actions">
    <?= Html::submitButton($model->isNew ? 'Create user' : 'Save changes', ['class' => 'btn btn-primary gw-btn']) ?>
    <?= Html::a('Cancel', $model->isNew ? ['index'] : ['view', 'id' => $model->getUser()->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
</div>
<?php ActiveForm::end(); ?>
</section>
