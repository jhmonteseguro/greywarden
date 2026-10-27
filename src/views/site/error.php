<?php
/**
 * @var yii\web\View $this
 * @var string $name
 * @var string $message
 * @var Exception $exception
 */

use yii\helpers\Html;

$this->title = $name;
$this->params['hideHeader'] = true; // the error block below carries the heading
$code = $exception instanceof yii\web\HttpException ? $exception->statusCode : 500;
?>
<section class="gw-error gw-error--<?= (int) $code ?>">
    <div class="gw-error__code"><?= (int) $code ?></div>
    <h1 class="gw-error__title"><?= Html::encode($name) ?></h1>
    <p class="gw-error__message"><?= nl2br(Html::encode($message)) ?></p>
    <p><?= Html::a('<i class="bi bi-arrow-left"></i> Back to dashboard', Yii::$app->homeUrl, ['class' => 'btn btn-outline-secondary']) ?></p>
</section>
