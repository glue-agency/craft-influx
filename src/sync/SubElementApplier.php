<?php

namespace GlueAgency\Influx\sync;

use craft\base\ElementInterface;
use GlueAgency\Influx\Influx;

/**
 * Applies a parent mapping's sub-mappings (`nativeFields` and `fields`) to a
 * related element.
 *
 * Never saves: whether the touched element gets persisted is the caller's
 * explicit, dry-run-aware decision
 * ({@see \GlueAgency\Influx\fields\Relation::populateSubElement()},
 * {@see \GlueAgency\Influx\fields\Assets::applySubFields()}). Keeping persistence
 * out of the walker is what lets the debug dry-run share the exact same
 * code path without side effects.
 *
 * Recursion (a sub-field that's itself a relation with its own sub-fields)
 * flows through {@see FieldContext::descend()}, which enforces the depth cap.
 *
 * Sub-mappings whose handle isn't on the related element's layout are
 * skipped silently for now — surfacing those as notes on the parent's
 * {@see MappingResult} needs a richer parse() return and is deliberately
 * deferred.
 */
class SubElementApplier
{
    /**
     * @return bool Whether any sub-mapping wrote to the element.
     * @throws \GlueAgency\Influx\exceptions\MappingDepthException on runaway recursion
     */
    public function apply(ElementInterface $element, FieldContext $parentContext): bool
    {
        if (!$parentContext->mapping->hasSubMappings()) {
            return false;
        }

        $touched = $this->applyNative($element, $parentContext);
        return $this->applyCustom($element, $parentContext) || $touched;
    }

    protected function applyNative(ElementInterface $element, FieldContext $parentContext): bool
    {
        $touched = false;
        foreach ($parentContext->mapping->nativeSubMappings() as $sub) {
            $value = $sub->resolve($parentContext->item);
            if ($value === null) {
                continue;
            }
            if ($element->hasAttribute($sub->handle) || property_exists($element, $sub->handle)) {
                $element->{$sub->handle} = $value;
                $touched = true;
            }
        }
        return $touched;
    }

    protected function applyCustom(ElementInterface $element, FieldContext $parentContext): bool
    {
        $touched = false;
        $registry = Influx::getInstance()->fields;

        foreach ($parentContext->mapping->subMappings() as $sub) {
            $craftField = $element->getFieldLayout()?->getFieldByHandle($sub->handle);
            if (!$craftField) {
                continue;
            }

            $context = $parentContext->descend($element, $sub, $craftField);
            $strategy = $registry->forCraftField($craftField);

            $value = $strategy->parse($context);
            if ($value === null) {
                continue;
            }

            $strategy->apply($context, $value);
            $touched = true;
        }
        return $touched;
    }
}
