<?php

/**
 * Yii / Craft application config used by craftcms/test-framework when it
 * boots the feature suite. Pulls credentials from the same env vars Craft
 * itself consumes — see tests/.env.example.
 */

use craft\helpers\App;

return [
    'id' => App::env('APP_ID') ?: 'CraftCMS--influx-tests',
    'modules' => [],
    'components' => [
        'db' => require __DIR__ . '/config/db.php',
        'mailer' => [
            'useFileTransport' => true,
            'fileTransportPath' => __DIR__ . '/storage/runtime/mail',
        ],
    ],
];
