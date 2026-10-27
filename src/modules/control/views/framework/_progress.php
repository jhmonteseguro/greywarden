<?php
/**
 * Stacked progress bar + legend for a framework summary. Each legend entry
 * links to the framework page filtered by that state.
 *
 * Markup: div.gw-framework-progress > div.gw-framework-progress__bar > span.gw-framework-progress__seg--{state}
 *                                   > ul.gw-framework-progress__legend
 *
 * @var yii\web\View $this
 * @var string $framework
 * @var array $summary FrameworkCoverage::summary()
 */

use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\components\FrameworkCoverage;
use yii\helpers\Html;

$labels = FrameworkCoverage::stateLabels();
$applicable = max(1, $summary['applicable']);
?>
<div class="gw-framework-progress">
    <div class="gw-framework-progress__headline">
        <span class="gw-framework-progress__value"><?= (int) $summary['percent'] ?><small>%</small></span>
        <span class="gw-framework-progress__caption"><strong><?= (int) $summary['met'] ?></strong> of <?= (int) $summary['applicable'] ?> applicable requirements met
            <?php if ($summary['unverified']): ?><span class="gw-muted">· <?= (int) $summary['unverified'] ?> not yet tested</span><?php endif; ?></span>
    </div>
    <div class="gw-framework-progress__bar" role="img" aria-label="<?= Html::encode(sprintf('%d met, %d partly met, %d planned, %d gaps', $summary['met'], $summary['partial'], $summary['planned'], $summary['gap'])) ?>">
        <?php foreach ([FrameworkCoverage::STATE_MET, FrameworkCoverage::STATE_PARTIAL, FrameworkCoverage::STATE_PLANNED, FrameworkCoverage::STATE_GAP] as $state): if (!$summary[$state]) continue; ?>
            <span class="gw-framework-progress__seg gw-framework-progress__seg--<?= $state ?>" style="width: <?= round($summary[$state] / $applicable * 100, 2) ?>%"
                  title="<?= Html::encode($labels[$state] . ': ' . $summary[$state]) ?>"></span>
        <?php endforeach; ?>
    </div>
    <ul class="gw-framework-progress__legend">
        <?php foreach ($labels as $state => $label): ?>
            <li class="gw-framework-progress__legend-item gw-framework-progress__legend-item--<?= $state ?>">
                <?= Html::a('<span class="gw-framework-progress__swatch" aria-hidden="true"></span> ' . Html::encode($label) . ' <strong>' . (int) $summary[$state] . '</strong>',
                    ['/control/framework/view', 'framework' => FrameworkCatalogue::slug($framework), 'state' => $state]) ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
