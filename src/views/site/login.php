<?php
/**
 * Sign-in page (layout: views/layouts/auth.php).
 * @var yii\web\View $this
 * @var app\models\LoginForm $model
 * @var bool $passkeys passkeys can be used on this address
 */

use app\assets\PasskeyAsset;
use app\widgets\ActiveForm;
use yii\helpers\Html;
use yii\helpers\Url;

if ($passkeys) {
    PasskeyAsset::register($this);
}

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
    <?php if ($passkeys): ?>
        <div class="gw-login__alt" data-gw-passkey-error="#passkey-error">
            <span class="gw-login__or">or</span>
            <?= Html::button('<i class="bi bi-person-badge"></i> Sign in with a passkey', [
                'class' => 'btn btn-outline-secondary w-100 gw-btn',
                'data-gw-passkey' => 'login',
                'data-mode' => 'passwordless',
                'data-options-url' => Url::to(['/site/passkey-options']),
                'data-submit-url' => Url::to(['/site/passkey']),
            ]) ?>
            <p id="passkey-error" class="gw-login__error" role="alert" hidden></p>
        </div>
    <?php endif; ?>
</section>
