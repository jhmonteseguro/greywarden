<?php

namespace app\modules\intel;

/**
 * Threat intel: IP address / ASN investigation against free intelligence
 * sources (registries, routing, reputation, exposure, DNSBLs and locally
 * cached threat feeds), analyst verdicts and notes, and the blocklist.
 *
 * Sources are defined in components\SourceRegistry, feeds in components\FeedCatalogue.
 * Optional API keys (AbuseIPDB, VirusTotal, abuse.ch, ...) are read from .env.
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\intel\controllers';
}
