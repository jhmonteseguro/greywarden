<?php

namespace app\modules\report;

/**
 * Management reports: security posture over a month, quarter or other period,
 * frozen when generated, signed off, viewed in the browser or printed as PDF.
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\report\controllers';
    public $defaultRoute = 'report';
}
