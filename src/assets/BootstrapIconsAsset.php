<?php

namespace app\assets;

use yii\web\AssetBundle;

/** Bootstrap Icons webfont (self-hosted from vendor/npm-asset). */
class BootstrapIconsAsset extends AssetBundle
{
    public $sourcePath = '@npm/bootstrap-icons/font';
    public $css = ['bootstrap-icons.min.css'];
    public $publishOptions = ['only' => ['bootstrap-icons.min.css', 'fonts/*']];
}
