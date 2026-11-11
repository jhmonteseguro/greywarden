<?php
/**
 * Confirm the password before sign-in methods change.
 * @var yii\web\View $this
 * @var app\models\ConfirmPasswordForm $model
 * @var string $return
 */

use app\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = 'Confirm your password';
$this->params['breadcrumbs'][] = ['label' => 'Sign-in security', 'url' => ['security']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="row">
    <div class="col-lg-6">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Confirm your password</h2>
            <p class="form-text gw-field__hint">Changing how you sign in needs your password again. You will not be asked again for <?= intdiv(\app\components\Mfa::CONFIRM_TTL, 60) ?> minutes.</p>
            <?php $form = ActiveForm::begin(['action' => ['confirm', 'return' => $return]]); ?>
            <?= $form->field($model, 'password')->passwordInput(['autofocus' => true, 'autocomplete' => 'current-password']) ?>
            <div class="gw-form__actions">
                <?= Html::submitButton('Confirm', ['class' => 'btn btn-primary gw-btn']) ?>
                <?= Html::a('Cancel', ['security'], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
            </div>
            <?php ActiveForm::end(); ?>
        </section>
    </div>
</div>
