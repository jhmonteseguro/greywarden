<?php
/**
 * Dashboard: renders the KPI and panel widgets configured in dashboard\Module.
 *
 * @var yii\web\View $this
 * @var array $kpis
 * @var array $panels
 */

use yii\helpers\Html;

$this->title = 'Security dashboard';
$this->params['subtitle'] = '<span class="gw-live" title="Figures are calculated when the page loads"><span class="gw-live__dot" aria-hidden="true"></span>Live</span>'
    . '<span class="gw-page-subtitle__text">Snapshot as of <time class="gw-mono">' . Html::encode(Yii::$app->formatter->asDatetime(time())) . '</time></span>';
?>
<section class="gw-kpis" aria-label="Key indicators">
    <?php foreach ($kpis as $kpi):
        $config = is_array($kpi) ? $kpi : ['class' => $kpi];
        $class = $config['class'];
        unset($config['class']); ?>
        <div class="gw-kpis__item"><?= $class::widget($config) ?></div>
    <?php endforeach; ?>
</section>

<div class="row g-3 gw-dashboard">
    <?php foreach ($panels as $panel):
        $config = is_array($panel) ? $panel : ['class' => $panel];
        $class = $config['class'];
        $span = $config['span'] ?? 'col-xl-6';
        unset($config['class'], $config['span']); ?>
        <div class="<?= Html::encode($span) ?>"><?= $class::widget($config) ?></div>
    <?php endforeach; ?>
</div>
