<?php
/**
 * Minimal layout for the login and error pages shown to guests.
 * @var yii\web\View $this
 * @var string $content
 */

use app\assets\AppAsset;
use app\components\Branding;
use yii\helpers\Html;

AppAsset::register($this);
$this->registerCsrfMetaTags();
$this->registerMetaTag(['charset' => Yii::$app->charset], 'charset');
$this->registerMetaTag(['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1']);
$this->registerMetaTag(['name' => 'robots', 'content' => 'noindex, nofollow']);
$this->registerLinkTag(['rel' => 'icon', 'type' => Branding::logoMimeType() ?? 'image/svg+xml', 'href' => Branding::logoUrl() ?? Yii::getAlias('@web/favicon.svg')]);
$this->registerLinkTag(['rel' => 'preload', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous', 'href' => Yii::getAlias('@web/fonts/inter-latin-wght-normal.woff2')]);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>" class="h-100" data-bs-theme="dark">
<head>
    <title><?= Html::encode($this->title ? $this->title . ' · ' . Yii::$app->name : Yii::$app->name) ?></title>
    <?php $this->head() ?>
</head>
<body class="gw-body gw-body--auth">
<?php $this->beginBody() ?>
<div class="gw-auth__backdrop" aria-hidden="true"></div>
<main class="gw-auth">
    <div class="gw-auth__brand">
        <?= $this->render('@app/views/layouts/_logo', ['class' => 'gw-auth__logo']) ?>
        <div class="gw-auth__wordmark">
            <span class="gw-auth__name"><?= Html::encode(Yii::$app->name) ?></span>
            <?php if (($tagline = Branding::tagline()) !== ''): ?><span class="gw-auth__tagline"><?= Html::encode($tagline) ?></span><?php endif; ?>
        </div>
    </div>
    <?= $this->render('_flash') ?>
    <?= $content ?>
    <?php if (($notice = Branding::loginNotice()) !== ''): ?>
        <p class="gw-auth__footer"><i class="bi bi-lock" aria-hidden="true"></i> <?= Html::encode($notice) ?></p>
    <?php endif; ?>
</main>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
