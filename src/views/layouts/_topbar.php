<?php
/**
 * Top bar: drawer toggle (mobile), global search ("/" to focus), UTC clock, user menu.
 * @var yii\web\View $this
 */

use app\models\User;
use yii\helpers\Html;
use yii\helpers\Url;

/** @var User $identity */
$identity = Yii::$app->user->identity;
?>
<header class="gw-topbar">
    <button type="button" class="gw-topbar__toggle" data-gw-toggle="sidebar" aria-controls="gw-sidebar" aria-label="Toggle navigation">
        <i class="bi bi-list" aria-hidden="true"></i>
    </button>

    <form class="gw-topbar__search" action="<?= Url::to(['/search/index']) ?>" method="get" role="search">
        <label for="gw-global-search" class="visually-hidden">Search</label>
        <div class="gw-search">
            <i class="bi bi-search gw-search__icon" aria-hidden="true"></i>
            <input type="search" id="gw-global-search" name="q" class="form-control gw-search__input" maxlength="100"
                   value="<?= Html::encode(Yii::$app->request->get('q') !== null && Yii::$app->controller->id === 'search' ? Yii::$app->request->get('q') : '') ?>"
                   placeholder="Search references, titles, hostnames, serials…" autocomplete="off" aria-keyshortcuts="/">
            <kbd class="gw-search__kbd" title="Press / to search">/</kbd>
        </div>
    </form>

    <div class="gw-topbar__right">
        <div class="gw-clock" title="Current time (UTC)">
            <i class="bi bi-clock" aria-hidden="true"></i>
            <time class="gw-clock__time" data-gw-clock><?= gmdate('H:i') ?></time>
            <span class="gw-clock__zone">UTC</span>
        </div>
        <div class="dropdown gw-usermenu">
            <button class="gw-usermenu__toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
                <span class="gw-avatar" aria-hidden="true"><?= Html::encode($identity->getInitials()) ?></span>
                <span class="gw-usermenu__who">
                    <span class="gw-usermenu__name"><?= Html::encode($identity->getDisplayName()) ?></span>
                    <span class="gw-usermenu__role"><?= Html::encode($identity->getRoleLabel()) ?></span>
                </span>
                <i class="bi bi-chevron-down gw-usermenu__caret" aria-hidden="true"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end gw-usermenu__menu">
                <li class="gw-usermenu__header">
                    <span class="gw-avatar gw-avatar--lg" aria-hidden="true"><?= Html::encode($identity->getInitials()) ?></span>
                    <span class="gw-usermenu__meta">
                        <strong><?= Html::encode($identity->getDisplayName()) ?></strong>
                        <span><?= Html::encode($identity->username) ?> &middot; <?= Html::encode($identity->email) ?></span>
                        <span class="gw-usermenu__role-chip"><?= Html::encode($identity->getRoleLabel()) ?></span>
                    </span>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><?= Html::a('<i class="bi bi-person-circle"></i> My profile', ['/account/profile'], ['class' => 'dropdown-item']) ?></li>
                <li><?= Html::a('<i class="bi bi-key"></i> Change password', ['/account/password'], ['class' => 'dropdown-item']) ?></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <?= Html::beginForm(['/site/logout'], 'post', ['class' => 'gw-logout-form']) ?>
                    <?= Html::submitButton('<i class="bi bi-box-arrow-right"></i> Sign out', ['class' => 'dropdown-item gw-logout']) ?>
                    <?= Html::endForm() ?>
                </li>
            </ul>
        </div>
    </div>
</header>
