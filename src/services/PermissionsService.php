<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use GlueAgency\Influx\enums\Permission;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;

/**
 * The one place that answers "may the current user do this?" for the plugin.
 *
 * Most of the permissions are a single flag and would read fine inline, but the
 * link ones aren't: every one of them is a verb over a scope — may this user
 * sync / inspect / debug, AND is this link among the ones they were given
 * ({@see Permission::ALL_LINKS} or {@see Permission::INDIVIDUAL_LINKS}). Logs
 * ask the same shape of question of their own scope. Every caller — the
 * controllers, the overview's rows, the element edit screen's Sync button —
 * asks it here rather than spelling out a permission string.
 *
 * Every method answers for the CURRENT user, so nothing here belongs in a
 * console context — {@see \GlueAgency\Influx\console\controllers\SyncController}
 * runs unrestricted, as CLI access is its own authorisation.
 */
class PermissionsService extends Component
{
    /**
     * Whether the current user holds a permission outright. Admins hold every
     * one, which Craft's own check already accounts for.
     *
     * Takes the raw value as well as the case, because a Twig template reaching
     * this through `craft.influx.permissions` has no way to name an enum case.
     */
    public function can(Permission|string $permission): bool
    {
        return Craft::$app->getUser()->checkPermission(
            $permission instanceof Permission ? $permission->value : $permission,
        );
    }

    /**
     * Whether the current user is an admin — who holds every permission, and
     * is the only one allowed to write link configuration at all.
     */
    public function isAdmin(): bool
    {
        return Craft::$app->getUser()->getIsAdmin();
    }

    /**
     * Whether this environment permits administrative (Project Config) changes
     * — Craft's `allowAdminChanges`, which no permission can grant past.
     */
    public function allowAdminChanges(): bool
    {
        return Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }

    /**
     * Both at once: an admin, in an environment that takes administrative
     * changes. The condition behind every write to link configuration — and,
     * negated, behind the read-only builder
     * ({@see \GlueAgency\Influx\controllers\AbstractController::readOnly()}).
     */
    public function isAdminAndAllowsAdminChanges(): bool
    {
        return $this->isAdmin() && $this->allowAdminChanges();
    }

    /**
     * Either one — for the questions where an admin OR an environment open to
     * administrative changes is enough on its own.
     */
    public function isAdminOrAllowsAdminChanges(): bool
    {
        return $this->isAdmin() || $this->allowAdminChanges();
    }

    /**
     * Whether this link is in the current user's scope — every link at once
     * ({@see Permission::ALL_LINKS}), or this one specifically. Scope alone
     * says nothing about what may be DONE with the link; that's the verbs
     * below, each of which is this check plus its own permission.
     */
    public function linkInScope(Link $link): bool
    {
        return $this->can(Permission::ALL_LINKS)
            || Craft::$app->getUser()->checkPermission(Permission::link($link->uid));
    }

    /**
     * The links of `$links` in scope, in the order they came in — what the
     * Links overview lists, rather than every link that exists.
     *
     * @param Link[] $links
     * @return Link[]
     */
    public function scopedLinks(array $links): array
    {
        if ($this->can(Permission::ALL_LINKS)) {
            return $links;
        }

        return array_values(array_filter($links, fn(Link $link) => $this->linkInScope($link)));
    }

    /**
     * Whether the current user may trigger a sync of this link.
     */
    public function canSyncLink(Link $link): bool
    {
        return $this->can(Permission::SYNC_LINKS) && $this->linkInScope($link);
    }

    /**
     * Whether the current user may open this link's configuration — read-only
     * unless they're also an admin ({@see isAdminAndAllowsAdminChanges()}).
     */
    public function canInspectLink(Link $link): bool
    {
        return $this->can(Permission::INSPECT_LINKS) && $this->linkInScope($link);
    }

    /**
     * Whether the current user may dry-run this link in the debug inspector.
     */
    public function canDebugLink(Link $link): bool
    {
        return $this->can(Permission::DEBUG_LINKS) && $this->linkInScope($link);
    }

    /**
     * Whether the current user may see this link's runs — granted for every
     * link's at once, or for this one's specifically. Its own axis, separate
     * from the link scope: watching what a link did and being allowed
     * to run it are different asks.
     */
    public function canViewLogsForLink(Link $link): bool
    {
        return $this->can(Permission::VIEW_LOGS)
            || Craft::$app->getUser()->checkPermission(Permission::viewLog($link->uid));
    }

    /**
     * Which links' runs the Logs screens may show: null for "no restriction"
     * (the blanket permission), otherwise the handles granted one by one.
     *
     * Handles, not UIDs, because that is what a log row stores — and why a run
     * whose link has since been deleted is visible only under the blanket
     * permission: there is no link left to have been granted.
     *
     * @return string[]|null
     */
    public function viewableLogLinkHandles(): ?array
    {
        if ($this->can(Permission::VIEW_LOGS)) {
            return null;
        }

        $handles = [];

        foreach (Influx::getInstance()->links->getAllLinks() as $link) {
            if ($this->canViewLogsForLink($link)) {
                $handles[] = $link->handle;
            }
        }

        return $handles;
    }

    /**
     * Whether any configured link satisfies the test. Only reached once the
     * blanket permission has already said no, so the link read is the price of
     * a per-link grant rather than of every check.
     */
    protected function anyLink(callable $test): bool
    {
        foreach (Influx::getInstance()->links->getAllLinks() as $link) {
            if ($test($link)) {
                return true;
            }
        }

        return false;
    }
}
