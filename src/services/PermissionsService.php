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
 * Most of the permissions are a single flag and would read fine inline, but
 * syncing isn't: it's granted either in the blanket
 * ({@see Permission::SYNC_ALL}) or link by link ({@see Permission::SYNC_LINK}),
 * so every caller that asks — the controller, the overview's Sync buttons, the
 * element edit screen's button — has to ask the same two-part question. It is
 * answered here once, and the rest of the plugin asks this service rather than
 * spelling out permission strings.
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
     */
    public function can(Permission $permission): bool
    {
        return Craft::$app->getUser()->checkPermission($permission->value);
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
     * Whether the current user may see this link — granted for every link at
     * once ({@see Permission::VIEW_LINKS}), or for this one specifically.
     */
    public function canViewLink(Link $link): bool
    {
        return $this->can(Permission::VIEW_LINKS)
            || Craft::$app->getUser()->checkPermission(Permission::viewLink($link->uid));
    }

    /**
     * Whether the current user may trigger a sync of this link — granted for
     * every link at once ({@see Permission::SYNC_ALL}), or for this one
     * specifically.
     */
    public function canSyncLink(Link $link): bool
    {
        return $this->can(Permission::SYNC_ALL)
            || Craft::$app->getUser()->checkPermission(Permission::syncLink($link->uid));
    }

    /**
     * The links of `$links` this user may see, in the order they came in — what
     * the Links overview lists, rather than every link that exists.
     *
     * @param Link[] $links
     * @return Link[]
     */
    public function viewableLinks(array $links): array
    {
        if ($this->can(Permission::VIEW_LINKS)) {
            return $links;
        }

        return array_values(array_filter($links, fn(Link $link) => $this->canViewLink($link)));
    }

    /**
     * Whether the current user may see any link at all — what decides whether
     * the Links screen is worth offering before any particular link is in hand.
     * The blanket permission answers it without a query; otherwise it takes the
     * links themselves, since the grant is per link.
     */
    public function canViewAnyLink(): bool
    {
        return $this->can(Permission::VIEW_LINKS) || $this->anyLink(fn(Link $link) => $this->canViewLink($link));
    }

    /**
     * Whether the current user may sync anything at all — the same question
     * {@see canViewAnyLink()} asks, for the run permissions.
     */
    public function canSyncAnyLink(): bool
    {
        return $this->can(Permission::SYNC_ALL) || $this->anyLink(fn(Link $link) => $this->canSyncLink($link));
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
