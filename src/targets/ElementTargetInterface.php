<?php

namespace GlueAgency\Influx\targets;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\models\FieldLayout;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;

/**
 * Adapter that lets the sync engine talk to any element type. One
 * implementation per element type (Entry, Calendar Event, Commerce Product,
 * ...). Built-ins are registered by Influx; third-party targets register by
 * listening to TargetsService::EVENT_REGISTER_TARGETS.
 *
 * A target owns everything the engine and the CP need to know about one element
 * type, grouped into six concerns:
 *   1. Identity + capabilities — which element class, what to call it, and which
 *      of the engine's features apply to it ({@see supportsMultiSite()},
 *      {@see criteriaKeys()}, {@see criteriaSchema()}, {@see supportsCreating()},
 *      {@see supportsSweeping()}). All static: the CP asks them per element type,
 *      before any link exists.
 *   2. Claiming — which links and which elements are this target's business
 *      ({@see handles()}, {@see targetsElement()}, {@see claimsElement()}), and
 *      the comparable scope two links overlap on ({@see claimCells()}).
 *   3. Resolution + construction — find the element a feed item pairs with, or
 *      build a fresh one carrying every link-mandated attribute
 *      ({@see findByMatchValue()}, {@see buildNew()}, {@see assignMatchValue()},
 *      {@see matchableNativeAttributes()}).
 *   4. Writing — apply native attributes ({@see applyNativeAttribute()},
 *      {@see ownsAttribute()}), persist ({@see save()}), and reconcile whatever
 *      a save doesn't cover ({@see afterCommit()}).
 *   5. Destructive writes + the missing-elements sweep ({@see disable()},
 *      {@see disableForSite()}, {@see delete()}, {@see deleteForSite()},
 *      {@see missingElementsQuery()}).
 *   6. Schema — the fields a link may map to ({@see getMappableFields()}), and the
 *      layout they come from ({@see fieldLayout()}).
 *
 * {@see AbstractElementTarget} implements every member a target doesn't have to
 * think about, so a new target is `elementType()` + `findByMatchValue()` +
 * `buildNew()` and nothing else.
 */
interface ElementTargetInterface
{
    /**
     * The broadest claim cell — "every element of this type". A target whose
     * element type has no sub-partition reports only this, so two links of that
     * type always intersect. {@see claimCells()}
     */
    public const CLAIM_ALL = '*';

    /**
     * FQCN of the element class this target handles, e.g. craft\elements\Entry.
     */
    public static function elementType(): string;

    /**
     * Human-readable label for this element type, used as the option label in
     * the link-edit dropdown and anywhere else the CP would otherwise show
     * a bare FQCN. Defaults to the element class's `displayName()`.
     */
    public static function friendlyName(): string;

    /**
     * Whether links to this element type can run per-site — i.e. carry
     * site-specific endpoints and be swept per-site. Localizable element types
     * (Entry) return true; global, non-localizable ones (User) return false,
     * so their links always run once against a single endpoint and the CP
     * hides the site-specific controls. {@see AbstractElementTarget} defaults
     * to true; a non-multi-site target overrides it, and {@see Link} rejects
     * site endpoints configured against such a target as a server-side backstop.
     */
    public static function supportsMultiSite(): bool;

    /**
     * The `elementCriteria` keys this element type scopes on — the query
     * refinements the CP offers as extra dropdowns on the General tab (Entry
     * uses `['section', 'type']`; User has none). Drives which of the builder's
     * criteria fields render for the selected type; the base returns `[]`.
     *
     * A target that scopes on criteria OWNS those key names — declared as
     * constants and returned from here ({@see EntryTarget::CRITERIA_SECTION}) —
     * and every reader goes through {@see Link::criterion()} with one of them, so
     * the literals live in exactly one place.
     *
     * @return list<string>
     */
    public static function criteriaKeys(): array;

    /**
     * The criteria dropdowns themselves, as a {@see \GlueAgency\Influx\schema\SchemaBuilder}
     * schema the builder's General tab renders straight into `elementCriteria`.
     * The UI twin of {@see criteriaKeys()}: that one is the server-side contract
     * (what {@see Link::criterion()} may be asked for, what a save may keep), this
     * one is how an operator fills it in. A target declaring criteria keys should
     * declare a node per key, and the base returns `[]` for a type with none.
     *
     * Every node's `handle` IS the criteria key, so the same constants name both.
     * Two node keys exist for this surface and are honoured by
     * `SchemaForm.vue`: `dependsOn` (the handle whose value this node's list is
     * keyed on — changing the parent clears this node) and `optionsBy` (that list,
     * as `parentValue => [{value, label}]`). Entry's entry-type dropdown is the
     * case they exist for: entry types are per section, so the list can't be one
     * flat array. {@see EntryTarget::criteriaSchema()}
     *
     * Static because the CP asks per element type, before any link exists — which
     * also means the option lists are resolved against Craft at request time.
     *
     * @return list<array>
     */
    public static function criteriaSchema(): array;

    /**
     * Whether a feed may CREATE elements of this type, or only update ones that
     * already exist. False for a type whose elements are brought into being in the
     * CP and only ever hydrated by a feed — a Global Set, which exists because
     * someone declared it in project config
     * ({@see \GlueAgency\Influx\targets\GlobalSetTarget}). The builder then doesn't
     * offer the `create` policy at all and a save drops it
     * ({@see Link::pruneProcessingForTarget()}); {@see buildNew()} throwing is the
     * last line of defence. {@see AbstractElementTarget} defaults to true.
     */
    public static function supportsCreating(): bool;

    /**
     * Whether links to this element type can be swept for elements that are
     * missing from the feed. A sweep acts on the COMPLEMENT of the ids a run saw,
     * so it's only safe for a type whose links can enumerate "everything I own"
     * ({@see missingElementsQuery()}). A type with no scoping dimension — User,
     * where the candidate set would be every user in the system — returns false,
     * and the builder then doesn't offer the disable-/delete-missing policies for
     * it at all and a save drops them ({@see Link::pruneProcessingForTarget()}).
     * {@see AbstractElementTarget} defaults to true; a non-sweeping target
     * overrides it, and
     * {@see \GlueAgency\Influx\sync\run\MissingElementsSweeper::plan()} is the
     * server-side backstop that reports a skipped sweep for config that reached a
     * run anyway — Project Config applies straight to the row, never through the
     * prune.
     */
    public static function supportsSweeping(): bool;

    /**
     * Is this target the right one for the given link?
     */
    public function handles(Link $link): bool;

    /**
     * Does this link STRUCTURALLY target this element — is the element the
     * right type and inside the link's configured scope (section/type for
     * entries, nothing extra for users)? Deliberately independent of whether
     * the element currently carries a match value: the "Sync from remote"
     * button surfaces for every structurally-targeted element (showing a
     * disabled state when it has no match value), and the per-element sync
     * action uses it to authorize an explicit link against an element.
     *
     * The structural half of {@see claimsElement()}.
     */
    public function targetsElement(Link $link, ElementInterface $element): bool;

    /**
     * Does this link claim this element for the sync engine — i.e. does it
     * {@see targetsElement()} AND does the element actually carry a non-empty
     * value for the link's match attribute? A claimed element is one a feed
     * item could be paired with; used to resolve THE link for an element in
     * flows that need a ready-to-sync target.
     */
    public function claimsElement(Link $link, ElementInterface $element): bool;

    /**
     * The cells this link claims — a canonical, comparable partition of "which
     * elements does this link manage", intersected with another link's to warn
     * about two links owning the same elements
     * ({@see \GlueAgency\Influx\models\Link::overlaps()}). Cells are only ever
     * compared between links of the SAME element type, so the strings are this
     * target's own vocabulary.
     *
     * Entries partition into `"{section} {entryType}"` cells expanded from the
     * link's criteria ({@see EntryTarget::claimCells()}). An element type with no
     * sub-partition reports the single {@see CLAIM_ALL} sentinel — which is what
     * the base returns, so a target only implements this when its element type
     * actually has a partition to report.
     *
     * @return list<string>
     */
    public function claimCells(Link $link): array;

    /**
     * How the link's criteria read in the CP — "Movies / Feature" for an entry
     * link, the volume's name for an asset one. Null when nothing is configured
     * yet, or when the element type carries no criteria at all. Formatting belongs
     * to the target for the same reason {@see claimCells()} does: only it knows
     * what its keys mean, and only it can turn a stored handle back into the name
     * an editor recognises (falling back to the handle when the section / group /
     * volume has since been removed).
     */
    public function criteriaLabel(Link $link): ?string;

    /**
     * Whether a link to this element type identifies its elements by a match
     * value at all.
     *
     * False for a target whose criteria already name ONE element: a Global Set
     * ({@see \GlueAgency\Influx\targets\GlobalSetTarget}), or an Entry link whose
     * section is a Craft Single ({@see \GlueAgency\Influx\targets\EntryTarget}).
     * There is nothing to disambiguate, so a match key would be ceremony — and
     * worse than ceremony, since it makes the operator nominate a feed node to
     * identify an element the config had already pinned.
     *
     * Link-scoped and NOT static, unlike the {@see supportsCreating()} trio: for
     * entries the answer depends on the link's own section, so it can't be known
     * per element type before a link exists. A target that can't resolve its
     * criteria must answer TRUE — "can't tell" has to mean "still expects a
     * match", or a half-configured link would quietly stop needing one.
     *
     * Answering false is half of a pair; {@see findWithoutMatch()} is the other.
     * {@see \GlueAgency\Influx\models\Link::requiresMatch()} is the one reader,
     * consulted by validation, the save-time prune, the builder, the sync engine
     * and the dry-run inspector alike.
     */
    public function requiresMatch(Link $link): bool;

    /**
     * Find an existing element matching the given key value, or null.
     * A per-site run passes $siteId and the lookup may scope to that site; a
     * siteless run passes null and the lookup must span all sites. Both
     * converge on the same canonical element as long as the match field is
     * not translatable — the precondition multi-site links rely on.
     *
     * Only called when {@see requiresMatch()} is true; a link that needs no match
     * resolves through {@see findWithoutMatch()} instead.
     */
    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface;

    /**
     * The one element this link's criteria name, without consulting the feed —
     * the resolution path for a target reporting {@see requiresMatch()} = false.
     * Null when the criteria don't resolve (no set configured, a section that has
     * since been removed), which the engine reads the same way it reads a match
     * that found nothing.
     *
     * $siteId scopes the lookup exactly as it does for {@see findByMatchValue()},
     * so a per-site run writes localized values onto the right row.
     *
     * {@see AbstractElementTarget} throws, so overriding {@see requiresMatch()}
     * without this fails loudly rather than resolving every item to null — the
     * same contract {@see buildNew()} enforces for a non-creating target.
     */
    public function findWithoutMatch(Link $link, ?int $siteId = null): ?ElementInterface;

    /**
     * Build a fresh element pre-populated with all link-mandated attributes
     * so the caller can apply mappings and save without further setup.
     */
    public function buildNew(Link $link, ?int $siteId = null): ElementInterface;

    /**
     * Apply a mapped value to a *native* attribute (title, slug, enabled,
     * postDate, ...). Custom fields are routed to per-field-type strategies
     * via FieldsService — this hook only fires when no Craft field with the
     * handle exists on the element's layout.
     *
     * Implementations resolve the value via {@see FieldMapping::resolve()},
     * translate it to whatever attribute(s) the element actually accepts
     * (e.g. coercing `enabled` to a bool), and return true when the write
     * actually CHANGED the element's value — so the sync engine can skip saving
     * elements nothing changed for. The target owns this comparison because
     * only it knows each attribute's semantics (e.g. that `author` compares by
     * id, not by the relation object a naive before/after read would return).
     * An empty resolved value clears the attribute (the feed is authoritative);
     * the engine only calls this for an actively-mapped handle.
     *
     * Convention: {@see AbstractElementTarget} dispatches to a
     * `parse{Handle}()` method on the target when one exists — declare
     * `parseEnabled()`, `parsePostDate()`, ... (signature:
     * `(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool`)
     * for attributes that need translation, and let the generic assignment
     * handle the rest. The run's {@see SyncContext} is threaded through so a
     * parser can reach the run's element-lookup cache (e.g. resolving Entry's
     * `author` through {@see SyncContext::$lookups}). Every handle a target
     * supports this way must also be reported by {@see getMappableFields()} —
     * link saving prunes mapping handles that aren't in that list.
     */
    public function applyNativeAttribute(
        SyncContext $context,
        ElementInterface $element,
        string $handle,
        RemoteItem $item,
        FieldMapping $mapping,
    ): bool;

    /**
     * Does the target own this mapping handle internally? Returning true
     * tells the sync engine to skip its generic native/custom dispatch — the
     * target handles this attribute itself.
     *
     * Example: User's `groups` is config-only (its value lives in the mapping's
     * extras) and an element save doesn't persist group membership, so the engine
     * must not try to assign it; {@see afterCommit()} reconciles it instead.
     */
    public function ownsAttribute(Link $link, string $handle): bool;

    /**
     * Assign the link's match value to a freshly-built element. The sync
     * engine has the value but only the target knows whether the match
     * attribute is a native attribute or a custom field on this element type.
     */
    public function assignMatchValue(ElementInterface $element, Link $link, mixed $matchValue): void;

    /**
     * Native attributes that can serve as a link's match key — the unique
     * identifiers {@see findByMatchValue()} can sensibly query on. Drives
     * the element-type group of the Match attribute picker; the custom
     * fields group is built separately from the layout.
     *
     * The base offers only `id` (the one identifier every element has);
     * targets add what their element type actually exposes for the given
     * link — e.g. the Entry target adds slug/title only when the resolved
     * entry type enables them.
     *
     * @return list<array{value: string, label: string}>
     */
    public function matchableNativeAttributes(Link $link): array;

    /**
     * Post-commit side effects for a create/update item, run by the sync engine
     * once the element has committed. Fires for every non-skipped, non-dry-run
     * create/update item — the element was saved when a field changed, and
     * unchanged existing elements are passed through — so a target can enforce
     * state that lives OUTSIDE the element save (e.g. user-group membership,
     * which a save doesn't persist). $isNew distinguishes a freshly-created
     * element from an updated one. No-op by default.
     *
     * The $item is here because this is the ONE hook that runs with both the feed
     * row and an element that has an id, which is exactly what a side effect
     * needing both requires: a user's photo comes from a feed node, and
     * `Users::saveUserPhoto()` can't run until the user exists
     * ({@see \GlueAgency\Influx\targets\UserTarget::afterCommit()}). Reading it
     * goes through the same {@see FieldMapping::resolve()} a mapping row would use,
     * so a handle claimed by {@see ownsAttribute()} still honours its configured
     * node and default.
     */
    public function afterCommit(SyncContext $context, ElementInterface $element, RemoteItem $item, bool $isNew): void;

    /**
     * Persist the element, reporting whether the save landed. The engine's ONE
     * write, on the target for the same reason the four destructive writes below
     * are: so a third-party target can save with whatever flags its element type
     * needs (its own propagation, search-index or resave policy) instead of the
     * engine hardcoding Craft's defaults. {@see AbstractElementTarget::save()}
     * runs Craft's own save WITH validation — see there for why.
     */
    public function save(ElementInterface $element): bool;

    public function disable(ElementInterface $element): bool;

    /**
     * Disable the element in a single site only (leaving its other sites
     * enabled). Used by the missing-elements sweep on a site-scoped run:
     * disabling the whole element when only one site's feed dropped it would
     * wrongly hide it everywhere. The passed element must already be loaded in
     * that site (the sweep query scopes it) so the per-site flag lands on the
     * right row.
     */
    public function disableForSite(ElementInterface $element, int $siteId): bool;

    public function delete(ElementInterface $element): bool;

    public function deleteForSite(ElementInterface $element, int $siteId): bool;

    /**
     * Query for elements this link owns that were NOT seen in the feed — the
     * candidate set for the missing-elements sweep. Mirrors the scoping of
     * {@see findByMatchValue()} (section/type/match-attribute) so the sweep
     * only ever considers elements this link actually manages, then excludes
     * the ids the run just touched so a same-run create can never be swept.
     *
     * @param list<int> $seenIds Element ids present in this run's feed — excluded.
     * @param int|null $siteId Scope to one site (site-scoped run) or null for
     * a cross-site (`siteId('*')->unique()`) candidate set.
     * @return \craft\elements\db\ElementQueryInterface|null Null when the link
     * has no resolvable match attribute (nothing safe to sweep).
     */
    public function missingElementsQuery(Link $link, array $seenIds, ?int $siteId): ?ElementQueryInterface;

    /**
     * Fields the link can map to. Drives the per-field mapping UI on the
     * CP edit screen. Each field is a {@see MappableField} — that value object
     * owns the descriptor's shape and its JSON serialization; natives are
     * declared through {@see \GlueAgency\Influx\schema\MappingSchemaBuilder::group()}
     * and a field layout's custom fields through
     * {@see AbstractElementTarget::customFieldDescriptors()}.
     *
     * Targets that don't have a meaningful field surface for a given link
     * (e.g. the link is missing a section/type) may return an empty list.
     *
     * @return list<MappableField>
     */
    public function getMappableFields(Link $link): array;

    /**
     * The field layout this link's custom-field mappings address — the resolved
     * entry type's for an entry, the volume's for an asset, the single global one
     * for a user. Null when the criteria don't resolve one yet.
     *
     * The primitive behind {@see getMappableFields()}, exposed because a second
     * consumer needs the layout WITHOUT the mapping schemas built off it:
     * {@see \GlueAgency\Influx\services\EndpointTokensService} only wants the
     * custom fields' handles and types for its Resource Endpoint token picker, and
     * used to reach them through Entry's own resolver — which is why that picker
     * was silently empty for every non-Entry link.
     */
    public function fieldLayout(Link $link): ?FieldLayout;
}
