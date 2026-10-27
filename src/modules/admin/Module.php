<?php

namespace app\modules\admin;

/**
 * Administration: users & roles, audit log, lookup lists.
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\admin\controllers';
    public $defaultRoute = 'user';
}
