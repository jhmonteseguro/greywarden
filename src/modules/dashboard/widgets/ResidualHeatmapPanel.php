<?php

namespace app\modules\dashboard\widgets;

use app\modules\risk\widgets\RiskHeatmap;
use Yii;
use yii\helpers\Html;

/** 5x5 heatmap of open risks (residual by default, toggle to inherent). */
class ResidualHeatmapPanel extends PanelWidget
{
    public string $title = 'Risk heatmap';
    public string $icon = 'grid-3x3';
    public string $key = 'heatmap';

    protected function renderTools(): string
    {
        return Html::a('Open register', ['/risk/risk/index', 'filter' => 'open'], ['class' => 'gw-panel__link']);
    }

    protected function renderBody(): string
    {
        $mode = Yii::$app->request->get('heatmap', RiskHeatmap::MODE_RESIDUAL);
        return RiskHeatmap::widget(['mode' => is_string($mode) ? $mode : RiskHeatmap::MODE_RESIDUAL, 'compact' => true]);
    }
}
