<?php
/**
 * "Needs attention" queue. Each group gets a severity tone (left stripe,
 * icon and count colour) so the panel reads like a SOC alert queue.
 *
 * @var yii\web\View $this
 * @var array $groups
 */

use yii\helpers\Html;

$any = array_sum(array_column($groups, 'total')) > 0;
/** group key => tone (critical|high|medium|info) */
$tones = [
    'critical-incidents' => 'critical',
    'regulator' => 'critical',
    'risk-treatment' => 'high',
    'non-compliant' => 'high',
    'weak-controls' => 'high',
    'control-tests' => 'medium',
    'control-targets' => 'medium',
    'control-tests-soon' => 'info',
    'not-assessed' => 'info',
    'risk-review' => 'medium',
    'stale-devices' => 'medium',
    'cloud-exposed' => 'critical',
    'cloud-non-compliant' => 'high',
    'cloud-accounts' => 'high',
    'cloud-unsupported-os' => 'high',
    'stale-cloud' => 'medium',
    'warranty' => 'info',
];
?>
<?php if (!$any): ?>
    <div class="gw-empty-state gw-empty-state--ok">
        <i class="bi bi-check2-circle" aria-hidden="true"></i>
        <p>Nothing needs attention right now.</p>
    </div>
<?php else: ?>
<div class="gw-attention">
    <?php foreach ($groups as $group): if (!$group['total']) continue;
        $tone = $tones[$group['key']] ?? 'info'; ?>
        <div class="gw-attention__group gw-attention__group--<?= Html::encode($group['key']) ?> gw-tone--<?= $tone ?>">
            <h3 class="gw-attention__title">
                <span class="gw-attention__icon" aria-hidden="true"><i class="bi bi-<?= Html::encode($group['icon']) ?>"></i></span>
                <span class="gw-attention__name"><?= Html::encode($group['label']) ?></span>
                <span class="gw-attention__count"><?= (int) $group['total'] ?></span>
                <?= Html::a('View all <i class="bi bi-chevron-right" aria-hidden="true"></i>', $group['url'], ['class' => 'gw-attention__more']) ?>
            </h3>
            <ul class="gw-attention__list">
                <?php foreach ($group['items'] as $item): ?>
                    <li class="gw-attention__item">
                        <?= Html::a(Html::encode($item->getReferenceValue()), $item->getViewUrl(), ['class' => 'gw-ref']) ?>
                        <span class="gw-attention__label"><?= Html::encode($item->getTitleValue()) ?></span>
                        <span class="gw-attention__meta"><?= Html::encode(($group['meta'])($item)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
