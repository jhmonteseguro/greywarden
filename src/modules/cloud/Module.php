<?php

namespace app\modules\cloud;

/**
 * Cloud Inventory module: cloud accounts and the cloud assets in them.
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\cloud\controllers';
    public $defaultRoute = 'asset';
}
