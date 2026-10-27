<?php
/**
 * @var yii\web\View $this
 * @var app\models\ProfileForm $model
 */

use app\helpers\Badge;
use app\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = 'My profile';
$this->params['breadcrumbs'][] = $this->title;
$user = $model->getUser();
$fmt = Yii::$app->formatter;
?>
<div class="row g-4">
    <div class="col-lg-7">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Profile details</h2>
            <?php $form = ActiveForm::begin(); ?>
            <div class="mb-3 gw-field">
                <label class="form-label gw-field__label">Username</label>
                <input type="text" class="form-control" value="<?= Html::encode($user->username) ?>" disabled>
            </div>
            <?= $form->field($model, 'full_name') ?>
            <?= $form->field($model, 'email')->input('email') ?>
            <?= $form->field($model, 'job_title') ?>
            <div class="gw-form__actions">
                <?= Html::submitButton('Save profile', ['class' => 'btn btn-primary gw-btn']) ?>
                <?= Html::a('Change password', ['password'], ['class' => 'btn btn-outline-secondary gw-btn']) ?>
            </div>
            <?php ActiveForm::end(); ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Account</h2>
            <dl class="gw-dl">
                <dt>Role</dt><dd><?= Badge::render('role', $user->getRoleName(), $user->getRoleLabel()) ?></dd>
                <dt>Last sign-in</dt><dd><?= $fmt->asDatetime($user->last_login_at) ?> <?= $user->last_login_ip ? '(' . Html::encode($user->last_login_ip) . ')' : '' ?></dd>
                <dt>Password changed</dt><dd><?= $fmt->asDatetime($user->password_changed_at) ?></dd>
                <dt>Member since</dt><dd><?= $fmt->asDate($user->created_at) ?></dd>
            </dl>
        </section>
    </div>
</div>
