<?php

namespace TDM\Influx\fields;

use Cake\Utility\Hash;
use Craft;
use craft\base\ElementInterface;
use TDM\Influx\Influx;
use TDM\Influx\models\Link;

/**
 * Recursively applies sub-mappings (under `fields[...]` and `nativeFields[...]`)
 * to a related element and saves it. Pulled out of {@see Relation} and
 * {@see Assets} so the recursive walker — and the "did anything change?"
 * tracking — lives in one place.
 *
 * Invocation sites:
 *   - {@see Relation::populateSubElement()} — inherited by every relation
 *     strategy ({@see Entries}, {@see Categories}, {@see Tags}, {@see Users}),
 *     so sub-mappings work uniformly across all of them.
 *   - {@see Assets::applySubFields()} — Assets isn't a Relation but its
 *     sub-field model (alt/title on the matched asset) needs the same walker.
 *
 * A sub-field that's itself a Relation (an entry inside an entry, an asset
 * with its own sub-fields) recurses naturally because each child mapping is
 * dispatched back through {@see \TDM\Influx\services\FieldsService}.
 */
class SubElementPopulator
{
    /**
     * @param array<mixed> $item       The remote item being processed.
     * @param array<mixed> $fieldInfo  The parent mapping config (the field
     *                                 whose value is the related element).
     */
    public function populate(
        ElementInterface $element,
        array $item,
        array $fieldInfo,
        Link $link,
    ): bool {
        $native = $fieldInfo['nativeFields'] ?? [];
        $custom = $fieldInfo['fields'] ?? [];
        if (empty($native) && empty($custom)) {
            return false;
        }

        $touched = $this->applyNative($element, $native, $item);
        $touched = $this->applyCustom($element, $custom, $item, $link) || $touched;

        if ($touched) {
            Craft::$app->getElements()->saveElement($element, false);
        }
        return $touched;
    }

    /**
     * @param array<string, mixed> $native
     */
    private function applyNative(ElementInterface $element, array $native, array $item): bool
    {
        $touched = false;
        foreach ($native as $handle => $sub) {
            if (!is_string($handle) || !is_array($sub)) {
                continue;
            }
            $value = $this->resolveSubValue($sub, $item);
            if ($value === null) {
                continue;
            }
            if ($element->hasAttribute($handle) || property_exists($element, $handle)) {
                $element->{$handle} = $value;
                $touched = true;
            }
        }
        return $touched;
    }

    /**
     * @param array<string, mixed> $custom
     */
    private function applyCustom(ElementInterface $element, array $custom, array $item, Link $link): bool
    {
        $touched = false;
        $fieldsRegistry = Influx::getInstance()->fields;

        foreach ($custom as $handle => $sub) {
            if (!is_string($handle) || !is_array($sub)) {
                continue;
            }
            $craftField = $element->getFieldLayout()?->getFieldByHandle($handle);
            if (!$craftField) {
                continue;
            }
            $strategy = $fieldsRegistry->forCraftField($craftField);
            $strategy->setContext($craftField, $handle, $sub, $item, $link, $element);
            $value = $strategy->parseField();
            if ($value === null) {
                continue;
            }
            $strategy->apply($element, $value);
            $touched = true;
        }
        return $touched;
    }

    private function resolveSubValue(array $sub, array $item): mixed
    {
        $node = $sub['node'] ?? null;
        $value = $node ? Hash::get($item, $node) : null;
        if ($value === null || $value === '') {
            $value = $sub['default'] ?? null;
        }
        return ($value === null || $value === '') ? null : $value;
    }
}
