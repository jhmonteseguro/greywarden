<?php
/**
 * Set up an authenticator app.
 * @var yii\web\View $this
 * @var app\models\TotpSetupForm $model
 * @var string $secret base32
 * @var string $uri otpauth:// URI in the QR code
 */

use app\components\Totp;
use app\helpers\QrCode;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = 'Set up an authenticator app';
$this->params['breadcrumbs'][] = ['label' => 'Sign-in security', 'url' => ['security']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="row g-4">
    <div class="col-lg-7">
        <section class="gw-panel">
            <h2 class="gw-panel__title">1. Scan the code</h2>
            <p class="form-text gw-field__hint">Open your authenticator app, add an account and scan this code.</p>
            <div class="gw-qr mb-3"><img src="<?= Html::encode(QrCode::dataUri($uri)) ?>" alt="QR code for your authenticator app" width="200" height="200"></div>
            <p class="form-text gw-field__hint mb-1">Can't scan it? Enter this key instead (time-based, 6 digits):</p>
            <p class="gw-secret"><?= Html::encode(Totp::formatSecret($secret)) ?></p>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="gw-panel">
            <h2 class="gw-panel__title">2. Enter the code it shows</h2>
            <?php $form = ActiveForm::begin(['id' => 'totp-form']); ?>
            <?= $form->field($model, 'code')->textInput(['autofocus' => true, 'autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'placeholder' => '123456', 'maxlength' => 16]) ?>
            <div class="gw-form__actions">
                <?= Html::submitButton('Turn on', ['class' => 'btn btn-primary gw-btn']) ?>
                <?= Html::a('Cancel', ['security'], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
            </div>
            <?php ActiveForm::end(); ?>
        </section>
    </div>
</div>
