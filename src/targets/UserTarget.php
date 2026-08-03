<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\NativeAttributes;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\SyncContext;

/**
 * Target for craft\elements\User.
 *
 * Users have no sub-partition to scope on, so the base's structural targeting
 * ("an in-handle User") and its claim rule are inherited unchanged, as is
 * {@see AbstractElementTarget::parseEnabled()} — a disabled user reads as
 * "disabled" from {@see User::getStatus()}, making that flag the feed-driven
 * active/inactive toggle. The remaining natives (username / email / fullName /
 * firstName / lastName) are plain strings the base assignment path handles, so
 * this target declares no parsers of its own.
 *
 * Users aren't localizable — one canonical row, no per-site content — so this
 * target reports {@see supportsMultiSite()} = false: a user link always runs
 * once against a single endpoint, and the builder hides the site-specific
 * endpoint controls for it ({@see Link::validateSiteEndpoints()} is the
 * server-side backstop).
 *
 * There's no section/type-style scoping dimension for users, so this target
 * reports {@see supportsSweeping()} = false: a user link can't enumerate
 * "everything it owns" without a scope, and sweeping every user in the system —
 * potentially disabling admins or the current user — is not a safe default. User
 * links therefore create / update only. The builder omits the disable-/
 * delete-missing policies for them, {@see \GlueAgency\Influx\sync\run\MissingElementsSweeper::plan()}
 * reports a skipped sweep for config that still carries one, and the base
 * {@see AbstractElementTarget::missingElementsQuery()} stays at its null default
 * as the last line of defence.
 *
 * User groups (a Pro-edition feature) are offered as a `groups` mapping field:
 * its config lives entirely in the extras schema — a lightswitch per group plus
 * `update` (also apply to existing users) and `remove` (make the selection
 * authoritative) toggles. Group membership isn't persisted by an element save,
 * so {@see afterCommit()} reconciles it via the Users service after each item
 * commits ({@see ownsAttribute()} keeps the mapping applier from treating
 * `groups` as a normal attribute).
 */
class UserTarget extends AbstractElementTarget
{
    /**
     * Cache key for the run-scoped user-group map. Prefixed with the owner so
     * two targets sharing a run's memo can't collide.
     */
    protected const MEMO_GROUP_IDS = 'userTarget.groupIdMap';

    public static function elementType(): string
    {
        return User::class;
    }

    /**
     * Users are global (non-localizable): the site-specific endpoint machinery
     * and its per-site sweep policies don't apply to them.
     */
    public static function supportsMultiSite(): bool
    {
        return false;
    }

    /**
     * Users have no scoping dimension, so "every user this link owns" is every
     * user in the system — see the class docblock for why that's never swept.
     */
    public static function supportsSweeping(): bool
    {
        return false;
    }

    /**
     * Users are matched globally — a match value uniquely identifies a person
     * regardless of site, and users have no per-site rows to scope by. The
     * $siteId argument (always null for a user link, which can't be site-
     * specific) is therefore ignored.
     */
    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?User
    {
        $matchAttr = $link->matchAttribute();

        if (! $matchAttr || $matchValue === null || $matchValue === '') {
            return null;
        }

        return User::find()
            ->status(null)
            ->{$matchAttr}($matchValue)
            ->one();
    }

    public function buildNew(Link $link, ?int $siteId = null): User
    {
        return new User();
    }

    /**
     * The `groups` field is config-only (its value is the selected groups +
     * behaviour toggles held in the mapping's extras) and can't be written as
     * an element attribute — a save doesn't persist group membership. Claiming
     * it here keeps the mapping applier from trying; {@see afterCommit()} does
     * the real work through the Users service.
     */
    public function ownsAttribute(Link $link, string $handle): bool
    {
        return $handle === 'groups';
    }

    /**
     * The user's identifiers, from the one list a Users RELATION field offers
     * too ({@see NativeAttributes::userMatchable()}) — including its `id` lead,
     * so the base's guarantee holds without merging into it.
     */
    public function matchableNativeAttributes(Link $link): array
    {
        return NativeAttributes::userMatchable();
    }

    /**
     * Custom fields come from the global User field layout, so they keep their
     * user-editor grouping.
     */
    public function getMappableFields(Link $link): array
    {
        return array_merge(
            $this->nativeFieldDefinitions()->toArray(),
            $this->customFieldDescriptors(
                Craft::$app->getFields()->getLayoutByType(User::class),
                Craft::t('influx', 'Profile'),
            ),
        );
    }

    /**
     * Reconcile the synced user's group membership from the `groups` mapping's
     * extras — group membership isn't written by an element save, so it's done
     * here, after the item commits, through the Users service.
     *
     * New users are always assigned the selected groups. Existing users are
     * only touched when `update` is on; `remove` then makes the selection
     * authoritative (any other group is dropped), otherwise the selected groups
     * are added to whatever the user already has. Nothing selected is treated as
     * "not configured" — never a strip-all.
     *
     * The selection is read from the extras toggles whose handle matches a real
     * group; `groupsUpdate` / `groupsRemove` are reserved behaviour handles and
     * never count as group selections. The write is skipped when the resulting
     * membership already equals the user's current groups, sparing the query and
     * its events.
     */
    public function afterCommit(SyncContext $context, ElementInterface $element, bool $isNew): void
    {
        if (! ($element instanceof User) || ! $element->id) {
            return;
        }

        $mapping = $context->link->getMappingCollection()->get('groups');

        if (! $mapping) {
            return;
        }
        $options = $mapping->options;

        $update = ! empty($options['groupsUpdate']);
        $remove = ! empty($options['groupsRemove']);

        if (! $isNew && ! $update) {
            return;
        }

        $byHandle = $this->groupIdMap($context);

        $selectedIds = [];

        foreach ($options as $handle => $on) {
            if ($handle === 'groupsUpdate' || $handle === 'groupsRemove') {
                continue;
            }

            if (! empty($on) && isset($byHandle[$handle])) {
                $selectedIds[] = $byHandle[$handle];
            }
        }

        if (! $selectedIds) {
            return;
        }

        $currentIds = array_map(static fn($group): int => (int) $group->id, $element->getGroups());

        $targetIds = $remove
            ? $selectedIds
            : array_values(array_unique(array_merge($currentIds, $selectedIds)));

        $current = $currentIds;
        $target = $targetIds;
        sort($current);
        sort($target);

        if ($current === $target) {
            return;
        }

        Craft::$app->getUsers()->assignUserToGroups($element->id, $targetIds);
    }

    /**
     * User-group handle → id map, memoized on the RUN rather than on this
     * target: the registry hands out one shared prototype per process, so a
     * property memo would outlive the run and hide a group created between two
     * runs of a long-lived worker. {@see \GlueAgency\Influx\sync\RunMemo} scopes
     * it to the run the way {@see SyncContext::$lookups} scopes element lookups,
     * which is enough to spare {@see afterCommit()} a query per item.
     *
     * @return array<string, int>
     */
    protected function groupIdMap(SyncContext $context): array
    {
        return $context->memo->remember(self::MEMO_GROUP_IDS, static function(): array {
            $map = [];

            foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
                $map[$group->handle] = (int) $group->id;
            }

            return $map;
        });
    }

    /**
     * The User-native mappable attributes — the fixed part of
     * {@see getMappableFields()}. `username` is dropped when the site uses the
     * email as the username (Craft manages it from the email then, so mapping it
     * would fight that); fullName / firstName / lastName are all offered — a
     * feed may carry either the combined name or the split parts. The `groups`
     * field is appended when this install has user groups (a Pro-edition
     * feature); `groupsUpdate` / `groupsRemove` are reserved handles among its
     * extras — {@see afterCommit()} reads them as behaviour flags, never as
     * group selections.
     */
    protected function nativeFieldDefinitions(): SchemaBuilder
    {
        return SchemaBuilder::make()
            ->group(Craft::t('influx', 'Native'), function(SchemaBuilder $group): void {
                // The same writable attributes a Users relation offers as
                // sub-field rows, in the same order — one list, two surfaces.
                foreach (NativeAttributes::userWritable() as $attribute) {
                    $group->text([
                        'handle' => $attribute['handle'],
                        'name'   => $attribute['label'],
                    ]);
                }

                $group
                    ->select([
                        'handle'  => 'enabled',
                        'name'    => Craft::t('app', 'Enabled'),
                        'options' => [
                            'true'  => Craft::t('app', 'Enabled'),
                            'false' => Craft::t('app', 'Disabled'),
                        ],
                    ]);

                $userGroups = Craft::$app->getUserGroups()->getAllGroups();

                if ($userGroups) {
                    $group->text([
                        'handle' => 'groups',
                        'name'   => Craft::t('app', 'Groups'),
                        'meta'   => ['subfieldsOnly' => true],
                        'extras' => function(SchemaBuilder $builder) use ($userGroups): void {
                            foreach ($userGroups as $userGroup) {
                                $builder->lightswitch(['handle' => $userGroup->handle, 'label' => $userGroup->name]);
                            }

                            $builder
                                ->lightswitch([
                                    'handle'       => 'groupsUpdate',
                                    'label'        => Craft::t('influx', 'Update existing users'),
                                    'instructions' => Craft::t('influx', 'Also apply these groups to users that already exist, not just newly-created ones.'),
                                ])
                                ->lightswitch([
                                    'handle'       => 'groupsRemove',
                                    'label'        => Craft::t('influx', 'Remove other groups'),
                                    'instructions' => Craft::t('influx', 'Remove any groups a synced user has that aren’t selected above (makes the selection authoritative).'),
                                ]);
                        },
                    ]);
                }
            });
    }
}
