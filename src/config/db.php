<?php

return [
    'class' => yii\db\Connection::class,
    'dsn' => sprintf(
        'mysql:host=%s;port=%s;dbname=%s',
        gw_env('DB_HOST', 'db'),
        gw_env('DB_PORT', '3306'),
        gw_env('DB_NAME', 'greywarden')
    ),
    'username' => gw_env('DB_USER', 'greywarden'),
    'password' => gw_env('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'enableSchemaCache' => !YII_DEBUG,
    'schemaCacheDuration' => 3600,
    'schemaCache' => 'cache',
];
