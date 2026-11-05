<?php
/**
 * Above the risk and incident forms when they were opened from a story.
 *
 * @var yii\web\View $this
 * @var app\modules\threat\models\ThreatTriage|null $triage
 * @var string $finish what the form still needs from the analyst
 */

use yii\helpers\Html;

?>
<?php if ($triage): ?>
    <div class="alert alert-info gw-callout" role="status">
        <i class="bi bi-newspaper" aria-hidden="true"></i>
        <span>Filled in from <?= Html::a(Html::encode($triage->getReferenceValue()), $triage->getViewUrl(), ['class' => 'gw-ref']) ?>. <?= Html::encode($finish) ?> Saving links the two and marks the story relevant.</span>
    </div>
<?php endif; ?>
