<?php
/**
 * @var yii\web\View $this
 * @var app\modules\admin\models\ResetPasswordForm $model
 * @var app\models\User $user
 */

use app\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = 'Reset password: ' . $user->username;
$this->params['breadcrumbs'][] = 'Admin';
$this->params['breadcrumbs'][] = ['label' => 'Users', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $user->username, 'url' => ['view', 'id' => $user->id]];
$this->params['breadcrumbs'][] = 'Reset password';
?>
<section class="gw-panel gw-panel--narrow">
    <p class="gw-muted">Set a temporary password and share it securely. Existing sessions for this user are signed out.</p>
    <?php $form = ActiveForm::begin(['id' => 'reset-password-form']); ?>
    <?= $form->field($model, 'password')->passwordInput(['autocomplete' => 'new-password']) ?>
    <?= $form->field($model, 'must_change_password')->checkbox() ?>
    <div class="gw-form__actions">
        <?= Html::submitButton('Reset password', ['class' => 'btn btn-warning gw-btn']) ?>
        <?= Html::a('Cancel', ['view', 'id' => $user->id], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
    </div>
    <?php ActiveForm::end(); ?>
</section>
