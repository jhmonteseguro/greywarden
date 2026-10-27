<?php

namespace app\modules\risk;

/**
 * Risk Register module (5x5 inherent/residual risk assessment).
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\risk\controllers';
    public $defaultRoute = 'risk';
}
