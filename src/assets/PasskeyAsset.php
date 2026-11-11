<?php

namespace app\assets;

use yii\web\AssetBundle;

/** Passkey sign-in and registration buttons (web/js/gw-passkeys.js) */
class PasskeyAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $js = ['js/gw-passkeys.js'];
    public $depends = [AppAsset::class];
}
