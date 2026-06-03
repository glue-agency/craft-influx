<?php

namespace TDM\Influx\mappings;

use craft\base\ElementInterface;
use TDM\Influx\models\Link;

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
 * Mapping config (per element field handle) shape, from project config:
 *
 *   mappings:
 *     title:
 *       type: PlainText
 *       node: title.rendered
 *       options: { ... }
 */
interface MappingInterface
{
    public static function type(): string;

    public function apply(
        ElementInterface $element,
        string $targetFieldHandle,
        array $item,
        array $config,
        Link $link,
    ): bool;

    public function hasChanged(
        ElementInterface $element,
        string $targetFieldHandle,
        array $item,
        array $config,
        Link $link,
    ): bool;
}
