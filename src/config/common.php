<?php

/**
 * Configuration shared by the web and console applications.
 */
return [
    'basePath' => dirname(__DIR__),
    'name' => gw_env('APP_NAME', 'Greywarden'),
    'timeZone' => gw_env('APP_TIMEZONE', 'UTC'),
    'language' => 'en-GB',
    'sourceLanguage' => 'en-GB',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'components' => [
        'db' => require __DIR__ . '/db.php',
        'cache' => [
            'class' => yii\caching\FileCache::class,
        ],
        'authManager' => [
            'class' => yii\rbac\DbManager::class,
        ],
        'formatter' => [
            'class' => yii\i18n\Formatter::class,
            'dateFormat' => 'php:d M Y',
            'datetimeFormat' => 'php:d M Y H:i',
            'timeFormat' => 'php:H:i',
            'defaultTimeZone' => gw_env('APP_TIMEZONE', 'UTC'),
            'timeZone' => gw_env('APP_TIMEZONE', 'UTC'),
            'nullDisplay' => '<span class="gw-null">&mdash;</span>',
            'booleanFormat' => ['No', 'Yes'],
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                    'except' => ['yii\web\HttpException:404', 'yii\web\HttpException:403'],
                ],
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];
