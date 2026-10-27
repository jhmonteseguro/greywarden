<?php

/**
 * PHPUnit bootstrap: boots a Yii console application against the TEST database
 * (DB_TEST_NAME, default greywarden_test) so tests never touch real data.
 */
putenv('DB_NAME=' . (getenv('DB_TEST_NAME') ?: 'greywarden_test'));
putenv('YII_ENV=test');

// No credentials for external services: tests must never reach Cloudflare or a keyed intel API
// (HTTP is faked as well; individual tests set their own fake values).
foreach (['CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ZONE_ID', 'CLOUDFLARE_RULE_NAME', 'ABUSEIPDB_API_KEY', 'VIRUSTOTAL_API_KEY',
             'GREYNOISE_API_KEY', 'OTX_API_KEY', 'ABUSECH_AUTH_KEY', 'IPINFO_TOKEN', 'INTEL_ENABLE_IPAPI', 'INTEL_DISABLE', 'ADMIN_PASSWORD'] as $secret) {
    putenv($secret);
}

// PHPUnit owns error handling (warnings in views fail the test); Yii must not replace it
define('YII_ENABLE_ERROR_HANDLER', false);

require __DIR__ . '/../config/bootstrap.php';
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../config/console.php';
$config['components']['db']['enableSchemaCache'] = false;
$config['components']['cache'] = ['class' => yii\caching\ArrayCache::class];
// Expected warnings/errors logged by tests go to their own file, never the app log the smoke test checks
$config['components']['log']['targets'] = [[
    'class' => yii\log\FileTarget::class,
    'levels' => ['error', 'warning'],
    'logFile' => '@runtime/logs/test.log',
]];
// Console code that builds web URLs (intel links, PDF views) uses the web app's pretty URLs
$web = (static fn() => require __DIR__ . '/../config/web.php')();
$config['components']['urlManager'] = $web['components']['urlManager'] + ['scriptUrl' => '/index.php', 'baseUrl' => '', 'hostInfo' => 'http://localhost'];
// Attachments and logos never land in (or are deleted from) the real storage folder
$config['params']['attachments.path'] = sys_get_temp_dir() . '/gw-test-attachments';
$config['params']['branding.path'] = sys_get_temp_dir() . '/gw-test-branding';
new yii\console\Application($config);

if (!str_ends_with((string) Yii::$app->db->dsn, 'dbname=' . (getenv('DB_TEST_NAME') ?: 'greywarden_test'))) {
    fwrite(STDERR, 'Refusing to run: the tests are not connected to the test database (' . Yii::$app->db->dsn . ")\n");
    exit(1);
}

Yii::setAlias('@tests', __DIR__);
require_once __DIR__ . '/DbTestCase.php';
