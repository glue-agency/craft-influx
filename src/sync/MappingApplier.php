<?php

namespace GlueAgency\Influx\sync;

use craft\base\ElementInterface;
use GlueAgency\Influx\Influx;
use Throwable;

/**
 * Walks the link's mappings against one remote item and writes the resolved
 * values onto the element, reporting one {@see MappingResult} per mapping.
 *
 * Error policy: a throwing strategy fails its own row, never the item — the
 * error lands on {@see MappingResult::$error} and the walk continues. This
 * matches what the debug view always did, so a feed that "debugs fine with
 * one red row" behaves identically when actually run.
 *
 * Saving is not this class's business; the aggregate
 * {@see MappingOutcome::$changed} flag tells the caller whether persisting
 * is worth it.
 */
class MappingApplier
{
    /**
     * @param bool $isNew When true, every write counts as a change (so a
     * freshly-built element always saves on the first pass regardless of
     * value-equality short-cuts).
     */
    public function apply(
        SyncContext $syncContext,
        ElementInterface $element,
        RemoteItem $item,
        bool $isNew,
    ): MappingOutcome {
        $link = $syncContext->link;
        $target = $syncContext->target;
        $fields = Influx::getInstance()->fields;
        $layout = $element->getFieldLayout();

        $changed = $isNew;
        $results = [];

        foreach ($link->getMappingCollection() as $handle => $mapping) {
            $rawValue = $mapping->rawValue($item);

            if ($target->ownsAttribute($link, $handle)) {
                $results[] = new MappingResult(
                    handle: $handle,
                    node: $mapping->node,
                    default: $mapping->default,
                    native: true,
                    rawValue: $rawValue,
                    note: 'Managed by target.',
                );

                continue;
            }

            $craftField = $layout?->getFieldByHandle($handle);

            if ($craftField === null) {
                // Native attribute (title/slug/status/...) — let the target
                // translate to whatever attribute Craft actually accepts.
                $currentValue = $this->safeAttribute($element, $handle);

                try {
                    $wrote = $target->applyNativeAttribute($element, $handle, $item, $mapping);
                } catch (Throwable $e) {
                    $results[] = new MappingResult(
                        handle: $handle,
                        node: $mapping->node,
                        default: $mapping->default,
                        native: true,
                        rawValue: $rawValue,
                        currentValue: $currentValue,
                        error: $e->getMessage(),
                    );

                    continue;
                }

                if ($wrote) {
                    $changed = true;
                }
                $results[] = new MappingResult(
                    handle: $handle,
                    node: $mapping->node,
                    default: $mapping->default,
                    native: true,
                    rawValue: $rawValue,
                    currentValue: $currentValue,
                    changed: $wrote,
                );

                continue;
            }

            // Custom field — dispatch through the per-field-type strategy.
            $currentValue = $this->safeFieldValue($element, $handle);
            $context = new FieldContext(
                craftField: $craftField,
                handle: $handle,
                mapping: $mapping,
                item: $item,
                link: $link,
                element: $element,
                dryRun: $syncContext->dryRun,
            );
            $strategy = $fields->forCraftField($craftField);

            try {
                $value = $strategy->parse($context);

                if ($value === null) {
                    $results[] = new MappingResult(
                        handle: $handle,
                        node: $mapping->node,
                        default: $mapping->default,
                        native: false,
                        rawValue: $rawValue,
                        currentValue: $currentValue,
                        changed: false,
                        note: 'Strategy returned null — field left untouched.',
                    );

                    continue;
                }

                $rowChanged = $isNew ? true : $strategy->hasChanged($context, $value);

                if ($rowChanged) {
                    $changed = true;
                }

                $strategy->apply($context, $value);

                $results[] = new MappingResult(
                    handle: $handle,
                    node: $mapping->node,
                    default: $mapping->default,
                    native: false,
                    rawValue: $rawValue,
                    parsedValue: $value,
                    currentValue: $currentValue,
                    changed: $rowChanged,
                );
            } catch (Throwable $e) {
                $results[] = new MappingResult(
                    handle: $handle,
                    node: $mapping->node,
                    default: $mapping->default,
                    native: false,
                    rawValue: $rawValue,
                    currentValue: $currentValue,
                    error: $e->getMessage(),
                );
            }
        }

        return new MappingOutcome($changed, $results);
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
