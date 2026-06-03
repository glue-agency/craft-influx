<?php

namespace TDM\Influx\mappings;

use craft\base\ElementInterface;
use TDM\Influx\models\Feed;

/**
 * Each MappingInterface implementation handles one logical kind of
 * destination — plain text, a relation field, a matrix block, etc. — and is
 * responsible for two things:
 *
 *   1. Reading from the remote payload and applying the resulting value to
 *      the element (`apply`).
 *   2. Reporting whether the value it *would* apply differs from what the
 *      element currently holds (`hasChanged`). The sync engine uses this to
 *      avoid touching elements that haven't actually changed remotely.
 *
 * Mapping config (per element field handle) shape, from YAML:
 *
 *   mappings:
 *     title:
 *       type: PlainText      # registered handle (see MappingService)
 *       node: title.rendered # Hash dot-path into the JSON item
 *       options: { ... }     # mapping-specific extras
 */
interface MappingInterface
{
    /**
     * Stable, human-friendly key used in YAML (`type: PlainText`).
     */
    public static function type(): string;

    /**
     * Pull a value out of $item and assign it to $element under
     * $targetFieldHandle. Returns true if the element was mutated.
     */
    public function apply(
        ElementInterface $element,
        string $targetFieldHandle,
        array $item,
        array $config,
        Feed $feed,
    ): bool;

    /**
     * Would `apply()` change anything? Used by change-detection to short
     * circuit a no-op save. Implementations should be pure / side-effect-free.
     */
    public function hasChanged(
        ElementInterface $element,
        string $targetFieldHandle,
        array $item,
        array $config,
        Feed $feed,
    ): bool;
}
