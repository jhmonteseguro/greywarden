<?php
/**
 * External tools for manual follow-up (open in a new tab).
 * @var yii\web\View $this
 * @var app\modules\intel\components\Indicator $indicator
 */

use app\modules\intel\components\Pivots;
use yii\helpers\Html;
?>
<p class="gw-muted gw-attack-tab__intro">These open the indicator on free third-party sites in a new tab. Nothing is sent until you click.</p>
<?php foreach (Pivots::for($indicator) as $group => $links): ?>
    <section class="gw-intel-group">
        <h2 class="gw-intel-group__title"><?= Html::encode($group) ?></h2>
        <ul class="gw-intel-pivots">
            <?php foreach ($links as $link): ?>
                <li>
                    <a class="gw-intel-pivot-card" href="<?= Html::encode($link['url']) ?>" target="_blank" rel="noopener noreferrer nofollow">
                        <span class="gw-intel-pivot-card__name"><?= Html::encode($link['label']) ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></span>
                        <span class="gw-intel-pivot-card__note"><?= Html::encode($link['note']) ?></span>
                        <span class="gw-intel-pivot-card__host gw-mono"><?= Html::encode(parse_url($link['url'], PHP_URL_HOST)) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
