<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\helpers\SchemaBuilder;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;

/**
 * Target for craft\elements\User.
 *
 * Users aren't localizable — one canonical row, no per-site content — so this
 * target reports {@see supportsMultiSite()} = false: a user link always runs
 * once against a single endpoint, and the builder hides the site-specific
 * endpoint controls for it ({@see Link::validateSiteEndpoints()} is the
 * server-side backstop).
 *
 * There's no section/type-style scoping dimension for users, so the
 * missing-elements sweep is deliberately NOT implemented (the base
 * {@see AbstractElementTarget::missingElementsQuery()} returns null, opting
 * out): a user link can't safely enumerate "everything it owns" without a
 * scope, and sweeping every user in the system — potentially disabling admins
 * or the current user — is not a safe default. User links therefore create /
 * update only; the delete/disable-missing policies no-op for them.
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
     * Memoized user-group handle → id map, so {@see afterCommit()} (called once
     * per committed item) doesn't re-query the groups on every user. Groups
     * don't change mid-run, so caching on the run's singleton target is safe.
     *
     * @var array<string, int>|null
     */
    protected ?array $groupIdMap = null;

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
     * Users have no sub-partition to scope on, so structural targeting is just
     * "an in-handle User" — exactly {@see AbstractElementTarget::targetsElement()},
     * which this target inherits unchanged. {@see claimsElement()} layers the
     * match-value check on top.
     */
    public function claimsElement(Link $link, ElementInterface $element): bool
    {
        if (! $this->targetsElement($link, $element)) {
            return false;
        }

        $matchAttr = $link->matchAttribute();

        if (! $matchAttr) {
            return false;
        }

        return $element->{$matchAttr} !== null && $element->{$matchAttr} !== '';
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
     * Adds the user's unique identifiers on top of the base `id`. `username`
     * is dropped when the site is configured to use the email as the username
     * (it's then just a copy of the email — not a distinct match key).
     */
    public function matchableNativeAttributes(Link $link): array
    {
        $attributes = parent::matchableNativeAttributes($link);

        if (! Craft::$app->getConfig()->getGeneral()->useEmailAsUsername) {
            $attributes[] = ['value' => 'username', 'label' => Craft::t('influx', 'Username (username)')];
        }

        $attributes[] = ['value' => 'email', 'label' => Craft::t('influx', 'Email (email)')];

        return $attributes;
    }

    public function getMappableFields(Link $link): array
    {
        $fields = $this->nativeFieldDefinitions();

        $layout = Craft::$app->getFields()->getLayoutByType(User::class);

        if (! $layout) {
            return $fields;
        }

        // Walk the field-layout tabs so custom fields keep their user-editor grouping (mirrors EntryTarget)
        $fallbackTab = Craft::t('influx', 'Profile');

        foreach ($layout->getTabs() as $tab) {
            $tabName = $tab->name ?: $fallbackTab;

            foreach ($tab->getElements() as $element) {
                if (! ($element instanceof CustomField)) {
                    continue;
                }
                $field = $element->getField();

                if (! $field) {
                    continue;
                }
                $fields[] = [
                    'handle'      => $field->handle,
                    'name'        => $field->name,
                    'native'      => false,
                    'group'       => $tabName,
                    'defaultType' => 'text',
                    'fieldClass'  => $field::class,
                    'fieldMeta'   => Influx::getInstance()->fields->metaFor($field),
                ];
            }
        }

        return $fields;
    }

    // -- native attribute parsers (dispatched by handle) ---------------------

    /**
     * Coerce the mapped value into the element `enabled` flag. A disabled user
     * reads as "disabled" from {@see User::getStatus()}, so this is the feed-
     * driven active/inactive toggle. Truthy spellings follow the Lightswitch
     * field strategy. (username/email/fullName/firstName/lastName are plain
     * string attributes handled by the base assignment path.)
     */
    protected function parseEnabled(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

        // Empty clears to disabled — an empty boolean is false.
        $new = match (true) {
            $value === null => false,
            is_bool($value) => $value,
            default         => in_array(strtolower(trim((string) $value)), Lightswitch::TRUTHY_VALUES, true),
        };

        $changed = (bool) $element->enabled !== $new;
        $element->enabled = $new;

        return $changed;
    }

    // -- user groups ----------------------------------------------------------

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

        // Existing users are reconciled only when the link opts in; new users always are
        if (! $isNew && ! $update) {
            return;
        }

        $byHandle = $this->groupIdMap();

        // Selected groups: truthy toggles matching a real group handle; the behaviour flags are reserved
        $selectedIds = [];

        foreach ($options as $handle => $on) {
            if ($handle === 'groupsUpdate' || $handle === 'groupsRemove') {
                continue;
            }

            if (! empty($on) && isset($byHandle[$handle])) {
                $selectedIds[] = $byHandle[$handle];
            }
        }

        // No groups picked — treat as unconfigured rather than a strip-all.
        if (! $selectedIds) {
            return;
        }

        $currentIds = array_map(static fn($group): int => (int) $group->id, $element->getGroups());

        $targetIds = $remove
            ? $selectedIds
            : array_values(array_unique(array_merge($currentIds, $selectedIds)));

        // Skip the write when membership already matches — avoids the query + events
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
     * User-group handle → id map, memoized for the run.
     *
     * @return array<string, int>
     */
    protected function groupIdMap(): array
    {
        if ($this->groupIdMap === null) {
            $this->groupIdMap = [];

            foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
                $this->groupIdMap[$group->handle] = (int) $group->id;
            }
        }

        return $this->groupIdMap;
    }

    // -- mappable-field metadata ----------------------------------------------

    /**
     * The User-native mappable attributes — the fixed part of
     * {@see getMappableFields()}. `username` is dropped when the site uses the
     * email as the username (Craft manages it from the email then, so mapping it
     * would fight that); fullName / firstName / lastName are all offered — a
     * feed may carry either the combined name or the split parts. The `groups`
     * field is appended when this install has user groups (see
     * {@see groupsFieldSpec()}).
     *
     * @return list<array>
     */
    protected function nativeFieldDefinitions(): array
    {
        $specs = [];

        if (! Craft::$app->getConfig()->getGeneral()->useEmailAsUsername) {
            $specs[] = ['handle' => 'username', 'name' => Craft::t('app', 'Username')];
        }

        $specs[] = ['handle' => 'email', 'name' => Craft::t('app', 'Email')];
        $specs[] = ['handle' => 'fullName', 'name' => Craft::t('app', 'Full Name')];
        $specs[] = ['handle' => 'firstName', 'name' => Craft::t('app', 'First Name')];
        $specs[] = ['handle' => 'lastName', 'name' => Craft::t('app', 'Last Name')];
        $specs[] = ['handle' => 'enabled', 'name' => Craft::t('app', 'Enabled'), 'type' => 'select', 'options' => [
            'true'  => Craft::t('app', 'Enabled'),
            'false' => Craft::t('app', 'Disabled'),
        ]];

        $groupsSpec = $this->groupsFieldSpec();

        if ($groupsSpec) {
            $specs[] = $groupsSpec;
        }

        return SchemaBuilder::make()->group(Craft::t('influx', 'Native'), $specs)->toArray();
    }

    /**
     * User groups (a Pro-edition feature) as a config-only `groups` field spec:
     * a lightswitch per user group (which to assign) plus the reserved
     * `groupsUpdate` / `groupsRemove` behaviour toggles {@see afterCommit()}
     * reads back to reconcile membership. `subfieldsOnly` hides the
     * source-node/default columns — the field has no feed value of its own.
     *
     * Null on installs with no user groups (Solo edition), where the control
     * would be empty and meaningless.
     *
     * @return array|null A {@see SchemaBuilder::group()} field spec, or null.
     */
    protected function groupsFieldSpec(): ?array
    {
        $groups = Craft::$app->getUserGroups()->getAllGroups();

        if (! $groups) {
            return null;
        }

        return [
            'handle' => 'groups',
            'name'   => Craft::t('app', 'Groups'),
            'meta'   => ['subfieldsOnly' => true],
            'extras' => function(SchemaBuilder $builder) use ($groups): void {
                foreach ($groups as $group) {
                    $builder->lightswitch(['handle' => $group->handle, 'label' => $group->name]);
                }

                // Reserved behaviour handles read as flags by afterCommit(), never as group selections
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
        ];
    }
}
