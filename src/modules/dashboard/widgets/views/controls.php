<?php
/**
 * @var yii\web\View $this
 * @var array<string,int> $effectiveness implemented controls per effectiveness
 * @var int $implemented
 * @var int $planned planned or implementing
 * @var int $testOverdue
 * @var int $testDueSoon tests due within Control::testDueSoonDays()
 * @var array<string,array> $frameworks framework => FrameworkCoverage::summary()
 */

use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\models\Control;
use yii\helpers\Html;

$labels = Control::effectivenessOptions();
$order = [Control::EFFECTIVENESS_EFFECTIVE, Control::EFFECTIVENESS_PARTIAL, Control::EFFECTIVENESS_INEFFECTIVE, Control::EFFECTIVENESS_NOT_TESTED];
$base = max(1, $implemented);
// The implemented-controls column plus one per framework
$col = match (count($frameworks)) { 0 => 'col-12', 1 => 'col-lg-6', 2 => 'col-lg-4', default => 'col-md-6 col-xxl-3' };
?>
<div class="row g-4 gw-controls-panel">
    <div class="<?= $col ?>">
        <h3 class="gw-controls-panel__heading">Implemented controls</h3>
        <div class="gw-framework-progress">
            <div class="gw-framework-progress__headline">
                <span class="gw-framework-progress__value"><?= (int) $implemented ?></span>
                <span class="gw-framework-progress__caption"><strong><?= (int) $effectiveness[Control::EFFECTIVENESS_EFFECTIVE] ?></strong> tested effective
                    · <?= Html::a($planned . ' planned', ['/control/control/index', 'filter' => 'planned']) ?></span>
            </div>
            <div class="gw-framework-progress__bar" role="img" aria-label="<?= Html::encode(implode(', ', array_map(static fn($k) => $effectiveness[$k] . ' ' . strtolower($labels[$k]), $order))) ?>">
                <?php foreach ($order as $k): if (!$effectiveness[$k]) continue; ?>
                    <span class="gw-framework-progress__seg gw-framework-progress__seg--<?= $k ?>" style="width: <?= round($effectiveness[$k] / $base * 100, 2) ?>%"
                          title="<?= Html::encode($labels[$k] . ': ' . $effectiveness[$k]) ?>"></span>
                <?php endforeach; ?>
            </div>
            <ul class="gw-framework-progress__legend">
                <?php foreach ($order as $k): ?>
                    <li class="gw-framework-progress__legend-item gw-framework-progress__legend-item--<?= $k ?>">
                        <?= Html::a('<span class="gw-framework-progress__swatch" aria-hidden="true"></span> ' . Html::encode($labels[$k]) . ' <strong>' . (int) $effectiveness[$k] . '</strong>',
                            ['/control/control/index', 'filter' => 'implemented', 'effectiveness' => $k]) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($testOverdue): ?>
                <p class="gw-text-danger gw-small mb-0"><i class="bi bi-calendar-x" aria-hidden="true"></i>
                    <?= Html::a($testOverdue . ' control test(s) overdue', ['/control/control/index', 'filter' => 'test-overdue']) ?></p>
            <?php endif; ?>
            <?php if ($testDueSoon): ?>
                <p class="gw-text-warning gw-small mb-0"><i class="bi bi-calendar-event" aria-hidden="true"></i>
                    <?= Html::a(sprintf('%d due in the next %d days', $testDueSoon, Control::testDueSoonDays()), ['/control/control/index', 'filter' => 'test-due-soon']) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php foreach ($frameworks as $framework => $summary): ?>
        <div class="<?= $col ?>">
            <h3 class="gw-controls-panel__heading"><?= Html::a(Html::encode(FrameworkCatalogue::label($framework)), ['/control/framework/view', 'framework' => FrameworkCatalogue::slug($framework)]) ?></h3>
            <?= $this->render('@app/modules/control/views/framework/_progress', ['framework' => $framework, 'summary' => $summary]) ?>
        </div>
    <?php endforeach; ?>
</div>
