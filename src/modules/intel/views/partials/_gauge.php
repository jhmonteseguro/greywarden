<?php
/**
 * Threat score ring (0-100). Fills on load; tick marks every 10 points.
 * @var int $score
 * @var string $tone   gw tone (critical, high, ...)
 * @var string $label  caption under the number
 * @var bool $special  no score (non-routable)
 */

$special ??= false;
$r = 54;
$c = 2 * M_PI * $r;
$offset = $c * (1 - max(0, min(100, $score)) / 100);
?>
<div class="gw-gauge gw-tone--<?= $tone ?>" role="img" aria-label="<?= $special ? 'No threat score' : 'Threat score ' . (int) $score . ' of 100' ?>" style="--gw-gauge-circ: <?= round($c, 2) ?>;">
    <svg viewBox="0 0 140 140" class="gw-gauge__svg" aria-hidden="true">
        <g class="gw-gauge__ticks">
            <?php for ($i = 0; $i < 40; $i++):
                $a = deg2rad($i * 9 - 90);
                $long = $i % 4 === 0;
                $r1 = $long ? 63 : 64.5;
                $r2 = 67; ?>
                <line x1="<?= round(70 + $r1 * cos($a), 2) ?>" y1="<?= round(70 + $r1 * sin($a), 2) ?>" x2="<?= round(70 + $r2 * cos($a), 2) ?>" y2="<?= round(70 + $r2 * sin($a), 2) ?>" class="<?= $long ? 'is-major' : '' ?>"/>
            <?php endfor; ?>
        </g>
        <circle class="gw-gauge__track" cx="70" cy="70" r="<?= $r ?>"/>
        <?php if (!$special): ?>
            <circle class="gw-gauge__value" cx="70" cy="70" r="<?= $r ?>" stroke-dasharray="<?= round($c, 2) ?>" stroke-dashoffset="<?= round($offset, 2) ?>" transform="rotate(-90 70 70)"/>
        <?php endif; ?>
        <circle class="gw-gauge__inner" cx="70" cy="70" r="44"/>
    </svg>
    <div class="gw-gauge__readout">
        <span class="gw-gauge__score"><?= $special ? '—' : (int) $score ?></span>
        <span class="gw-gauge__label"><?= yii\helpers\Html::encode($label) ?></span>
    </div>
</div>
