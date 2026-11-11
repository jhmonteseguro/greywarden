<?php
/**
 * New recovery codes, shown once.
 * @var yii\web\View $this
 * @var string[] $codes
 */

use app\components\Mfa;
use yii\helpers\Html;

$this->title = 'Your recovery codes';
$this->params['breadcrumbs'][] = ['label' => 'Sign-in security', 'url' => ['security']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="row">
    <div class="col-lg-7">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Save these recovery codes now</h2>
            <p class="form-text gw-field__hint">
                Each code signs you in once if you lose your phone or passkeys. They are shown only this once: copy them into your
                password manager or print them. Any older codes no longer work.
            </p>
            <ol class="gw-codes">
                <?php foreach ($codes as $code): ?>
                    <li><?= Html::encode(Mfa::formatCode($code)) ?></li>
                <?php endforeach; ?>
            </ol>
            <div class="gw-form__actions">
                <?= Html::a("I've saved them", ['security'], ['class' => 'btn btn-primary gw-btn']) ?>
            </div>
        </section>
    </div>
</div>
