<?php

namespace app\modules\action;

/**
 * Actions: remediation actions raised from any record (or on their own).
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\action\controllers';
    public $defaultRoute = 'action';
}
