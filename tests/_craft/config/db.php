<?php

use craft\helpers\App;

return [
    'dsn' => sprintf(
        '%s:host=%s;port=%s;dbname=%s;',
        App::env('DB_DRIVER') ?: 'mysql',
        App::env('DB_SERVER') ?: '127.0.0.1',
        App::env('DB_PORT') ?: 3306,
        App::env('DB_DATABASE') ?: 'influx_tests',
    ),
    'user'        => App::env('DB_USER') ?: 'root',
    'password'    => App::env('DB_PASSWORD') ?: '',
    'schema'      => App::env('DB_SCHEMA') ?: 'public',
    'tablePrefix' => App::env('DB_TABLE_PREFIX') ?: '',
];
