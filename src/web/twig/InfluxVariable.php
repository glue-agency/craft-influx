<?php

namespace GlueAgency\Influx\web\twig;

use GlueAgency\Influx\Influx;
use GlueAgency\Influx\services\PermissionsService;

/**
 * `craft.influx` in CP templates — the plugin's services, reached the way
 * Craft's own plugins expose theirs, so a template asks the same object the PHP
 * does instead of restating what it knows.
 *
 * Registered on {@see \craft\web\twig\variables\CraftVariable::EVENT_INIT}
 * ({@see \GlueAgency\Influx\Influx::registerTwigVariable()}). A getter per
 * service rather than the plugin itself: Yii resolves a plugin's components
 * through `__get`, which Twig's attribute lookup only reaches once they have
 * been instantiated.
 */
class InfluxVariable
{
    /**
     * `craft.influx.permissions` — every "may the current user…" question the
     * CP templates ask, from the flat `can()` to the per-link ones.
     */
    public function getPermissions(): PermissionsService
    {
        return Influx::getInstance()->permissions;
    }
}
