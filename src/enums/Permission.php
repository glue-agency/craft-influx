<?php

namespace GlueAgency\Influx\enums;

use Craft;

/**
 * The plugin's user permissions. Backed values are what Craft stores per user
 * group, so they must stay stable — a renamed value silently revokes the
 * permission from everyone holding it.
 *
 * Registered as a tree by {@see \GlueAgency\Influx\Influx::registerPermissions()}:
 * the screen permissions carry the ones nested under them, and each case knows
 * its own label and the caveat worth spelling out on the permissions screen.
 *
 * ACCESS_PLUGIN is Craft's, not ours — Craft generates one per plugin handle —
 * but it's a magic string in our controllers all the same, so it lives here
 * with the rest rather than being typed out per gate.
 */
enum Permission: string
{
    case ACCESS_PLUGIN = 'accessPlugin-influx';
    case VIEW_LINKS = 'influx:viewLinks';
    case DEBUG_LINKS = 'influx:debugLinks';
    case SYNC = 'influx:sync';
    case VIEW_LOGS = 'influx:viewLogs';
    case DELETE_LOGS = 'influx:deleteLogs';

    /**
     * Human-readable label for Craft's permissions screen.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACCESS_PLUGIN => Craft::t('influx', 'Access Influx'),
            self::VIEW_LINKS    => Craft::t('influx', 'View links'),
            self::DEBUG_LINKS   => Craft::t('influx', 'Debug links'),
            self::SYNC          => Craft::t('influx', 'Sync elements from a remote link'),
            self::VIEW_LOGS     => Craft::t('influx', 'View logs'),
            self::DELETE_LOGS   => Craft::t('influx', 'Delete logs'),
        };
    }

    /**
     * The line Craft renders under the checkbox, for the permissions whose
     * reach isn't obvious from the label alone. Null for the rest.
     */
    public function info(): ?string
    {
        return match ($this) {
            self::VIEW_LINKS  => Craft::t('influx', 'Link configuration is shown read-only — creating, editing and deleting links stays with admins.'),
            self::DEBUG_LINKS => Craft::t('influx', 'Fetches the remote feed and dry-runs a link over it. Writes nothing.'),
            self::DELETE_LOGS => Craft::t('influx', 'Includes clearing every log entry at once.'),
            default           => null,
        };
    }
}
