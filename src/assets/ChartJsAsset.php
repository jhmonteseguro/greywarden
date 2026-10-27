<?php

namespace app\assets;

use yii\web\AssetBundle;

/** Chart.js 4 UMD build (self-hosted from vendor/npm-asset). */
class ChartJsAsset extends AssetBundle
{
    public $sourcePath = '@npm/chart.js/dist';
    public $js = ['chart.umd.min.js'];
    public $publishOptions = ['only' => ['chart.umd.min.js', 'chart.umd.min.js.map']];
}
