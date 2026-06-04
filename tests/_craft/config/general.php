<?php

use craft\helpers\App;

return [
    'devMode' => true,
    'securityKey' => App::env('SECURITY_KEY') ?: 'test-security-key-32chars-or-more!!',
    'allowAdminChanges' => true,
    'disallowRobots' => true,
];
