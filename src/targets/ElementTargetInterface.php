<?php

namespace GlueAgency\Influx\targets;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;

/**
 * Adapter that lets the sync engine talk to any element type. One
 * implementation per element type (Entry, Calendar Event, Commerce Product,
 * ...). Built-ins are registered by Influx; third-party targets register by
 * listening to TargetsService::EVENT_REGISTER_TARGETS.
 *
 * Each target owns three concerns:
 *   1. Decide whether it can handle a given link (typically by FQCN match).
 *   2. Find an existing element for a link item, or build a new one.
 *   3. Apply link-level requirements (section/type/calendar/etc.) so a freshly
 *      built element passes Craft's save-time validation.
 */
interface ElementTargetInterface
{
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
     * Is this target the right one for the given link?
     */
    public function handles(Link $link): bool;

    /**
     * Does this link claim this element? Used by the "Sync from remote"
     * button to decide whether to show, and by the per-element sync action
     * to look up the right link for an element.
     */
    public function claimsElement(Link $link, ElementInterface $element): bool;

    /**
     * Find an existing element matching the given key value, or null.
     * Implementations are expected to query across sites — multi-site links
     * rely on finding the same canonical element regardless of which site is
     * being processed first.
     */
    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface;

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
     * target has already handled this attribute during {@see buildNew()}.
     *
     * Example: Entry's `author` is read from the mapping's `default` and
     * assigned at construction time, so the engine must not try to also
     * assign it as a string value.
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
     * CP edit screen. Each field is reported as:
     *
     *   [
     *     'handle' => 'title',
     *     'name'   => 'Title',
     *     'native' => true,
     *     'group'  => 'Native' | 'Content' | ... // matches the field-layout
     *                                            // tab name for custom fields
     *     'defaultType' => 'text' | 'select' | 'element',
     *     // For 'select': map of value => label.
     *     'options' => ['live' => 'Live', 'disabled' => 'Disabled'],
     *     // For 'element': FQCN of the element type to pick from.
     *     'elementType' => craft\elements\User::class,
     *     // Optional: FQCN of the custom-field class (for typed-mapping
     *     // dispatch; null/absent for native fields).
     *     'fieldClass' => 'craft\\fields\\Assets',
     *     // Optional: opaque map of per-field-type meta the typed-mapping
     *     // UI / runtime needs (sources, sub-fields, dropdown options...).
     *     'fieldMeta' => [],
     *   ]
     *
     * Targets that don't have a meaningful field surface for a given link
     * (e.g. the link is missing a section/type) may return an empty list.
     *
     * @return list<array{handle: string, name: string, native: bool, group: string, defaultType: string, options?: array<string,string>, elementType?: class-string, fieldClass?: ?string, fieldMeta?: array}>
     */
    public function getMappableFields(Link $link): array;
}
