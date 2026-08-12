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
 * per-link ones aren't: a link is manageable either in the blanket
 * ({@see Permission::MANAGE_LINKS}) or link by link ({@see Permission::MANAGE_LINK}),
 * and its runs are visible on the same two-part terms. Every caller that asks —
 * the controllers, the overview's rows, the element edit screen's Sync button —
 * asks the same question, answered here once, so the rest of the plugin never
 * spells out a permission string.
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
     * Whether this link is one the current user manages — granted for every
     * link at once, or for this one specifically.
     *
     * One grant, one set: it decides what the Links overview lists, which links
     * the builder will open, what Debug may run against, and what the sync
     * triggers accept — rather than several per-link axes to keep in step.
     */
    public function canManageLink(Link $link): bool
    {
        return $this->can(Permission::MANAGE_LINKS)
            || Craft::$app->getUser()->checkPermission(Permission::manageLink($link->uid));
    }

    /**
     * The links of `$links` this user manages, in the order they came in —
     * what the Links overview lists, rather than every link that exists.
     *
     * @param Link[] $links
     * @return Link[]
     */
    public function manageableLinks(array $links): array
    {
        if ($this->can(Permission::MANAGE_LINKS)) {
            return $links;
        }

        return array_values(array_filter($links, fn(Link $link) => $this->canManageLink($link)));
    }

    /**
     * Whether the current user manages any link at all — what decides whether a
     * link-shaped affordance is worth rendering before a particular link is in
     * hand. The blanket permission answers it without a query; otherwise it
     * takes the links themselves, since the grant is per link.
     */
    public function canManageAnyLink(): bool
    {
        return $this->can(Permission::MANAGE_LINKS) || $this->anyLink(fn(Link $link) => $this->canManageLink($link));
    }

    /**
     * Whether the current user may see this link's runs — granted for every
     * link's at once, or for this one's specifically. Its own axis, separate
     * from {@see canManageLink()}: watching what a link did and being allowed
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
