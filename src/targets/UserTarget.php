<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\models\FieldLayout;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\NativeAttributes;
use GlueAgency\Influx\services\AssetUploadService;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use Throwable;

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
 * Four things about a user live OUTSIDE an element save, and all four are
 * reconciled in {@see afterCommit()} through the Users service, with
 * {@see ownsAttribute()} claiming their handles so the mapping applier doesn't
 * try to assign them:
 *
 *   - `groups` (a Pro-edition feature) — config-only: a lightswitch per group plus
 *     `update` (also apply to existing users) and `remove` (make the selection
 *     authoritative) toggles, all held in the mapping's extras.
 *   - `photo` — a remote image URL per item, downloaded through
 *     {@see \GlueAgency\Influx\services\AssetUploadService} and handed to
 *     `saveUserPhoto()`, which needs a user that already has an id.
 *   - `suspended` — Craft's suspension flag is a user-record column the element
 *     save doesn't touch; it moves through `suspendUser()` / `unsuspendUser()`.
 *   - `activation` — config-only: whether a newly created user gets Craft's
 *     activation email, or is activated outright. Without one of the two a synced
 *     user is left pending with no way in.
 *
 * `newPassword` is deliberately NOT in that list: it's an ordinary attribute the
 * element save hashes and persists ({@see User::beforeSave()}), so it rides the
 * base assignment path like the other strings.
 */
class UserTarget extends AbstractElementTarget
{
    /**
     * Cache key for the run-scoped user-group map. Prefixed with the owner so
     * two targets sharing a run's memo can't collide.
     */
    protected const MEMO_GROUP_IDS = 'userTarget.groupIdMap';

    /**
     * The handles this target reconciles itself, after the commit — see the class
     * docblock for what each one is and why an element save can't carry it.
     * {@see ownsAttribute()} reads this list, so a handle can't be claimed without
     * appearing here.
     */
    protected const OWNED_HANDLES = ['groups', 'photo', 'suspended', 'activation'];

    /**
     * Reserved extras handles among the `groups` toggles — behaviour flags, never
     * group selections. {@see reconcileGroups()} reads them as such.
     */
    protected const GROUP_FLAGS = ['groupsUpdate', 'groupsRemove'];

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
     * The four handles no element save can carry — group membership, the photo,
     * the suspension flag and the activation policy. Claiming them keeps the
     * mapping applier from trying to assign them; {@see afterCommit()} does the
     * real work through the Users service.
     */
    public function ownsAttribute(Link $link, string $handle): bool
    {
        return in_array($handle, self::OWNED_HANDLES, true);
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
                $this->fieldLayout($link),
                Craft::t('influx', 'Profile'),
            ),
        );
    }

    /**
     * There is exactly one user layout in a Craft install, so no criteria are
     * involved in reaching it.
     */
    public function fieldLayout(Link $link): ?FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(User::class);
    }

    /**
     * Everything about the synced user that an element save doesn't carry, in the
     * order Craft wants it: membership, then the photo, then the account state
     * (suspension and activation are both about whether the person can log in, so
     * they settle last).
     *
     * Each step is a no-op when its handle isn't mapped, so a link that only writes
     * names and custom fields pays for none of this. Nothing here throws: a photo
     * whose download or crop fails is logged and the item still counts as synced —
     * the alternative is one broken image URL failing a whole user.
     */
    public function afterCommit(SyncContext $context, ElementInterface $element, RemoteItem $item, bool $isNew): void
    {
        if (! ($element instanceof User) || ! $element->id) {
            return;
        }

        $mappings = $context->link->getMappingCollection();

        $this->reconcileGroups($context, $element, $mappings->get('groups'), $isNew);
        $this->reconcilePhoto($context, $element, $item, $mappings->get('photo'));
        $this->reconcileSuspension($context, $element, $item, $mappings->get('suspended'));
        $this->reconcileActivation($element, $mappings->get('activation'), $isNew);
    }

    /**
     * Group membership from the `groups` mapping's extras.
     *
     * New users are always assigned the selected groups. Existing users are
     * only touched when `update` is on; `remove` then makes the selection
     * authoritative (any other group is dropped), otherwise the selected groups
     * are added to whatever the user already has. Nothing selected is treated as
     * "not configured" — never a strip-all.
     *
     * The selection is read from the extras toggles whose handle matches a real
     * group; {@see GROUP_FLAGS} are reserved behaviour handles and never count as
     * group selections. The write is skipped when the resulting membership already
     * equals the user's current groups, sparing the query and its events.
     */
    protected function reconcileGroups(SyncContext $context, User $user, ?FieldMapping $mapping, bool $isNew): void
    {
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
            if (in_array($handle, self::GROUP_FLAGS, true)) {
                continue;
            }

            if (! empty($on) && isset($byHandle[$handle])) {
                $selectedIds[] = $byHandle[$handle];
            }
        }

        if (! $selectedIds) {
            return;
        }

        $currentIds = array_map(static fn($group): int => (int) $group->id, $user->getGroups());

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

        Craft::$app->getUsers()->assignUserToGroups($user->id, $targetIds);
    }

    /**
     * The user photo, from a remote URL the feed carries.
     *
     * Skipped when the user's current photo already has the filename that URL
     * would land under — so a nightly re-sync doesn't re-download and re-crop every
     * avatar. That makes the FILENAME the identity of a photo, which is the same
     * trade {@see \GlueAgency\Influx\fields\Assets::matchExistingByUrl()} makes:
     * a photo replaced at the same URL under the same name isn't picked up. Clearing
     * the mapped value deletes the photo, since the feed is authoritative.
     *
     * Downloading goes through {@see \GlueAgency\Influx\services\AssetUploadService},
     * which owns the http(s)-only guard that makes a feed-supplied URL safe to
     * follow. The temp file is removed either way — Craft copies it into the user
     * photo volume.
     */
    protected function reconcilePhoto(SyncContext $context, User $user, RemoteItem $item, ?FieldMapping $mapping): void
    {
        if (! $mapping) {
            return;
        }

        $url = $mapping->resolve($item);

        if ($url === null || $url === '') {
            if ($user->photoId) {
                $this->deletePhoto($user);
            }

            return;
        }

        $uploads = $this->uploads();
        $filename = $uploads->filenameFor((string) $url);

        if ($user->getPhoto()?->getFilename() === $filename) {
            return;
        }

        $tempPath = null;

        try {
            $tempPath = $uploads->downloadToTemp((string) $url);
            $this->savePhoto($user, $tempPath, $filename);
        } catch (Throwable $e) {
            Craft::warning(
                "Couldn't set the photo for user {$user->id} from '{$url}': " . $e->getMessage(),
                __METHOD__,
            );
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Craft's suspension flag, which lives on the user record rather than on the
     * element, so only `suspendUser()` / `unsuspendUser()` can move it.
     *
     * Distinct from `enabled` on purpose: a disabled user is hidden, a suspended
     * one is locked out and told so. Truthy spellings come from
     * {@see Lightswitch::coerce()}, the same coercion the inherited
     * {@see AbstractElementTarget::parseEnabled()} uses, so an addressed-but-empty
     * value reads as "not suspended". Already-correct state is left alone, since
     * both calls fire events and destroy the user's sessions.
     */
    protected function reconcileSuspension(SyncContext $context, User $user, RemoteItem $item, ?FieldMapping $mapping): void
    {
        if (! $mapping) {
            return;
        }

        $suspended = Lightswitch::coerce($mapping->resolve($item));

        if ($suspended === $user->suspended) {
            return;
        }

        try {
            $this->setSuspended($user, $suspended);
        } catch (Throwable $e) {
            Craft::warning(
                "Couldn't change the suspended state of user {$user->id}: " . $e->getMessage(),
                __METHOD__,
            );
        }
    }

    /**
     * Let a freshly created user in. A user the sync creates has no password and no
     * verification code, so Craft leaves it PENDING — visible in the CP, unable to
     * log in, and with nothing to send it a way in. The config-only `activation` row
     * says which way out an operator wants: `email` sends Craft's own verification
     * link so the person sets their own password, `activate` activates outright (for
     * a feed that carries the password through `newPassword`, or a user who signs in
     * through another provider), and the unset default leaves it pending.
     *
     * Only for a new user: re-activating or re-mailing an existing one on every sync
     * would be both noise and a security annoyance. A user Craft doesn't consider
     * pending is skipped too — a create that raced another sync.
     */
    protected function reconcileActivation(User $user, ?FieldMapping $mapping, bool $isNew): void
    {
        if (! $mapping || ! $isNew || ! $user->pending) {
            return;
        }

        $policy = (string) $mapping->option('activation', '');

        if ($policy === '') {
            return;
        }

        try {
            match ($policy) {
                'activate' => $this->activate($user),
                'email'    => $this->sendActivationEmail($user),
                default    => null,
            };
        } catch (Throwable $e) {
            Craft::warning(
                "Couldn't activate user {$user->id}: " . $e->getMessage(),
                __METHOD__,
            );
        }
    }

    /**
     * The download service, as a seam — so a spec can exercise the fetch/skip
     * decision without HTTP. Same seam {@see AssetTarget::uploads()} exposes, for
     * the same reason.
     */
    protected function uploads(): AssetUploadService
    {
        return Influx::getInstance()->assetUpload;
    }

    /*
     * The Users-service calls, one method each.
     * =========================================================================
     * Every out-of-band write above goes through one of these rather than calling
     * the service inline, so the DECIDING — when a photo is worth re-downloading,
     * which activation policy applies to whom — is specifiable without a booted
     * Craft, and so a subclass can substitute its own account plumbing (an SSO
     * install that activates users elsewhere).
     */

    protected function savePhoto(User $user, string $tempPath, string $filename): void
    {
        Craft::$app->getUsers()->saveUserPhoto($tempPath, $user, $filename);
    }

    protected function deletePhoto(User $user): void
    {
        Craft::$app->getUsers()->deleteUserPhoto($user);
    }

    protected function setSuspended(User $user, bool $suspended): void
    {
        $users = Craft::$app->getUsers();

        $suspended ? $users->suspendUser($user) : $users->unsuspendUser($user);
    }

    protected function activate(User $user): void
    {
        Craft::$app->getUsers()->activateUser($user);
    }

    protected function sendActivationEmail(User $user): void
    {
        Craft::$app->getUsers()->sendActivationEmail($user);
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
     * feature); {@see GROUP_FLAGS} are reserved handles among its extras —
     * {@see reconcileGroups()} reads them as behaviour flags, never as group
     * selections.
     *
     * `newPassword`, `photo` and `suspended` are plain source-node rows; only
     * `activation` is config-only, since "let this user in" is a policy rather than
     * something a feed says per row.
     */
    protected function nativeFieldDefinitions(): MappingSchemaBuilder
    {
        return MappingSchemaBuilder::make()
            ->group(Craft::t('influx', 'Native'), function(MappingSchemaBuilder $group): void {
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
                    ])
                    // A user account can be live but locked out; `enabled` only
                    // hides it. Reconciled through the Users service, which is the
                    // only thing that can move the flag.
                    ->select([
                        'handle'  => 'suspended',
                        'name'    => Craft::t('app', 'Suspended'),
                        'options' => [
                            'true'  => Craft::t('app', 'Suspended'),
                            'false' => Craft::t('app', 'Active'),
                        ],
                    ])
                    // A URL, so there's nothing to pick in the CP — the default cell
                    // would be a second address to type, not a file to choose.
                    ->text([
                        'handle' => 'photo',
                        'name'   => Craft::t('app', 'Photo'),
                        'cells'  => ['default' => false],
                    ])
                    ->text([
                        'handle' => 'newPassword',
                        'name'   => Craft::t('app', 'Password'),
                        // A default password would be the SAME password for every
                        // user the feed creates, which is worse than none.
                        'cells' => ['default' => false],
                    ])
                    ->text([
                        'handle' => 'activation',
                        'name'   => Craft::t('influx', 'Activation'),
                        // A policy, not a value: the whole row is the select below.
                        'cells' => ['source' => false, 'default' => false],
                        // One select rather than two toggles, because the three
                        // outcomes are mutually exclusive — a pair of switches would
                        // let an operator ask for both and leave the target to guess.
                        'extras' => fn(MappingSchemaBuilder $builder) => $builder->select([
                            'handle'       => 'activation',
                            'label'        => Craft::t('influx', 'New users'),
                            'instructions' => Craft::t('influx', 'A user the sync creates has no password, so Craft leaves it pending — visible in the control panel, unable to sign in. This is how it gets a way in.'),
                            'options'      => [
                                ['value' => '',         'label' => Craft::t('influx', 'Leave pending')],
                                ['value' => 'email',    'label' => Craft::t('influx', 'Send an activation email')],
                                ['value' => 'activate', 'label' => Craft::t('influx', 'Activate immediately')],
                            ],
                            'default' => '',
                        ]),
                    ]);

                $userGroups = Craft::$app->getUserGroups()->getAllGroups();

                if ($userGroups) {
                    $group->text([
                        'handle' => 'groups',
                        'name'   => Craft::t('app', 'Groups'),
                        // Membership is the toggles below, so there's nothing for a
                        // feed node or a default to say — neither cell renders.
                        'cells'  => ['source' => false, 'default' => false],
                        'extras' => function(MappingSchemaBuilder $builder) use ($userGroups): void {
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
