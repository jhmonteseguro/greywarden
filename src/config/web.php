<?php

use yii\helpers\ArrayHelper;

$common = require __DIR__ . '/common.php';
$secureCookies = gw_env_bool('COOKIE_SECURE', false);

$config = [
    'id' => 'greywarden',
    // Branding: display name etc. from Admin › Settings (components/Branding)
    'bootstrap' => ['log', app\components\Branding::class],
    'defaultRoute' => 'dashboard/default/index',
    'as passwordGuard' => app\components\PasswordChangeGuard::class,
    'modules' => [
        'dashboard' => ['class' => app\modules\dashboard\Module::class],
        'risk' => ['class' => app\modules\risk\Module::class],
        'control' => ['class' => app\modules\control\Module::class],
        'decision' => ['class' => app\modules\decision\Module::class],
        'incident' => ['class' => app\modules\incident\Module::class],
        'inventory' => ['class' => app\modules\inventory\Module::class],
        'cloud' => ['class' => app\modules\cloud\Module::class],
        'attack' => ['class' => app\modules\attack\Module::class],
        'intel' => ['class' => app\modules\intel\Module::class],
        'admin' => ['class' => app\modules\admin\Module::class],
    ],
    'components' => [
        'request' => [
            'cookieValidationKey' => gw_cookie_key(dirname(__DIR__) . '/runtime'),
            'enableCsrfValidation' => true,
            'enableCookieValidation' => true,
            'csrfCookie' => ['httpOnly' => true, 'secure' => $secureCookies, 'sameSite' => yii\web\Cookie::SAME_SITE_LAX],
        ],
        'session' => [
            'name' => 'GWSESSID',
            // In runtime/ (a volume in production), so sessions survive a container upgrade
            'savePath' => '@runtime/sessions',
            'timeout' => (int) gw_env('SESSION_TIMEOUT', 28800),
            'cookieParams' => [
                'httponly' => true,
                'secure' => $secureCookies,
                'samesite' => yii\web\Cookie::SAME_SITE_LAX,
            ],
        ],
        'user' => [
            'identityClass' => app\models\User::class,
            'enableAutoLogin' => false,
            'authTimeout' => (int) gw_env('SESSION_TIMEOUT', 28800),
            'loginUrl' => ['/site/login'],
            'identityCookie' => ['name' => '_gwIdentity', 'httpOnly' => true, 'secure' => $secureCookies],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'assetManager' => [
            'appendTimestamp' => true,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                '' => 'dashboard/default/index',
                'login' => 'site/login',
                'logout' => 'site/logout',
                'search' => 'search/index',
                'branding/logo' => 'site/logo',
                'account/<action:[\w-]+>' => 'account/<action>',

                // Domain modules: /risks, /risks/12, /risks/12/update, /risks/create ...
                'risks' => 'risk/risk/index',
                'risks/<id:\d+>' => 'risk/risk/view',
                'risks/<id:\d+>/<action:[\w-]+>' => 'risk/risk/<action>',
                'risks/<action:[\w-]+>' => 'risk/risk/<action>',

                'controls' => 'control/control/index',
                'controls/<id:\d+>' => 'control/control/view',
                'controls/<id:\d+>/<action:[\w-]+>' => 'control/control/<action>',
                'controls/<action:[\w-]+>' => 'control/control/<action>',
                'frameworks' => 'control/framework/index',
                'frameworks/requirement/<id:\d+>' => 'control/framework/requirement',
                'frameworks/<framework:[\w-]+>' => 'control/framework/view',

                'decisions' => 'decision/decision/index',
                'decisions/<id:\d+>' => 'decision/decision/view',
                'decisions/<id:\d+>/<action:[\w-]+>' => 'decision/decision/<action>',
                'decisions/<action:[\w-]+>' => 'decision/decision/<action>',

                'incidents' => 'incident/incident/index',
                'incidents/<id:\d+>' => 'incident/incident/view',
                'incidents/<id:\d+>/<action:[\w-]+>' => 'incident/incident/<action>',
                'incidents/<action:[\w-]+>' => 'incident/incident/<action>',

                'devices' => 'inventory/device/index',
                'devices/<id:\d+>' => 'inventory/device/view',
                'devices/<id:\d+>/<action:[\w-]+>' => 'inventory/device/<action>',
                'devices/<action:[\w-]+>' => 'inventory/device/<action>',

                // Cloud Inventory: /cloud/assets, /cloud/assets/12, /cloud/accounts, /cloud/accounts/3/update ...
                'cloud/assets' => 'cloud/asset/index',
                'cloud/assets/<id:\d+>' => 'cloud/asset/view',
                'cloud/assets/<id:\d+>/<action:[\w-]+>' => 'cloud/asset/<action>',
                'cloud/assets/<action:[\w-]+>' => 'cloud/asset/<action>',
                'cloud/accounts' => 'cloud/account/index',
                'cloud/accounts/<id:\d+>' => 'cloud/account/view',
                'cloud/accounts/<id:\d+>/<action:[\w-]+>' => 'cloud/account/<action>',
                'cloud/accounts/<action:[\w-]+>' => 'cloud/account/<action>',

                'attachments/<id:\d+>' => 'attachment/download',
                'attachments/<id:\d+>/<action:[\w-]+>' => 'attachment/<action>',
                'attachments/<action:[\w-]+>' => 'attachment/<action>',

                // MITRE ATT&CK: /attack, /attack/T1566.001, /attack/mitigations, /attack/mitigations/M1017
                'attack' => 'attack/technique/index',
                'attack/mitigations' => 'attack/mitigation/index',
                'attack/mitigations/<id:[Mm]\d{4}>' => 'attack/mitigation/view',
                'attack/<id:[Tt]\d{4}(?:\.\d{3})?>' => 'attack/technique/view',

                // Threat intel: /intel, /intel/ip/203.0.113.7, /intel/asn/AS64496, /intel/blocklist, /intel/sources
                'intel' => 'intel/investigate/index',
                // IPv6 colons stay readable in the URL (the pattern only admits hex digits, ':' and '.')
                ['pattern' => 'intel/ip/<ip:[0-9A-Fa-f:.]+>', 'route' => 'intel/investigate/ip', 'encodeParams' => false],
                'intel/asn/<asn:(?:[Aa][Ss])?\d{1,10}>' => 'intel/investigate/asn',
                'intel/<action:(note|verdict)>' => 'intel/investigate/<action>',
                'intel/indicator/<id:\d+>' => 'intel/investigate/indicator',
                'intel/sources' => 'intel/source/index',
                'intel/sources/update' => 'intel/source/update-feeds',
                'intel/blocklist' => 'intel/blocklist/index',
                'intel/blocklist/<id:\d+>' => 'intel/blocklist/view',
                'intel/blocklist/<id:\d+>/<action:[\w-]+>' => 'intel/blocklist/<action>',
                'intel/blocklist/<action:[\w-]+>' => 'intel/blocklist/<action>',

                'admin/<controller:(user|audit|lookup)>' => 'admin/<controller>/index',
                'admin/<controller:(user|audit|lookup)>/<id:\d+>' => 'admin/<controller>/view',
                'admin/<controller:(user|audit|lookup)>/<id:\d+>/<action:[\w-]+>' => 'admin/<controller>/<action>',
                'admin/<controller:(user|audit|lookup)>/<action:[\w-]+>' => 'admin/<controller>/<action>',
                'admin/settings' => 'admin/settings/index',
            ],
        ],
    ],
];

if (YII_ENV_DEV) {
    $allowed = gw_env_list('DEBUG_ALLOWED_IPS', ['127.0.0.1', '::1']);
    if (class_exists(yii\debug\Module::class)) {
        $config['bootstrap'][] = 'debug';
        $config['modules']['debug'] = ['class' => yii\debug\Module::class, 'allowedIPs' => $allowed];
    }
    if (class_exists(yii\gii\Module::class)) {
        $config['bootstrap'][] = 'gii';
        $config['modules']['gii'] = ['class' => yii\gii\Module::class, 'allowedIPs' => $allowed];
    }
}

return ArrayHelper::merge($common, $config);
