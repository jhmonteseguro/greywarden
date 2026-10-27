<?php
/**
 * Full-screen "sweep" shown while an investigation runs (search submit, re-run).
 * Triggered by greywarden.js on [data-gw-scan] links / forms.
 * @var yii\web\View $this
 * @var app\modules\intel\components\Indicator|null $indicator
 */

use app\modules\intel\components\SourceRegistry;
use yii\helpers\Html;

$labels = array_values(array_map(static fn($s) => $s->label(), array_filter(SourceRegistry::all(), static fn($s) => $s->isEnabled())));
?>
<div class="gw-scan" id="gw-scan" hidden aria-live="polite" data-sources="<?= Html::encode(json_encode($labels)) ?>">
    <div class="gw-scan__box">
        <div class="gw-scan__radar" aria-hidden="true"><span class="gw-scan__sweep"></span><span class="gw-scan__blip"></span><span class="gw-scan__blip"></span></div>
        <p class="gw-scan__title">Interrogating <span data-gw-scan-count><?= count($labels) ?></span> intelligence sources</p>
        <p class="gw-scan__target gw-mono" data-gw-scan-target><?= Html::encode($indicator->value ?? '') ?></p>
        <p class="gw-scan__source gw-mono" data-gw-scan-source>&nbsp;</p>
    </div>
</div>
