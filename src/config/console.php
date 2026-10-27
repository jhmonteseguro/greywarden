<?php

use yii\helpers\ArrayHelper;

$common = require __DIR__ . '/common.php';

$config = [
    'id' => 'greywarden-console',
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'controllerMap' => [
        'migrate' => [
            'class' => yii\console\controllers\MigrateController::class,
            'migrationPath' => ['@app/migrations', '@yii/rbac/migrations'],
            'templateFile' => '@app/components/views/migration.php',
        ],
    ],
];

if (YII_ENV_DEV && class_exists(yii\gii\Module::class)) {
    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = ['class' => yii\gii\Module::class];
}

return ArrayHelper::merge($common, $config);
