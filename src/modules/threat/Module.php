<?php

namespace app\modules\threat;

/**
 * Threat Watch: security news, advisories and exploited vulnerabilities gathered from
 * public feeds, grouped into stories, enriched with CISA KEV, EPSS and CVSS, and ranked
 * against the organisation's watchlist for triage.
 *
 * Sources, articles, stories and CVEs are shared public facts; the watchlist and triage
 * belong to an organisation (app\models\Organisation).
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\threat\controllers';
}
