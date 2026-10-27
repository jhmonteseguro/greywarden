<?php
/**
 * KPI tile. Tone (critical|high|warning|success|info|neutral) drives the accent
 * stripe and icon colour via .gw-kpi--{tone}; breakdown items use the same tones.
 *
 * @var yii\web\View $this
 * @var string $label
 * @var string $icon
 * @var string $id
 * @var array $data
 */

use yii\helpers\Html;
use yii\helpers\Url;

$tag = $data['url'] ? 'a' : 'div';
$attrs = ['class' => 'gw-kpi gw-kpi--' . $data['tone'], 'id' => $id];
if ($data['url']) {
    $attrs['href'] = Url::to($data['url']);
}
?>
<?= Html::beginTag($tag, $attrs) ?>
    <div class="gw-kpi__head">
        <span class="gw-kpi__label"><?= Html::encode($label) ?></span>
        <span class="gw-kpi__icon" aria-hidden="true"><i class="bi bi-<?= Html::encode($icon) ?>"></i></span>
    </div>
    <div class="gw-kpi__value"><?= Html::encode((string) $data['value']) ?><?php if ($data['unit']): ?><span class="gw-kpi__unit"><?= Html::encode($data['unit']) ?></span><?php endif; ?></div>
    <?php if ($data['breakdown']): ?>
        <ul class="gw-kpi__breakdown">
            <?php foreach ($data['breakdown'] as $item): ?>
                <li class="gw-kpi__breakdown-item gw-kpi__breakdown-item--<?= Html::encode($item['tone'] ?? 'neutral') ?>">
                    <span class="gw-kpi__dot" aria-hidden="true"></span>
                    <span class="gw-kpi__breakdown-label"><?= Html::encode($item['label']) ?></span>
                    <strong class="gw-kpi__breakdown-value"><?= Html::encode((string) $item['value']) ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($data['caption']): ?>
        <div class="gw-kpi__caption"><?= Html::encode($data['caption']) ?></div>
    <?php endif; ?>
    <?php if ($data['url']): ?>
        <i class="bi bi-arrow-up-right gw-kpi__go" aria-hidden="true"></i>
    <?php endif; ?>
<?= Html::endTag($tag) ?>
