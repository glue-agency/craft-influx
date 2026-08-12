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
 * Links separate SCOPE from CAPABILITY. The scope is which links a user works
 * with at all — {@see ALL_LINKS}, or {@see INDIVIDUAL_LINKS} with a checkbox
 * each — and it decides what the overview lists. {@see SYNC_LINKS},
 * {@see INSPECT_LINKS} and {@see DEBUG_LINKS} are verbs over that scope: what
 * may be done with those links, never which links. Logs carry a scope of their
 * own, because watching what a link did is a different ask from running it.
 *
 * ACCESS_PLUGIN is Craft's, not ours — Craft generates one per plugin handle —
 * but it's a magic string in our controllers all the same, so it lives here
 * with the rest rather than being typed out per gate.
 */
enum Permission: string
{
    case ACCESS_PLUGIN = 'accessPlugin-influx';
    case ACCESS_LINKS = 'influx:accessLinks';
    case ALL_LINKS = 'influx:allLinks';
    case INDIVIDUAL_LINKS = 'influx:individualLinks';
    case SYNC_LINKS = 'influx:syncLinks';
    case INSPECT_LINKS = 'influx:inspectLinks';
    case DEBUG_LINKS = 'influx:debugLinks';
    case ACCESS_LOGS = 'influx:accessLogs';
    case VIEW_LOGS = 'influx:viewLogs';
    case VIEW_LOG = 'influx:viewLog';
    case DELETE_LOGS = 'influx:deleteLogs';

    /**
     * The permission putting ONE link in scope, nested under
     * {@see INDIVIDUAL_LINKS}.
     */
    public static function link(string $uid): string
    {
        return self::forLink(self::INDIVIDUAL_LINKS, $uid);
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
            self::ACCESS_PLUGIN    => Craft::t('influx', 'Access Influx'),
            self::ACCESS_LINKS     => Craft::t('influx', 'Access links'),
            self::ALL_LINKS        => Craft::t('influx', 'All links'),
            self::INDIVIDUAL_LINKS => Craft::t('influx', 'Individual links'),
            self::SYNC_LINKS       => Craft::t('influx', 'Sync links'),
            self::INSPECT_LINKS    => Craft::t('influx', 'Inspect links'),
            self::DEBUG_LINKS      => Craft::t('influx', 'Debug links'),
            self::ACCESS_LOGS      => Craft::t('influx', 'Access logs'),
            self::VIEW_LOGS        => Craft::t('influx', 'View all logs'),
            self::VIEW_LOG         => Craft::t('influx', 'View individual logs'),
            self::DELETE_LOGS      => Craft::t('influx', 'Delete logs'),
        };
    }

    /**
     * The line Craft renders under a permission's checkbox, for one whose reach
     * isn't obvious from the label alone.
     */
    public function info(): ?string
    {
        return match ($this) {
            self::ALL_LINKS => Craft::t('influx', 'Covers every link, including ones added later. If enabled, supersedes the individual selection.'),
            self::VIEW_LOGS => Craft::t('influx', 'If enabled, supersedes the individual selection.'),
            self::SYNC_LINKS, self::INSPECT_LINKS, self::DEBUG_LINKS => Craft::t('influx', 'Only applies to the selected links.'),
            self::DELETE_LOGS => Craft::t('influx', 'Only applies to the selected logs.'),
            default           => null,
        };
    }
}
