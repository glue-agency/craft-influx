<?php

namespace GlueAgency\Influx\enums;

use Craft;

/**
 * The plugin's user permissions. Backed values are what Craft stores per user
 * group, so they must stay stable — a renamed value silently revokes the
 * permission from everyone holding it.
 *
 * Registered as a tree by {@see \GlueAgency\Influx\Influx::registerPermissions()},
 * one branch per CP section: ACCESS_LINKS and ACCESS_LOGS decide whether their
 * section's nav item is there at all, and everything nested under them is what
 * that section then offers.
 *
 * Under Links there is ONE per-link axis: managing a link — seeing it, opening
 * its configuration, and syncing it — granted for all of them
 * ({@see MANAGE_LINKS}) or link by link ({@see MANAGE_LINK}). DEBUG_LINKS is a
 * capability over that same set rather than a list of its own. Logs carry a
 * per-link axis too, because watching what a link did is a different ask from
 * running it.
 *
 * ACCESS_PLUGIN is Craft's, not ours — Craft generates one per plugin handle —
 * but it's a magic string in our controllers all the same, so it lives here
 * with the rest rather than being typed out per gate.
 */
enum Permission: string
{
    case ACCESS_PLUGIN = 'accessPlugin-influx';
    case ACCESS_LINKS = 'influx:accessLinks';
    case MANAGE_LINKS = 'influx:manageLinks';
    case MANAGE_LINK = 'influx:manageLink';
    case DEBUG_LINKS = 'influx:debugLinks';
    case ACCESS_LOGS = 'influx:accessLogs';
    case VIEW_LOGS = 'influx:viewLogs';
    case VIEW_LOG = 'influx:viewLog';
    case DELETE_LOGS = 'influx:deleteLogs';

    /**
     * The permission for ONE link, nested under {@see MANAGE_LINK}.
     */
    public static function manageLink(string $uid): string
    {
        return self::forLink(self::MANAGE_LINK, $uid);
    }

    /**
     * The permission for seeing ONE link's runs, nested under {@see VIEW_LOG}.
     */
    public static function viewLog(string $uid): string
    {
        return self::forLink(self::VIEW_LOG, $uid);
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
            self::ACCESS_LINKS  => Craft::t('influx', 'Access links'),
            self::MANAGE_LINKS  => Craft::t('influx', 'Manage all links'),
            self::MANAGE_LINK   => Craft::t('influx', 'Manage individual links'),
            self::DEBUG_LINKS   => Craft::t('influx', 'Debug links'),
            self::ACCESS_LOGS   => Craft::t('influx', 'Access logs'),
            self::VIEW_LOGS     => Craft::t('influx', 'View all logs'),
            self::VIEW_LOG      => Craft::t('influx', 'View individual logs'),
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
            self::MANAGE_LINKS => Craft::t('influx', 'Viewing and syncing, for every link. If enabled, supersedes the individual selection.'),
            self::VIEW_LOGS    => Craft::t('influx', 'If enabled, supersedes the individual selection.'),
            self::DEBUG_LINKS  => Craft::t('influx', 'Only applies to links the user can manage.'),
            default            => null,
        };
    }
}
