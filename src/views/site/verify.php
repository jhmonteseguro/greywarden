<?php
/**
 * Second step of signing in (layout: views/layouts/auth.php).
 * @var yii\web\View $this
 * @var app\models\MfaCodeForm $model
 * @var app\models\User|null $user null once the sign-in was locked
 * @var bool $hasTotp
 * @var bool $passkeys
 */

use app\assets\PasskeyAsset;
use app\widgets\ActiveForm;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Two-step sign-in';
if ($passkeys) {
    PasskeyAsset::register($this);
}
?>
<section class="gw-auth__card gw-login">
    <h1 class="gw-auth__title">Two-step sign-in</h1>
    <?php if ($user === null): ?>
        <?= Html::error($model, 'code', ['class' => 'alert alert-danger gw-flash']) ?>
        <p class="gw-auth__lead">Start again from the sign-in page.</p>
        <?= Html::a('Back to sign in', ['/site/login'], ['class' => 'btn btn-primary w-100 gw-btn']) ?>
    <?php else: ?>
        <p class="gw-auth__lead">
            <?= $hasTotp ? 'Enter the 6-digit code from your authenticator app' : 'Use your passkey' ?>, or a recovery code.
        </p>

        <?php if ($passkeys): ?>
            <div class="gw-login__alt gw-login__alt--first" data-gw-passkey-error="#passkey-error">
                <?= Html::button('<i class="bi bi-person-badge"></i> Use a passkey', [
                    'class' => 'btn ' . ($hasTotp ? 'btn-outline-secondary' : 'btn-primary') . ' w-100 gw-btn',
                    'data-gw-passkey' => 'login',
                    'data-mode' => 'second-step',
                    'data-options-url' => Url::to(['/site/passkey-options']),
                    'data-submit-url' => Url::to(['/site/passkey']),
                    'autofocus' => !$hasTotp,
                ]) ?>
                <p id="passkey-error" class="gw-login__error" role="alert" hidden></p>
                <span class="gw-login__or">or</span>
            </div>
        <?php endif; ?>

        <?php $form = ActiveForm::begin(['id' => 'verify-form', 'options' => ['class' => 'gw-form gw-login__form']]); ?>
            <?= $form->field($model, 'code')->textInput([
                'autofocus' => $hasTotp, 'autocomplete' => 'one-time-code', 'inputmode' => $hasTotp ? 'numeric' : 'text',
                'placeholder' => $hasTotp ? '123456' : 'xxxx-xxxx-xxxx-xxxx', 'spellcheck' => 'false',
            ])->label($hasTotp ? 'Code from the app or a recovery code' : 'Recovery code') ?>
            <div class="gw-form__actions">
                <?= Html::submitButton('<i class="bi bi-shield-check"></i> Verify', ['class' => 'btn btn-primary w-100 gw-btn', 'name' => 'verify-button']) ?>
            </div>
        <?php ActiveForm::end(); ?>
        <p class="gw-login__hint">Signing in as <strong><?= Html::encode($user->username) ?></strong>. <?= Html::a('Not you?', ['/site/login']) ?></p>
        <p class="gw-login__hint">Lost your phone and your recovery codes? Ask an administrator to reset your two-step sign-in.</p>
    <?php endif; ?>
</section>
