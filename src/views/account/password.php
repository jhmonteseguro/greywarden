<?php
/**
 * @var yii\web\View $this
 * @var app\models\PasswordForm $model
 * @var bool $forced
 */

use app\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = 'Change password';
$this->params['breadcrumbs'][] = $this->title;
?>
<section class="gw-panel gw-panel--narrow">
    <?php if ($forced): ?>
        <div class="alert alert-warning gw-callout" role="alert">
            <i class="bi bi-shield-exclamation"></i> Your password was set by an administrator. Choose a new one to continue.
        </div>
    <?php endif; ?>
    <?php $form = ActiveForm::begin(['id' => 'password-form']); ?>
    <?= $form->field($model, 'current_password')->passwordInput(['autocomplete' => 'current-password', 'autofocus' => true]) ?>
    <?= $form->field($model, 'new_password')->passwordInput(['autocomplete' => 'new-password']) ?>
    <?= $form->field($model, 'new_password_repeat')->passwordInput(['autocomplete' => 'new-password']) ?>
    <div class="gw-form__actions">
        <?= Html::submitButton('Change password', ['class' => 'btn btn-primary gw-btn']) ?>
        <?php if (!$forced): ?>
            <?= Html::a('Cancel', ['profile'], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
        <?php endif; ?>
    </div>
    <?php ActiveForm::end(); ?>
</section>
