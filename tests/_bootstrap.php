<?php

/**
 * Codeception bootstrap.
 *
 * The unit suite is the only suite in this repo. It runs pure PHPUnit
 * assertions against Field strategies, the Link model and FieldsService.
 * No Craft boot, no DB.
 *
 * The plugin used to ship a `feature/` suite using `\craft\test\Craft`, but
 * that path is broken on Craft 5 in practice (see tests/README.md). It was
 * removed; this file only needs to set sane defaults for the unit suite.
 */

date_default_timezone_set('UTC');

// The Yii class is bootstrapped, not autoloaded. Field strategies extend
// craft\base\Component which extends yii\base\Component; constructing one
// touches Yii::configure(), so the class has to be in scope even for the
// pure-PHP unit suite.
require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
