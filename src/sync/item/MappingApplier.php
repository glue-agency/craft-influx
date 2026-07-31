<?php

namespace GlueAgency\Influx\sync\item;

use Closure;
use craft\base\ElementInterface;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\SyncContext;
use Throwable;

/**
 * Walks a link's mappings against one remote item and writes the resolved
 * values onto the element, reporting one {@see MappingResult} per top-level
 * mapping.
 *
 * The SAME walk drives a related element's sub-mappings at any depth:
 * {@see applySubMappings()} re-enters this class through
 * {@see FieldContext::descend()}, so the empty/active policy, change detection,
 * and per-row error handling live in exactly one place instead of being
 * duplicated by a separate sub-element walker.
 *
 * Error policy: a throwing strategy fails its own row, never the item — the
 * error lands on {@see MappingResult::$error} and the walk continues. A
 * throwing *sub*-mapping propagates to its parent relation row (it has no row
 * of its own), matching how the debug view has always behaved.
 *
 * Empty policy (the feed is authoritative): an actively-mapped field whose
 * value is now empty, or whose value no longer resolves to anything, is
 * written through as empty — clearing the field. This holds uniformly across
 * native attributes, custom fields, and sub-element fields, at every depth.
 * Only a handle with no mapping at all ({@see FieldMapping::isActive()} false)
 * is left untouched.
 *
 * The clearing policy covers empty and unresolved values — NOT failed writes. A
 * relation whose referenced element could not be created throws
 * ({@see \GlueAgency\Influx\fields\Relation::persistNewElement()}), so the row
 * lands under the error policy above and the field keeps what it had; only the
 * feed genuinely going empty clears it. An empty write is a decision; a refused
 * one is an error, and the two must never look alike.
 *
 * Change detection: a row counts as "changed" only when the value it writes
 * differs from what the element already holds — custom fields via
 * {@see \GlueAgency\Influx\fields\Field::hasChanged()}, native attributes via
 * the target's {@see \GlueAgency\Influx\targets\ElementTargetInterface::applyNativeAttribute()}
 * return value (the target owns the comparison because only it knows each
 * attribute's semantics — e.g. that `author` compares by id). Per-row flags
 * stay genuine: an empty/missing value landing on an already-empty field is
 * not a change, even on a freshly-built element, so the debug view only flags
 * rows that actually receive a differing value. The caller folds these
 * per-row flags into the item's save decision (seeding it "changed" for a new
 * element, which always saves on its first pass); the same applies to related
 * elements, which {@see applySubMappings()} reports as changed only when a
 * sub-mapping actually differed — so unchanged relations no longer re-save on
 * every sync.
 *
 * Saving is not this class's business.
 */
class MappingApplier
{
    /**
     * Strategy-lookup seam handed to every {@see FieldContext} this walk builds
     * (and, through {@see FieldContext::descend()}, to every sub-mapping), so
     * neither this class nor a field strategy reaches FieldsService through the
     * plugin singleton mid-walk. Null = the context asks the plugin's registry
     * ({@see FieldContext::strategyFor()}).
     */
    protected ?Closure $strategyResolver = null;

    /**
     * @param callable|null $strategyResolver `fn(CraftFieldInterface): Field`.
     */
    public function __construct(?callable $strategyResolver = null)
    {
        $this->strategyResolver = $strategyResolver !== null ? Closure::fromCallable($strategyResolver) : null;
    }

    /**
     * Walk the link's mappings against one remote item, writing the resolved
     * values onto the element and returning one {@see MappingResult} per
     * top-level mapping. Per-row change flags stay genuine — an empty or
     * missing field reads as unchanged even on a freshly-built element, so the
     * debug view only flags rows that actually receive a differing value. The
     * caller folds these flags into the item's save decision.
     *
     * A handle with no field on the element's layout is a native attribute
     * (title/slug/enabled/...), handed to the target, which maps it to whatever
     * Craft accepts.
     *
     * @return list<MappingResult>
     */
    public function apply(
        SyncContext $syncContext,
        ElementInterface $element,
        RemoteItem $item,
    ): array {
        $link = $syncContext->link;
        $target = $syncContext->target;
        $layout = $element->getFieldLayout();

        $results = [];

        // One collector for the whole walk; descend() hands it to every
        // sub-mapping, so children attach to the row that opened their frame.
        $collector = new ChildResultCollector();

        foreach ($link->getMappingCollection() as $handle => $mapping) {
            if ($target->ownsAttribute($link, $handle)) {
                $results[] = new MappingResult(
                    handle: $handle,
                    node: $mapping->node,
                    default: $mapping->default,
                    native: true,
                    rawValue: $mapping->rawValue($item),
                    managedByTarget: true,
                );

                continue;
            }

            $craftField = $layout?->getFieldByHandle($handle);

            if ($craftField === null) {
                $result = $this->applyNativeAttribute($syncContext, $element, $handle, $mapping, $item);
            } else {
                $context = new FieldContext(
                    craftField: $craftField,
                    handle: $handle,
                    mapping: $mapping,
                    item: $item,
                    link: $link,
                    element: $element,
                    dryRun: $syncContext->dryRun,
                    lookups: $syncContext->lookups,
                    strategyResolver: $this->strategyResolver,
                    applier: $this,
                    childCollector: $collector,
                );
                $result = $this->applyCustomField($context);
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Apply a parent mapping's sub-mappings (`nativeFields` and `fields`) to a
     * related element, reusing the same custom-field path the top level uses.
     *
     * Never saves: whether the touched element gets persisted is the caller's
     * explicit, dry-run-aware decision
     * ({@see \GlueAgency\Influx\fields\RelationalField::persistSubElement()}).
     * Keeping persistence out of the walk is what lets the debug dry-run share
     * the exact same code path without side effects.
     *
     * Recursion (a sub-field that is itself a relation with its own sub-fields)
     * flows through {@see FieldContext::descend()}, which enforces the depth cap.
     *
     * A sub-handle that isn't on the related element's layout is skipped
     * silently. Sub-mappings are deliberately not wrapped in a try/catch: a
     * throwing sub-strategy propagates to the parent relation's row, the only
     * row it has.
     *
     * @return SubMappingOutcome The walk's per-sub-field rows —
     * {@see SubMappingOutcome::changed()} is the signal the caller uses to decide
     * whether the related element is worth saving, and the rows themselves are
     * what a {@see ChildResult} presents in the inspectors' drill-down.
     * @throws \GlueAgency\Influx\exceptions\MappingDepthException on runaway recursion
     */
    public function applySubMappings(FieldContext $parentContext, ElementInterface $element): SubMappingOutcome
    {
        if (! $parentContext->mapping->hasSubMappings()) {
            return new SubMappingOutcome();
        }

        $results = [];

        foreach ($parentContext->mapping->nativeSubMappings() as $sub) {
            $result = $this->applyNativeSubField($element, $parentContext->item, $sub);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        foreach ($parentContext->mapping->subMappings() as $sub) {
            $craftField = $element->getFieldLayout()?->getFieldByHandle($sub->handle);

            if (! $craftField) {
                continue;
            }

            $results[] = $this->mapCustomField($parentContext->descend($element, $sub, $craftField));
        }

        return new SubMappingOutcome($results);
    }

    /**
     * Apply one native-attribute mapping at the top level. An attribute the
     * item doesn't address — no node value and no `default` — is left
     * untouched; everything else is handed to the target, which both
     * writes the value (clearing the attribute when the feed value is empty)
     * and reports whether it actually changed. Change detection lives in the
     * target because only it knows each attribute's semantics — e.g. that
     * `author` compares by id, not by the relation object a naive before/after
     * read of `$element->author` would return.
     */
    protected function applyNativeAttribute(
        SyncContext $syncContext,
        ElementInterface $element,
        string $handle,
        FieldMapping $mapping,
        RemoteItem $item,
    ): MappingResult {
        $rawValue = $mapping->rawValue($item);
        $currentValue = $this->safeAttribute($element, $handle);

        if (! $mapping->addressedBy($item)) {
            return new MappingResult(
                handle: $handle,
                node: $mapping->node,
                default: $mapping->default,
                native: true,
                rawValue: $rawValue,
                currentValue: $currentValue,
                changed: false,
                unaddressed: true,
            );
        }

        try {
            $changed = $syncContext->target->applyNativeAttribute($syncContext, $element, $handle, $item, $mapping);
        } catch (Throwable $e) {
            return new MappingResult(
                handle: $handle,
                node: $mapping->node,
                default: $mapping->default,
                native: true,
                rawValue: $rawValue,
                currentValue: $currentValue,
                error: $e->getMessage(),
            );
        }

        return new MappingResult(
            handle: $handle,
            node: $mapping->node,
            default: $mapping->default,
            native: true,
            rawValue: $rawValue,
            currentValue: $currentValue,
            changed: $changed,
            usedDefault: $mapping->usesDefault($item),
        );
    }

    /**
     * Apply one native-attribute sub-mapping (title/slug on a related element).
     * Honours the same empty/active policy and change detection as the top
     * level, but writes the value directly — the related element type's own
     * value hygiene isn't reachable from here. Native sub-fields are always
     * title/slug strings, so a null-aware string compare suffices for change
     * detection.
     *
     * @return MappingResult|null The sub-field's row for the drill-down, or null
     * when the related element has no such attribute — skipped silently, as it
     * always has been.
     */
    protected function applyNativeSubField(ElementInterface $element, RemoteItem $item, FieldMapping $sub): ?MappingResult
    {
        if (! ($element->hasAttribute($sub->handle) || property_exists($element, $sub->handle))) {
            return null;
        }

        $rawValue = $sub->rawValue($item);

        if (! $sub->addressedBy($item)) {
            return new MappingResult(
                handle: $sub->handle,
                node: $sub->node,
                default: $sub->default,
                native: true,
                rawValue: $rawValue,
                currentValue: $this->safeAttribute($element, $sub->handle),
                changed: false,
                unaddressed: true,
            );
        }

        $before = $this->safeAttribute($element, $sub->handle);
        $value = $sub->resolve($item);
        $element->{$sub->handle} = $value;
        $after = $this->safeAttribute($element, $sub->handle);

        return new MappingResult(
            handle: $sub->handle,
            node: $sub->node,
            default: $sub->default,
            native: true,
            rawValue: $rawValue,
            parsedValue: $value,
            currentValue: $before,
            changed: (string) ($before ?? '') !== (string) ($after ?? ''),
            usedDefault: $sub->usesDefault($item),
        );
    }

    /**
     * Top-level custom-field row: {@see mapCustomField()} with strategy errors
     * captured as a per-mapping {@see MappingResult::$error} row so one broken
     * field never fails the whole item.
     */
    protected function applyCustomField(FieldContext $context): MappingResult
    {
        try {
            return $this->mapCustomField($context);
        } catch (Throwable $e) {
            return new MappingResult(
                handle: $context->handle,
                node: $context->mapping->node,
                default: $context->mapping->default,
                native: false,
                rawValue: $context->mapping->rawValue($context->item),
                currentValue: $this->safeFieldValue($context->element, $context->handle),
                error: $e->getMessage(),
            );
        }
    }

    /**
     * THE single definition of how a custom field is mapped — shared by the top
     * level and by every sub-mapping at any depth. Parses, applies the
     * empty/active policy, detects change, and writes. The addressed gate is
     * delegated to the strategy ({@see \GlueAgency\Influx\fields\Field::addressed()})
     * so a node-less parent whose value derives from sub-mappings (Matrix) can
     * still run. Strategy errors are not caught here: the caller decides whether
     * to capture (top level) or let them propagate to a parent relation row
     * (sub-mappings).
     *
     * The feed only touches a field it addresses — one it provides a node value
     * for, or carries a `default` for; an absent node leaves the field
     * untouched. An addressed-but-empty value clears the field, because the feed
     * is authoritative, and the row counts as changed only when the written
     * value differs from what's already there — so clearing an already-empty
     * field is not a change (a new element still saves regardless).
     *
     * The row's drill-down children come from two channels: a strategy that
     * walks sub-elements reports them as it goes, through the collector frame
     * this method opens; a strategy that derives them from its parsed value
     * reports them afterwards, through
     * {@see \GlueAgency\Influx\fields\Field::collectChildren()}. The hook wins
     * when both spoke. Children are decoration, so a throwing derivation is
     * swallowed — but a throwing parse/apply still propagates, and its walk's
     * children are dropped with it.
     */
    protected function mapCustomField(FieldContext $context): MappingResult
    {
        $rawValue = $context->mapping->rawValue($context->item);
        $currentValue = $this->safeFieldValue($context->element, $context->handle);
        $strategy = $context->strategyFor($context->craftField);

        if (! $strategy->addressed($context)) {
            return new MappingResult(
                handle: $context->handle,
                node: $context->mapping->node,
                default: $context->mapping->default,
                native: false,
                rawValue: $rawValue,
                currentValue: $currentValue,
                changed: false,
                unaddressed: true,
            );
        }

        $context->childCollector?->open();
        $hookChildren = null;

        try {
            $value = $strategy->parse($context);
            $rowChanged = $strategy->hasChanged($context, $value);

            $strategy->apply($context, $value);

            try {
                $hookChildren = $strategy->collectChildren($context, $value, $currentValue);
            } catch (Throwable) {
                // Children are decoration — a throwing derivation must not fail the row.
            }
        } finally {
            $collected = $context->childCollector?->close();
        }

        $children = $hookChildren ?? $collected;

        return new MappingResult(
            handle: $context->handle,
            node: $context->mapping->node,
            default: $context->mapping->default,
            native: false,
            rawValue: $rawValue,
            parsedValue: $value,
            currentValue: $currentValue,
            changed: $rowChanged,
            usedDefault: $context->mapping->usesDefault($context->item),
            children: $children,
            childrenType: $children !== null ? $strategy->childrenKind() : null,
        );
    }

    protected function safeAttribute(ElementInterface $element, string $handle): mixed
    {
        try {
            return $element->{$handle} ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function safeFieldValue(ElementInterface $element, string $handle): mixed
    {
        try {
            return $element->getFieldValue($handle);
        } catch (Throwable) {
            return null;
        }
    }
}
