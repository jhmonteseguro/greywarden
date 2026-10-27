<?php
/**
 * Main application layout: left sidebar + top bar + content area.
 *
 * View params used by pages:
 *   $this->title                    page heading (h1)
 *   $this->params['breadcrumbs']    breadcrumb trail
 *   $this->params['subtitle']       HTML under the heading (reference, badges)
 *   $this->params['actions']        HTML for header action buttons
 *
 * @var yii\web\View $this
 * @var string $content
 */

use app\assets\AppAsset;
use app\components\Branding;
use yii\bootstrap5\Breadcrumbs;
use yii\helpers\Html;

AppAsset::register($this);
$this->registerCsrfMetaTags();
$this->registerMetaTag(['charset' => Yii::$app->charset], 'charset');
$this->registerMetaTag(['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1']);
$this->registerMetaTag(['name' => 'robots', 'content' => 'noindex, nofollow']);
$this->registerLinkTag(['rel' => 'icon', 'type' => Branding::logoMimeType() ?? 'image/svg+xml', 'href' => Branding::logoUrl() ?? Yii::getAlias('@web/favicon.svg')]);
$this->registerLinkTag(['rel' => 'preload', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous', 'href' => Yii::getAlias('@web/fonts/inter-latin-wght-normal.woff2')]);
$route = Yii::$app->controller->route;
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>" class="h-100" data-bs-theme="dark">
<head>
    <?php /* Apply the remembered collapsed-sidebar state before first paint (see greywarden.js). */ ?>
    <script>try{if(localStorage.getItem('gw.sidebar')==='collapsed'){document.documentElement.classList.add('gw-sidebar-collapsed');}}catch(e){}</script>
    <title><?= Html::encode($this->title ? $this->title . ' · ' . Yii::$app->name : Yii::$app->name) ?></title>
    <?php $this->head() ?>
</head>
<body class="gw-body" data-route="<?= Html::encode($route) ?>">
<?php $this->beginBody() ?>
<a class="visually-hidden-focusable gw-skip-link" href="#gw-content">Skip to content</a>

<div class="gw-app" id="gw-app">
    <?= $this->render('_sidebar') ?>

    <div class="gw-main">
        <?= $this->render('_topbar') ?>

        <main class="gw-content" id="gw-content" tabindex="-1">
            <?php if ($this->title !== null && empty($this->params['hideHeader'])): ?>
                <header class="gw-page-header">
                    <div class="gw-page-header__text">
                        <?php if (!empty($this->params['breadcrumbs'])): ?>
                            <?= Breadcrumbs::widget([
                                'links' => $this->params['breadcrumbs'],
                                'homeLink' => ['label' => 'Dashboard', 'url' => Yii::$app->homeUrl],
                                'options' => ['class' => 'gw-breadcrumbs'],
                            ]) ?>
                        <?php endif; ?>
                        <h1 class="gw-page-title"><?= Html::encode($this->title) ?></h1>
                        <?php if (!empty($this->params['subtitle'])): ?>
                            <div class="gw-page-subtitle"><?= $this->params['subtitle'] ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($this->params['actions'])): ?>
                        <div class="gw-page-actions"><?= $this->params['actions'] ?></div>
                    <?php endif; ?>
                </header>
            <?php elseif (!empty($this->params['breadcrumbs'])): ?>
                <?= Breadcrumbs::widget([
                    'links' => $this->params['breadcrumbs'],
                    'homeLink' => ['label' => 'Dashboard', 'url' => Yii::$app->homeUrl],
                    'options' => ['class' => 'gw-breadcrumbs'],
                ]) ?>
            <?php endif; ?>

            <?= $this->render('_flash') ?>

            <?= $content ?>
        </main>

        <footer class="gw-footer">
            <span><?= Html::encode(Yii::$app->name) ?> &middot; security management</span>
            <?php if (YII_ENV_DEV): ?><span class="gw-footer__env"><span class="gw-footer__env-dot" aria-hidden="true"></span>Development mode</span><?php endif; ?>
        </footer>
    </div>
</div>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
