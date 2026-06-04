<?php

namespace TDM\Influx\sync;

use craft\base\ElementInterface;
use TDM\Influx\Influx;
use TDM\Influx\models\Link;
use TDM\Influx\targets\ElementTargetInterface;

/**
 * Walks `$link->mappings` against one remote item and writes the resolved
 * values onto the element.
 *
 * Pulled out of {@see \TDM\Influx\services\SynchronizationService::processItem}
 * so the per-mapping dispatch (target-owned? native attribute? custom field
 * strategy?) lives in one place. The sync service still owns the event
 * lifecycle around an item — only the inside of the mapping foreach moved.
 *
 * Returns whether any write happened, which the caller turns into
 * created / updated / unchanged on the log.
 */
class MappingApplier
{
    /**
     * @param bool $isNew  When true, every write counts as a change (so a
     *                     freshly-built element always saves on the first
     *                     pass regardless of value-equality short-cuts).
     * @return bool        Whether the element was mutated.
     */
    public function apply(
        ElementTargetInterface $target,
        ElementInterface $element,
        Link $link,
        array $item,
        bool $isNew,
    ): bool {
        $changed = $isNew;
        $fields = Influx::getInstance()->fields;
        $layout = $element->getFieldLayout();

        foreach ($link->mappings as $handle => $config) {
            if (!is_array($config)) {
                continue;
            }

            if ($target->ownsAttribute($link, $handle)) {
                continue;
            }

            $craftField = $layout?->getFieldByHandle($handle);

            if ($craftField === null) {
                // Native attribute (title/slug/status/...) — let the target
                // translate to whatever attribute Craft actually accepts.
                if ($target->applyNativeAttribute($element, $handle, $item, $config)) {
                    $changed = true;
                }
                continue;
            }

            // Custom field — dispatch through the per-field-type strategy.
            $strategy = $fields->forCraftField($craftField);
            $strategy->setContext($craftField, $handle, $config, $item, $link, $element);

            $value = $strategy->parseField();
            if ($value === null) {
                continue;
            }

            if (!$changed && $strategy->hasChanged($element, $value)) {
                $changed = true;
            }

            $strategy->apply($element, $value);
        }

        return $changed;
    }
}
