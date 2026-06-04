<?php

/**
 * Codeception bootstrap.
 *
 * Per-suite Craft boot (feature/) is handled by craftcms/test-framework via
 * its codeception module — see tests/feature.suite.yml. The unit suite runs
 * plain PHPUnit assertions against pure Field strategies and needs nothing
 * more than composer autoload, which Codeception arranges itself.
 *
 * Anything that has to be in place BEFORE either suite loads (env vars,
 * timezones, ...) can go below.
 */

date_default_timezone_set('UTC');

defined('CRAFT_TESTS_BASE_DIR') || define('CRAFT_TESTS_BASE_DIR', __DIR__ . '/_craft');
defined('CRAFT_BASE_PATH') || define('CRAFT_BASE_PATH', __DIR__ . '/_craft');
defined('CRAFT_CONFIG_PATH') || define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
defined('CRAFT_STORAGE_PATH') || define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
defined('CRAFT_TEMPLATES_PATH') || define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
defined('CRAFT_TRANSLATIONS_PATH') || define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');
