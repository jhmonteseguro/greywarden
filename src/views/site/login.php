<?php
/**
 * Sign-in page (layout: views/layouts/auth.php).
 * @var yii\web\View $this
 * @var app\models\LoginForm $model
 */

use app\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = 'Sign in';
?>
<section class="gw-auth__card gw-login">
    <h1 class="gw-auth__title">Sign in</h1>
    <p class="gw-auth__lead">Authorised personnel only. Activity is logged.</p>


    <?php $form = ActiveForm::begin(['id' => 'login-form', 'options' => ['class' => 'gw-form gw-login__form']]); ?>
        <?= $form->field($model, 'username')->textInput(['autofocus' => true, 'autocomplete' => 'username']) ?>
        <?= $form->field($model, 'password')->passwordInput(['autocomplete' => 'current-password']) ?>
        <div class="gw-form__actions">
            <?= Html::submitButton('<i class="bi bi-box-arrow-in-right"></i> Sign in', ['class' => 'btn btn-primary w-100 gw-btn gw-btn--login', 'name' => 'login-button']) ?>
        </div>
    <?php ActiveForm::end(); ?>
</section>
