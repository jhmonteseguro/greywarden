<?php
/**
 * @var yii\web\View $this
 * @var string $mode inherent|residual|target
 * @var array $counts [likelihood][impact] => count
 * @var int $total
 * @var int $unassessed
 * @var bool $showToggle
 * @var string $toggleParam
 * @var bool $compact
 * @var array $likelihoodLabels
 * @var array $impactLabels
 */

use app\helpers\RiskMatrix;
use yii\helpers\Html;
use yii\helpers\Url;

?>
<div class="gw-heatmap<?= $compact ? ' gw-heatmap--compact' : '' ?>" data-mode="<?= Html::encode($mode) ?>">
    <?php if ($showToggle): ?>
        <div class="gw-heatmap__toggle gw-segmented" role="group" aria-label="Heatmap mode">
            <?php foreach (['inherent' => 'Inherent', 'residual' => 'Residual', 'target' => 'Target'] as $key => $label): ?>
                <?= Html::a($label, Url::current([$toggleParam => $key]), [
                    'class' => 'gw-segmented__item gw-heatmap__mode' . ($mode === $key ? ' is-active' : ''),
                    'aria-pressed' => $mode === $key ? 'true' : 'false',
                ]) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <table class="gw-heatmap__grid" aria-label="<?= Html::encode(ucfirst($mode)) ?> risk heatmap">
        <tbody>
        <?php for ($l = 5; $l >= 1; $l--): ?>
            <tr>
                <?php if ($l === 5): ?>
                    <th class="gw-heatmap__axis gw-heatmap__axis--y" rowspan="5" scope="rowgroup"><span>Likelihood</span></th>
                <?php endif; ?>
                <th class="gw-heatmap__label gw-heatmap__label--y" scope="row" title="<?= Html::encode($likelihoodLabels[$l]) ?>">
                    <span class="gw-heatmap__num"><?= $l ?></span>
                    <span class="gw-heatmap__text"><?= Html::encode($likelihoodLabels[$l]) ?></span>
                </th>
                <?php for ($i = 1; $i <= 5; $i++):
                    $score = $l * $i;
                    $rating = RiskMatrix::rating($score);
                    $n = $counts[$l][$i] ?? 0;
                    $url = ['/risk/risk/index', 'filter' => 'open', "{$mode}_likelihood" => $l, "{$mode}_impact" => $i];
                ?>
                    <td class="gw-heatmap__cell gw-heatmap__cell--<?= $rating ?><?= $n ? ' has-risks' : ' is-empty' ?>"
                        data-score="<?= $score ?>" data-count="<?= $n ?>"
                        title="<?= Html::encode(sprintf('L%d × I%d = %d (%s): %d risk(s)', $l, $i, $score, RiskMatrix::ratingLabel($rating), $n)) ?>">
                        <?php if ($n): ?>
                            <?= Html::a('<span class="gw-heatmap__count">' . $n . '</span><span class="gw-heatmap__score" aria-hidden="true">' . $score . '</span>', $url, [
                                'class' => 'gw-heatmap__link',
                                'aria-label' => sprintf('%d risk(s) with likelihood %d and impact %d (score %d, %s)', $n, $l, $i, $score, RiskMatrix::ratingLabel($rating)),
                            ]) ?>
                        <?php else: ?>
                            <span class="gw-heatmap__count">0</span><span class="gw-heatmap__score" aria-hidden="true"><?= $score ?></span>
                        <?php endif; ?>
                    </td>
                <?php endfor; ?>
            </tr>
        <?php endfor; ?>
        <tr>
            <td colspan="2"></td>
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <th class="gw-heatmap__label gw-heatmap__label--x" scope="col" title="<?= Html::encode($impactLabels[$i]) ?>">
                    <span class="gw-heatmap__num"><?= $i ?></span>
                    <span class="gw-heatmap__text"><?= Html::encode($impactLabels[$i]) ?></span>
                </th>
            <?php endfor; ?>
        </tr>
        <tr>
            <td colspan="2"></td>
            <th class="gw-heatmap__axis gw-heatmap__axis--x" colspan="5" scope="colgroup">Impact</th>
        </tr>
        </tbody>
    </table>

    <div class="gw-heatmap__footer">
        <ul class="gw-heatmap__legend">
            <?php foreach (RiskMatrix::bands() as $band):
                $range = RiskMatrix::scoreRange($band['key']); ?>
                <li class="gw-heatmap__legend-item gw-heatmap__legend-item--<?= Html::encode($band['key']) ?>">
                    <span class="gw-heatmap__swatch"></span><?= Html::encode($band['label']) ?> (<?= $range[0] ?>–<?= $range[1] ?>)
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="gw-heatmap__summary">
            <strong class="gw-heatmap__total"><?= $total ?></strong> open risk(s) plotted<?php if ($unassessed): ?>; <?= $unassessed ?> without a <?= $mode === 'target' ? 'target score' : 'residual assessment' ?><?php endif; ?>.
        </p>
    </div>
</div>
