<?php
/**
 * @var yii\web\View $this
 * @var string $mode
 */

use app\modules\risk\widgets\RiskHeatmap;
use yii\helpers\Html;

$this->title = 'Risk heatmap';
$this->params['breadcrumbs'][] = ['label' => 'Risk Register', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'Heatmap';
$this->params['subtitle'] = 'Open risks plotted by likelihood and impact. Click a cell to see the risks in it.';
$this->params['actions'] = Html::a('<i class="bi bi-list-ul"></i> Register', ['index'], ['class' => 'btn btn-outline-secondary gw-btn']);
?>
<section class="gw-panel gw-panel--heatmap">
    <?= RiskHeatmap::widget(['mode' => $mode]) ?>
</section>
