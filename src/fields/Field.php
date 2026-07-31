<?php

namespace GlueAgency\Influx\fields;

use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\helpers\Comparable;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\ChildResult;
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
     * Optional extra UI metadata, merged into the payload by
     * {@see \GlueAgency\Influx\services\FieldsService::metaFor()}. The mapping
     * extras UI is declared via {@see schema()} — the primary
     * contract, with labels co-located on each node — so most strategies
     * never need this. Override only to ship structured meta a schema node
     * can't express; `schema` is a reserved key set by metaFor.
     */
    public function fieldMeta(CraftFieldInterface $field): array
    {
        return [];
    }

    /**
     * Declarative form schema for this field type's mapping-extras block — a
     * {@see SchemaBuilder} the SPA renders generically. Declaring the UI next
     * to the parse logic is what keeps the Vue side free of per-field-type
     * branches: adding a kind is a single-PHP-file change.
     *
     * Default: an empty builder (no extras).
     */
    public function schema(CraftFieldInterface $field): SchemaBuilder
    {
        return SchemaBuilder::make();
    }

    /**
     * How the builder should render this field's default-value editor — the
     * "— use default —" cell of a mapping row. Null (the base) leaves it a plain
     * text input, which is right for anything the operator types.
     *
     * The keys are the ones {@see SchemaBuilder::group()} reads off a declared
     * node, so a CUSTOM field describes its editor in exactly the vocabulary a
     * native attribute already does: `type` ({@see SchemaBuilder::TEXT},
     * {@see SchemaBuilder::SELECT} or {@see SchemaBuilder::ELEMENT}) plus
     * `options` for a select / `elementType` for an element picker.
     *
     * Overriding this is a promise {@see parse()} has to keep: a default picked
     * from a select or an element picker is a stored value / an element id, so
     * the strategy must stop putting it through the feed-value matching its
     * `options.match` describes ({@see Relation::parse()}).
     *
     * @return array{type: string, options?: array<string, string>, elementType?: class-string}|null
     */
    public function defaultEditor(CraftFieldInterface $field): ?array
    {
        return null;
    }

    /**
     * Wrap a builder-schema node list in the `fieldMeta` envelope the SPA's
     * SchemaForm consumes: the {@see schema()} nodes, with any extra meta
     * merged in. THE one place the `{schema}` shape is defined — every mappable
     * field routes its schema through here, whether it's a custom field (via
     * {@see \GlueAgency\Influx\services\FieldsService::metaFor()}) or a native
     * attribute declared by an {@see \GlueAgency\Influx\targets\ElementTargetInterface}.
     * `schema` is reserved and wins over `$extra`.
     *
     * @param list<array> $schema SchemaBuilder nodes.
     * @param array<string, mixed> $extra Extra meta keys (e.g. `subfieldsOnly`).
     * @return array<string, mixed>
     */
    public static function meta(array $schema, array $extra = []): array
    {
        return ['schema' => $schema] + $extra;
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
     * `'blocks'|'assets'|'entries'|'users'|'categories'|'tags'|'elements'`.
     * Null for a strategy that nests nothing.
     */
    public function childrenKind(): ?string
    {
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
