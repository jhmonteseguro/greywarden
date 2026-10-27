<?php

namespace app\modules\attack;

/**
 * MITRE ATT&CK: the Enterprise catalogue (tactics, techniques, mitigations),
 * the matrix view and coverage of techniques seen in incidents / mapped to
 * risks against the mitigations adopted in the Decision Log.
 *
 * The catalogue is read-only in the UI; it is loaded by migration from
 * data/attack and refreshed with `php yii attack/update`.
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\attack\controllers';
}
