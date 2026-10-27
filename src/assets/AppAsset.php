<?php

namespace app\assets;

use yii\web\AssetBundle;

/**
 * Main application asset bundle.
 *
 * ALL custom styling lives in web/css/greywarden.css (theme tokens at the top).
 * Behaviour lives in web/js/greywarden.js.
 */
class AppAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = [
        'css/greywarden.css',
    ];
    public $js = [
        'js/greywarden.js',
    ];
    public $depends = [
        \yii\web\YiiAsset::class,
        \yii\bootstrap5\BootstrapAsset::class,
        \yii\bootstrap5\BootstrapPluginAsset::class,
        BootstrapIconsAsset::class,
    ];
}
