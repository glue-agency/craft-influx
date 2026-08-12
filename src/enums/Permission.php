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
 * its own label.
 *
 * ACCESS_PLUGIN is Craft's, not ours — Craft generates one per plugin handle —
 * but it's a magic string in our controllers all the same, so it lives here
 * with the rest rather than being typed out per gate.
 */
enum Permission: string
{
    case ACCESS_PLUGIN = 'accessPlugin-influx';
    case VIEW_LINKS = 'influx:viewLinks';
    case VIEW_LINK = 'influx:viewLink';
    case DEBUG_LINKS = 'influx:debugLinks';
    case SYNC_ALL = 'influx:syncAll';
    case SYNC_LINK = 'influx:syncLink';
    case VIEW_LOGS = 'influx:viewLogs';
    case DELETE_LOGS = 'influx:deleteLogs';

    /**
     * The permission for seeing ONE link, nested under {@see VIEW_LINK}.
     */
    public static function viewLink(string $uid): string
    {
        return self::forLink(self::VIEW_LINK, $uid);
    }

    /**
     * The permission for syncing ONE link, nested under {@see SYNC_LINK}.
     */
    public static function syncLink(string $uid): string
    {
        return self::forLink(self::SYNC_LINK, $uid);
    }

    /**
     * A per-link permission's value. Dynamic — there is one per link — so it
     * can't be a case of its own.
     *
     * Keyed by the link's UID rather than its handle, the way Craft keys its
     * own per-section permissions: a handle can be renamed, and every user
     * group holding the old string would silently lose the link.
     */
    protected static function forLink(self $permission, string $uid): string
    {
        return $permission->value . ':' . $uid;
    }

    /**
     * Human-readable label for Craft's permissions screen.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACCESS_PLUGIN => Craft::t('influx', 'Access Influx'),
            self::VIEW_LINKS    => Craft::t('influx', 'View all links'),
            self::VIEW_LINK     => Craft::t('influx', 'View link'),
            self::DEBUG_LINKS   => Craft::t('influx', 'Debug links'),
            self::SYNC_ALL      => Craft::t('influx', 'Sync all links'),
            self::SYNC_LINK     => Craft::t('influx', 'Sync link'),
            self::VIEW_LOGS     => Craft::t('influx', 'View logs'),
            self::DELETE_LOGS   => Craft::t('influx', 'Delete logs'),
        };
    }

    /**
     * The line Craft renders under a permission's checkbox, for one whose reach
     * isn't obvious from the label alone.
     */
    public function info(): ?string
    {
        return match ($this) {
            self::VIEW_LINKS, self::SYNC_ALL => Craft::t('influx', 'If enabled, supersedes any individually selected link.'),
            default => null,
        };
    }
}
