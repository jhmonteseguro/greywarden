<?php

namespace app\assets;

use yii\web\AssetBundle;

/**
 * Greywarden chart initialiser (web/js/gw-charts.js). Colours are read from
 * CSS custom properties (--gw-chart-*) defined in greywarden.css.
 */
class ChartAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $js = ['js/gw-charts.js'];
    public $depends = [ChartJsAsset::class, AppAsset::class];
}
