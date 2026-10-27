<?php

namespace app\modules\incident;

/**
 * Incident Log module (NIST SP 800-61 lifecycle).
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\incident\controllers';
    public $defaultRoute = 'incident';
}
