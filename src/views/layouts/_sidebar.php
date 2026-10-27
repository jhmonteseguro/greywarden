<?php
/**
 * Left sidebar navigation. Items are defined in components/Navigation.php.
 *
 * Desktop: fixed rail that can be collapsed to icons only (html.gw-sidebar-collapsed,
 * remembered in localStorage by greywarden.js). Below 992px it is an off-canvas
 * drawer opened with body.gw-sidebar-open.
 *
 * @var yii\web\View $this
 */

use app\components\Navigation;
use yii\helpers\Html;
use yii\helpers\Url;

?>
<aside class="gw-sidebar" id="gw-sidebar" aria-label="Main navigation">
    <div class="gw-sidebar__brand">
        <a href="<?= Url::home() ?>" class="gw-brand" aria-label="<?= Html::encode(Yii::$app->name) ?> home">
            <?= $this->render('@app/views/layouts/_logo', ['class' => 'gw-brand__logo']) ?>
            <span class="gw-brand__name"><?= Html::encode(Yii::$app->name) ?></span>
        </a>
        <button type="button" class="gw-sidebar__close" data-gw-toggle="sidebar" aria-label="Close navigation">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>
    <nav class="gw-nav">
        <?php foreach (Navigation::visibleSections() as $section): ?>
            <div class="gw-nav__section">
                <h2 class="gw-nav__heading"><span><?= Html::encode($section['label']) ?></span></h2>
                <ul class="gw-nav__list">
                    <?php foreach ($section['items'] as $item): ?>
                        <li class="gw-nav__item">
                            <a href="<?= Url::to($item['url']) ?>" class="gw-nav__link<?= $item['isActive'] ? ' is-active' : '' ?>"
                               data-gw-label="<?= Html::encode($item['label']) ?>"<?= $item['isActive'] ? ' aria-current="page"' : '' ?>>
                                <i class="bi bi-<?= Html::encode($item['icon']) ?> gw-nav__icon" aria-hidden="true"></i>
                                <span class="gw-nav__label"><?= Html::encode($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="gw-sidebar__footer">
        <button type="button" class="gw-sidebar__collapse" data-gw-collapse="sidebar" aria-controls="gw-sidebar" aria-expanded="true" title="Collapse sidebar">
            <i class="bi bi-chevron-double-left gw-sidebar__collapse-icon" aria-hidden="true"></i>
            <span class="gw-nav__label">Collapse</span>
        </button>
    </div>
</aside>
<div class="gw-sidebar-backdrop" data-gw-toggle="sidebar"></div>
