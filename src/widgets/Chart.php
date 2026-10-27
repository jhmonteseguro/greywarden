<?php

namespace app\widgets;

use app\assets\ChartAsset;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Json;

/**
 * Chart.js chart rendered from a JSON config. Colours are NOT set here:
 * each dataset carries a `tone` (e.g. "critical", "high", "success") and
 * web/js/gw-charts.js resolves it to the CSS variable --gw-chart-{tone}.
 *
 * Markup: div.gw-chart > canvas[data-gw-chart]
 *
 * Example:
 *   Chart::widget(['type' => 'bar', 'labels' => [...], 'datasets' => [['label' => 'High', 'data' => [...], 'tone' => 'high']], 'stacked' => true])
 */
class Chart extends Widget
{
    public string $type = 'bar';
    public array $labels = [];
    public array $datasets = [];
    public bool $stacked = false;
    public bool $horizontal = false;
    public ?string $ariaLabel = null;
    /** Optional: URLs per label index; clicking a bar navigates there */
    public array $links = [];
    /** Extra Chart.js options merged client-side */
    public array $options = [];
    public string $height = '280px';

    public function run(): string
    {
        ChartAsset::register($this->view);
        $config = [
            'type' => $this->type,
            'labels' => $this->labels,
            'datasets' => $this->datasets,
            'stacked' => $this->stacked,
            'horizontal' => $this->horizontal,
            'links' => $this->links,
            'options' => (object) $this->options,
        ];
        $canvas = Html::tag('canvas', '', [
            'id' => $this->getId(),
            'data-gw-chart' => Json::encode($config),
            'role' => 'img',
            'aria-label' => $this->ariaLabel ?? 'Chart',
        ]);
        return Html::tag('div', $canvas, ['class' => 'gw-chart gw-chart--' . $this->type, 'style' => "height: {$this->height}"]);
    }
}
