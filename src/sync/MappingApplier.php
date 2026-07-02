<?php

namespace GlueAgency\Influx\sync;

use craft\base\ElementInterface;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\FieldMapping;
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
     * Walk the link's mappings against one remote item, writing the resolved
     * values onto the element and returning one {@see MappingResult} per
     * top-level mapping. Per-row change flags stay genuine — an empty or
     * missing field reads as unchanged even on a freshly-built element, so the
     * debug view only flags rows that actually receive a differing value. The
     * caller folds these flags into the item's save decision.
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

        foreach ($link->getMappingCollection() as $handle => $mapping) {
            if ($target->ownsAttribute($link, $handle)) {
                $results[] = new MappingResult(
                    handle: $handle,
                    node: $mapping->node,
                    default: $mapping->default,
                    native: true,
                    rawValue: $mapping->rawValue($item),
                    note: 'Managed by target.',
                );

                continue;
            }

            $craftField = $layout?->getFieldByHandle($handle);

            if ($craftField === null) {
                // Native attribute (title/slug/status/...) — the target
                // translates it to whatever attribute Craft actually accepts.
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
     * @return bool Whether any sub-mapping wrote a differing value — the signal
     * the caller uses to decide whether the related element is worth saving.
     * @throws \GlueAgency\Influx\exceptions\MappingDepthException on runaway recursion
     */
    public function applySubMappings(FieldContext $parentContext, ElementInterface $element): bool
    {
        if (! $parentContext->mapping->hasSubMappings()) {
            return false;
        }

        $changed = false;

        foreach ($parentContext->mapping->nativeSubMappings() as $sub) {
            if ($this->applyNativeSubField($element, $parentContext->item, $sub)) {
                $changed = true;
            }
        }

        foreach ($parentContext->mapping->subMappings() as $sub) {
            $craftField = $element->getFieldLayout()?->getFieldByHandle($sub->handle);

            if (! $craftField) {
                // Sub-mappings whose handle isn't on the related element's
                // layout are skipped silently — surfacing those as notes needs
                // a richer return and is deliberately deferred.
                continue;
            }

            // No try/catch here: a throwing sub-strategy propagates to the
            // parent relation's row (it has no row of its own).
            if ($this->mapCustomField($parentContext->descend($element, $sub, $craftField))->changed === true) {
                $changed = true;
            }
        }

        return $changed;
    }

    // -- native attributes ----------------------------------------------------

    /**
     * Apply one native-attribute mapping at the top level. Unmapped attributes
     * are left untouched; everything else is handed to the target, which both
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
            // Node absent from this item (and no default) — leave the attribute
            // alone rather than clearing it.
            return new MappingResult(
                handle: $handle,
                node: $mapping->node,
                default: $mapping->default,
                native: true,
                rawValue: $rawValue,
                currentValue: $currentValue,
                changed: false,
                note: 'Feed has no value for this attribute — left untouched.',
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
        );
    }

    /**
     * Apply one native-attribute sub-mapping (title/slug on a related element).
     * Honours the same empty/active policy and change detection as the top
     * level, but writes the value directly — the related element type's own
     * value hygiene isn't reachable from here.
     *
     * @return bool Whether the attribute's value actually changed.
     */
    protected function applyNativeSubField(ElementInterface $element, RemoteItem $item, FieldMapping $sub): bool
    {
        if (! ($element->hasAttribute($sub->handle) || property_exists($element, $sub->handle))) {
            return false;
        }

        // Node absent from this item (and no default) — leave it untouched
        // rather than clearing it.
        if (! $sub->addressedBy($item)) {
            return false;
        }

        $before = $this->safeAttribute($element, $sub->handle);
        // An active-but-empty sub-mapping resolves to null and clears the
        // attribute — the feed is authoritative at every depth.
        $element->{$sub->handle} = $sub->resolve($item);
        $after = $this->safeAttribute($element, $sub->handle);

        // Native sub-fields are only ever title/slug (plain strings), so a
        // null-aware string compare is enough to decide whether the related
        // element is worth re-saving.
        return (string) ($before ?? '') !== (string) ($after ?? '');
    }

    // -- custom fields --------------------------------------------------------

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
     * empty/active policy, detects change, and writes. Strategy errors are not
     * caught here: the caller decides whether to capture (top level) or let
     * them propagate to a parent relation row (sub-mappings).
     */
    protected function mapCustomField(FieldContext $context): MappingResult
    {
        $rawValue = $context->mapping->rawValue($context->item);
        $currentValue = $this->safeFieldValue($context->element, $context->handle);

        // The feed only touches a field it addresses: a node it provides a
        // value for (even an explicit empty string), or an explicit default. A
        // node that's simply absent from this item is left untouched — the feed
        // isn't saying "make this empty", it just doesn't mention the field.
        if (! $context->mapping->addressedBy($context->item)) {
            return new MappingResult(
                handle: $context->handle,
                node: $context->mapping->node,
                default: $context->mapping->default,
                native: false,
                rawValue: $rawValue,
                currentValue: $currentValue,
                changed: false,
                note: 'Feed has no value for this field — left untouched.',
            );
        }

        $strategy = Influx::getInstance()->fields->forCraftField($context->craftField);

        // An addressed-but-empty value (explicit empty string, or one that no
        // longer resolves) clears the field — the feed is authoritative. The
        // row is "changed" only when the value differs from what the element
        // already holds, so a clear of an already-empty field is not a change,
        // even on a new element (the element still saves; see apply()).
        $value = $strategy->parse($context);
        $rowChanged = $strategy->hasChanged($context, $value);

        $strategy->apply($context, $value);

        return new MappingResult(
            handle: $context->handle,
            node: $context->mapping->node,
            default: $context->mapping->default,
            native: false,
            rawValue: $rawValue,
            parsedValue: $value,
            currentValue: $currentValue,
            changed: $rowChanged,
        );
    }

    // -- shared helpers -------------------------------------------------------

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
