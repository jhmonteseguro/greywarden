<?php
/**
 * Imported configuration of a cloud asset or account: the findings it raises and the settings themselves.
 *
 * @var yii\web\View $this
 * @var app\modules\cloud\models\CloudAsset|app\modules\cloud\models\CloudAccount $model
 * @var string $title panel title
 * @var string|null $seenAt when the configuration was last imported
 */

use app\helpers\Badge;
use yii\helpers\Html;

$findings = $model->getFindings();
$facts = $model->getConfigFacts();
?>
<section class="gw-panel gw-cloud-config" id="configuration">
    <h2 class="gw-panel__title"><i class="bi bi-sliders" aria-hidden="true"></i> <?= Html::encode($title) ?>
        <?php if ($seenAt): ?><span class="gw-muted gw-small gw-cloud-config__when">as imported <?= Yii::$app->formatter->asRelativeTime($seenAt) ?></span><?php endif; ?></h2>
    <div class="row g-4">
        <div class="col-lg-5">
            <h3 class="gw-cloud-config__heading">Findings</h3>
            <?php if ($findings): ?>
                <ul class="gw-cloud-findings">
                    <?php foreach ($findings as $f): ?>
                        <li class="gw-cloud-findings__item"><?= Badge::severity($f['severity']) ?> <span><?= Html::encode($f['title']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="gw-empty gw-cloud-findings--none"><i class="bi bi-check-circle" aria-hidden="true"></i> Nothing found in the imported settings.</p>
            <?php endif; ?>
        </div>
        <div class="col-lg-7">
            <h3 class="gw-cloud-config__heading">Settings</h3>
            <?php if ($facts): ?>
                <dl class="gw-dl gw-cloud-config__settings">
                    <?php foreach ($facts as [$label, $value]): ?>
                        <dt><?= Html::encode($label) ?></dt>
                        <dd><?= $value === '' ? '<span class="gw-null">&mdash;</span>' : Html::encode($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
            <?php else: ?>
                <p class="gw-empty">No settings recorded for this resource type.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
