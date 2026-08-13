<?php

namespace GlueAgency\Influx\fields;

use Cake\Utility\Hash;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\enums\ChildAction;
use GlueAgency\Influx\helpers\Comparable;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\RemoteItem;
use Throwable;

/**
 * Per-Craft-field-type mapping strategy. One concrete subclass per `craft\fields\*`
 * class whose mapping behaviour genuinely diverges from the default; everything
 * else falls through to {@see DefaultField}.
 *
 * Strategies are stateless shared singletons (see
 * {@see \GlueAgency\Influx\services\FieldsService}): everything a call needs travels
 * in an immutable {@see FieldContext}, so the sub-mapping recursion can safely
 * re-enter the same instance.
 *
 * Lifecycle, driven by {@see \GlueAgency\Influx\sync\item\MappingApplier}:
 *
 *   $value = $strategy->parse($context);
 *   // An actively-mapped field is always written — a null/empty value clears
 *   // it (the feed is authoritative). hasChanged() only decides whether the
 *   // write counts toward the element's save-worthy "changed" flag.
 *   $changed = $strategy->hasChanged($context, $value);
 *   $strategy->apply($context, $value);
 *
 * `parse()` is the one method subclasses have to implement; everything else
 * has a sensible default in this base.
 */
abstract class Field
{
    /**
     * Cap on the children one mapping row emits ({@see collectChildren()}). A
     * runaway feed — a node that fans out into thousands of blocks or table
     * rows — would otherwise balloon the debug inspector's payload and the log
     * rows a run stores, for no diagnostic gain: the first hundred already show
     * what the mapping does. The cap is PER MAPPING ROW, so every nesting row of
     * a link gets its own hundred.
     */
    protected const CHILD_RESULT_LIMIT = 100;

    /**
     * FQCN of the Craft field class this strategy handles. Return `null` to
     * register as the generic fallback (only {@see DefaultField} should).
     *
     * Subclasses may also point at a base class (e.g. `BaseOptionsField`)
     * to cover a whole family — {@see \GlueAgency\Influx\services\FieldsService}
     * walks the parent chain on lookup.
     */
    public static function craftFieldClass(): ?string
    {
        return null;
    }

    /**
     * Whether this field type can serve as a link's MATCH attribute — the unique
     * key an item is paired with an existing element by.
     *
     * Matching runs the stored value straight at an element query
     * (`Entry::find()->{$handle}($value)`), so it only means anything for a field
     * holding one comparable value that a feed can carry as an external
     * identifier. Default true: that covers the scalar-ish types and, importantly,
     * {@see DefaultField} — a field type Influx has no strategy for is usually a
     * plain value, and refusing it by default would silently narrow the option list
     * for every third-party field.
     *
     * A strategy returns false when its value can't identify anything. That's not
     * the same as "the query would fail": a relation field's
     * `BaseRelationField::queryCondition()` builds a perfectly valid condition — it
     * just filters by relation to an element ID, so the only feed value that could
     * ever match is Craft's own id for the related element, which is not an
     * identifier for the element being matched. Offering it produced a match key
     * that silently matched nothing.
     *
     * Read by {@see \GlueAgency\Influx\services\LinkBuilderService::mappableFields()}
     * when it builds the Match attribute dropdown.
     */
    public static function matchable(): bool
    {
        return true;
    }

    /**
     * Resolve the remote item + per-field mapping into the value the element
     * field should hold.
     *
     * Contract:
     *   - return null — "no data for this mapping, leave the field untouched";
     *   - throw       — present-but-malformed data; the applier surfaces it
     *                   as a per-mapping error.
     *
     * Strategies with side effects (creating missing relations, uploading
     * assets, writing to related elements) must honour `$context->dryRun`
     * and skip them when set — the debug inspector runs this exact code path.
     */
    abstract public function parse(FieldContext $context): mixed;

    /**
     * THE declaration of this field type's whole mapping row — its source-node
     * cell, its default-value cell and its extras — as three regions of schema
     * nodes the SPA renders through one `type => component` map.
     *
     * The default is the shape most field types want: a source-node select and a
     * plain text default. A strategy overrides to say something different, and
     * says it by ABSENCE where a cell doesn't apply — a Matrix declares neither
     * cell because its value comes from sub-mappings, and a Preparse field
     * declares a source region holding nothing but a note. See
     * {@see MappingSchema} for the three ways to declare a region.
     */
    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            'source'  => true,
            'default' => true,
        ]);
    }

    /**
     * The options a lazily-declared default select offers, resolved on demand
     * rather than shipped with the row.
     *
     * Default: whatever the `default` region already declared, so a strategy
     * carrying its options inline needs nothing here — the lazy endpoint and the
     * bootstrap answer with the same list either way.
     *
     * A strategy overrides this AND declares `'lazy' => true` on its default node
     * when the list is big enough to be worth a round-trip: every builder
     * bootstrap otherwise pays for it once per field on the layout, whether or not
     * the operator ever opens that row. Options that ARE the field's own settings
     * (an option field's, a colour palette) are already in memory and not worth
     * deferring; a repository lookup of every country in the world is.
     *
     * @return array<string, string> value => label
     */
    public function defaultOptions(CraftFieldInterface $field): array
    {
        $node = $this->schema($field)->toArray()['default'][0] ?? [];

        return static::optionsAsMap($node['options'] ?? []);
    }

    /**
     * A node's option LIST as the `value => label` MAP the lazy endpoint speaks.
     * The two shapes exist because a node carries an ordered list while the
     * endpoint is keyed; this is the one place they meet.
     *
     * A row with an empty value is dropped defensively: the "nothing picked" row
     * is a sentinel on the node rather than one of the options
     * ({@see MappingSchemaBuilder::defaultSelect()}), so it should never reach
     * here — but a field declaring a genuinely valueless option would be offering
     * the lazy endpoint a key it can't address.
     *
     * @param list<array{value: string, label: string}> $options
     * @return array<string, string>
     */
    public static function optionsAsMap(array $options): array
    {
        $map = [];

        foreach ($options as $option) {
            if (! is_array($option) || ! isset($option['value']) || $option['value'] === '') {
                continue;
            }

            $map[(string) $option['value']] = (string) ($option['label'] ?? $option['value']);
        }

        return $map;
    }

    /**
     * The row ANOTHER field renders when nested under this one — its default cell
     * and its extras, which this has to ask that field's strategy for because how a
     * field is configured is its own business, not its parent's.
     *
     * A seam rather than a direct service call so a schema spec can answer for it
     * without a booted plugin, in the same idiom as this class's other registry
     * seams. See {@see \GlueAgency\Influx\services\FieldsService::childRowFor()}
     * for what a nested row gets and why it stops at one card level.
     *
     * @return array{default: array|null, extra: list<array>}
     */
    protected function childRowFor(CraftFieldInterface $craftField): array
    {
        return Influx::getInstance()->fields->childRowFor($craftField);
    }

    /**
     * Set the parsed value on the context's element. Default: route to
     * `setFieldValue`, which is correct for every custom field. Subclasses
     * override only when they need something more involved.
     */
    public function apply(FieldContext $context, mixed $value): bool
    {
        $context->element->setFieldValue($context->handle, $value);

        return true;
    }

    /**
     * Whether the incoming value differs from what the element currently holds.
     * The sync engine uses this to skip elements that nothing has changed for.
     *
     * This is the template: it reads the current field value once and hands
     * both values to {@see valueDiffers()}, which subclasses override to
     * express their comparison semantics. Reading the field value can throw
     * (a related-element query failing, a field mid-migration, ...); an
     * unreadable current value ⇒ assume changed so the write still happens.
     */
    public function hasChanged(FieldContext $context, mixed $incoming): bool
    {
        try {
            $current = $context->element->getFieldValue($context->handle);

            return $this->valueDiffers($context, $current, $incoming);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * What is wrong with this mapping's CONFIG, as messages for the operator —
     * empty when nothing is. The save-time half of a strategy's own rules, run
     * from {@see \GlueAgency\Influx\models\Link::validateMappings()} and surfaced
     * on the row the mapping belongs to.
     *
     * Config only, and that bound is the whole point: this runs with no feed, no
     * item and no element, so it can only judge what a link stores. "This node
     * isn't in the response" is a run-time fact and belongs in a log, not in a
     * save that would then depend on a remote service being up.
     *
     * A strategy that implements this MUST drive its run-time throw off the same
     * predicate rather than a second copy — a save and a run disagreeing about
     * what is valid is worse than neither checking ({@see Matrix} for the shape
     * that keeps them one).
     *
     * Messages are sentences about "this mapping" and never name the handle: the
     * error is keyed to the row, which already says which field it is.
     *
     * Custom fields only. A native attribute has no strategy filed against it —
     * its rules belong to the target that declares it — so the dispatch skips
     * one rather than handing it to the fallback strategy, which knows nothing
     * about it. That's a deliberate bound, not an oversight.
     *
     * @return list<string>
     */
    public function validateMapping(FieldMapping $mapping): array
    {
        return [];
    }

    /**
     * Whether the feed addresses this mapping for the given item — the gate
     * {@see \GlueAgency\Influx\sync\item\MappingApplier} consults before running the
     * strategy at all. Default: the mapping's own node/default addressing.
     * Strategies whose value derives from SUB-mappings rather than an own node
     * ({@see Matrix}) override this, because a node-less parent mapping would
     * otherwise always read as unaddressed.
     */
    public function addressed(FieldContext $context): bool
    {
        return $context->mapping->addressedBy($context->item);
    }

    /**
     * Per-child drill-down entries for this row, derived AFTER parse/apply from
     * the value the field is receiving and the one it held. The channel for a
     * strategy whose children fall out of its parsed value (Matrix blocks);
     * a strategy that walks sub-elements instead reports each child as it goes,
     * through {@see FieldContext::$childCollector}.
     *
     * Called on dry runs and real runs alike, so it must be side-effect free —
     * it's the inspectors' presentation of what the row did, not part of doing
     * it.
     *
     * @return list<ChildResult>|null Null = this strategy nests nothing.
     */
    public function collectChildren(FieldContext $context, mixed $incoming, mixed $current): ?array
    {
        return null;
    }

    /**
     * Back-fill identity onto the children a mapping row already carries, once
     * the owner element has been committed — the real run's counterpart to
     * {@see collectChildren()}, which derives them BEFORE the save. The seam
     * exists for a strategy whose children only become elements at save time: a
     * Matrix block the sync adds has no element (and often no title) at
     * derivation time because nothing is persisted yet, so the log snapshot would
     * head it with an ordinal instead of a chip. By the time the snapshot is
     * presented those blocks ARE saved elements, and this is where they get
     * zipped back on.
     *
     * Default: nothing to fill. A strategy that implements it must be
     * read-tolerant — the value is read back off a live element right after its
     * save, and a failed read degrades to "no back-fill", never to a thrown row —
     * and must leave every already-identified child exactly as it is: what the
     * derivation resolved is its verdict.
     *
     * Level 1 only, by design: a child row may itself carry children (a relation
     * nested inside a block), but those are already-saved related elements, and a
     * second level down has no ordering guarantee left to pair on. The caller
     * ({@see \GlueAgency\Influx\sync\item\ItemRunner}) walks the top-level rows
     * and stops there.
     *
     * @param string $handle the owner element's field handle for this row
     * @param list<ChildResult> $children the row's raw children, mutated in place
     */
    public function attachSavedChildren(ElementInterface $element, string $handle, array $children): void
    {
    }

    /**
     * Noun key for the drill count summary ("3 blocks", "2 assets"):
     * `'blocks'|'rows'|'assets'|'entries'|'users'|'categories'|'tags'|'elements'`.
     * Null for a strategy that nests nothing.
     */
    public function childrenKind(): ?string
    {
        return null;
    }

    /**
     * The action string a child carries: the hypothetical label on a dry run,
     * the committed value on a real one ({@see ChildAction::dryRunLabel()}).
     * Shared by the full-replace strategies, which decide a child's action
     * themselves rather than reporting one they just performed
     * ({@see RelationalField::reportChild()} is past every dry-run guard, so it
     * needs none of this).
     */
    protected function childActionLabel(FieldContext $context, ChildAction $action): string
    {
        return $context->dryRun ? $action->dryRunLabel() : $action->value;
    }

    /**
     * The index of the first not-yet-consumed entry equal to `$needle` — the one
     * greedy step a full-replace pairing takes, over the per-child fingerprints
     * first and then over a positional key ({@see Matrix::pairBlocks()},
     * {@see Table::pairRows()}).
     *
     * @param list<mixed> $values
     * @param array<int, true> $consumed
     */
    protected function firstUnconsumed(array $values, array $consumed, mixed $needle): ?int
    {
        foreach ($values as $index => $value) {
            if (! isset($consumed[$index]) && $value === $needle) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Compare the element's current field value against the incoming one.
     * Called by {@see hasChanged()} with the already-read current value, so
     * the read-failure guard lives in one place. Default: normalise both
     * sides and compare — which already covers scalars, bools, dates and
     * elements ({@see normalize()}); subclasses override only for semantics no
     * per-value normalisation can express (id-set comparison, HTML
     * serialisation, per-block fingerprints).
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        return $this->normalize($current) !== $this->normalize($incoming);
    }

    /**
     * Normalise a resolved value into a per-row value list — the first half of
     * the index-zip rule two strategies share ({@see Matrix}'s blocks,
     * {@see Table}'s rows). A list array is one value per row; anything else (a
     * scalar, or an assoc array that is ONE row's value) becomes a
     * single-element list.
     *
     * @return list<mixed>
     */
    protected function valueList(mixed $resolved): array
    {
        return is_array($resolved) && array_is_list($resolved) ? $resolved : [$resolved];
    }

    /**
     * The largest per-row list length across every contributing value list —
     * the row count, and the second half of the shared zip rule. Ragged lists
     * yield the longest; how a row that outlives a short list is filled is the
     * caller's call (Matrix leaves the key off, Table writes a null cell).
     *
     * @param list<list<mixed>> $lists
     */
    protected function maxLength(array $lists): int
    {
        $max = 0;

        foreach ($lists as $values) {
            $max = max($max, count($values));
        }

        return $max;
    }

    /**
     * Coerce one raw child value through the child field's OWN strategy, so its
     * per-field options (match, truthy, format, …) apply inside a nested
     * structure exactly as they would at the top level. The synthetic
     * single-value item makes the child's own `resolve()` yield exactly this
     * value; a node-less (useDefault-only) child is item-independent and reuses
     * the parent item.
     *
     * Shared by every strategy that walks a sub-mapping tree — {@see Matrix}'s
     * blocks, {@see ContentBlock}'s fields, {@see Addresses}'s address records.
     *
     * @param ElementInterface $carrier the element a child strategy reads its
     * field settings against — the nested element where one exists, the owner
     * as a stand-in where it can't be built yet.
     * @throws \GlueAgency\Influx\exceptions\MappingDepthException past MAX_DEPTH
     */
    protected function coerceChildValue(
        FieldContext $context,
        ElementInterface $carrier,
        FieldMapping $sub,
        CraftFieldInterface $childCraftField,
        mixed $value,
    ): mixed {
        $childItem = $sub->node !== null
            ? new RemoteItem(Hash::insert([], $sub->node, $value))
            : $context->item;

        $childContext = $context->descend($carrier, $sub, $childCraftField, $childItem);

        return $this->childStrategy($context, $childCraftField)->parse($childContext);
    }

    /**
     * Resolve the mapping strategy for a child craft field, through the seam the
     * context carries ({@see FieldContext::strategyFor()}) rather than the plugin
     * singleton — a field strategy has no business reaching a global mid-walk.
     * Still extracted so tests can record the {@see FieldContext} a child
     * receives and return a marker.
     */
    protected function childStrategy(FieldContext $context, CraftFieldInterface $childCraftField): Field
    {
        return $context->strategyFor($childCraftField);
    }

    /**
     * The mappings out of a set that actually carry something — the active-only
     * rule every sub-mapping strategy applies before walking a tree, so a handle
     * with neither a node nor an explicit default contributes nothing.
     *
     * @param iterable<FieldMapping> $mappings
     * @return list<FieldMapping>
     */
    protected function filterActive(iterable $mappings): array
    {
        $active = [];

        foreach ($mappings as $mapping) {
            if ($mapping->isActive()) {
                $active[] = $mapping;
            }
        }

        return $active;
    }

    /**
     * The field layer's seam onto {@see Comparable::of()} — the one comparison
     * normaliser, shared with the targets' native-attribute path so a bool, a
     * date or a related element compares the same either side. Subclasses reach
     * for it when they build their own comparison (e.g. {@see Matrix}'s
     * per-block fingerprint) instead of re-normalising leaves themselves.
     *
     * Also the per-field-type override point for a value whose comparable form
     * needs more than {@see Comparable::of()} can see — a serialized date is one
     * ({@see Date::normalize()}). Default is the shared normaliser verbatim, and
     * {@see Matrix} routes each fingerprint leaf through the OWNING strategy's
     * version so both sides of a leaf reduce the same way.
     */
    protected function normalize(mixed $value): mixed
    {
        return Comparable::of($value);
    }
}
