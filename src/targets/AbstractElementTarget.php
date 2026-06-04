<?php

namespace TDM\Influx\targets;

use Cake\Utility\Hash;
use Craft;
use craft\base\ElementInterface;
use TDM\Influx\models\Link;

abstract class AbstractElementTarget implements ElementTargetInterface
{
    public function handles(Link $link): bool
    {
        return ltrim($link->elementType, '\\') === ltrim(static::elementType(), '\\');
    }

    /**
     * Default native-attribute apply: resolve the feed value (`node` then
     * `default`) and assign it via setAttribute, falling back to setFieldValue
     * for attrs Craft exposes that way. Subclasses dispatch to `parseFoo`
     * methods first to translate values that aren't directly settable (e.g.
     * Entry's `status` → `enabled`).
     *
     * Returns true when a write happened, so the sync engine can flag the
     * element as changed.
     */
    public function applyNativeAttribute(
        ElementInterface $element,
        string $handle,
        array $item,
        array $config,
    ): bool {
        $method = 'parse' . ucfirst($handle);
        if (method_exists($this, $method)) {
            return (bool)$this->{$method}($element, $item, $config);
        }

        $value = $this->resolveValue($item, $config);
        if ($value === null) {
            return false;
        }

        if ($element->hasAttribute($handle) || property_exists($element, $handle)) {
            $element->{$handle} = $value;
        } else {
            $element->setFieldValue($handle, $value);
        }
        return true;
    }

    public function disable(ElementInterface $element): bool
    {
        $element->enabled = false;
        return Craft::$app->getElements()->saveElement($element, false);
    }

    public function delete(ElementInterface $element): bool
    {
        return Craft::$app->getElements()->deleteElement($element);
    }

    public function deleteForSite(ElementInterface $element, int $siteId): bool
    {
        return Craft::$app->getElements()->deleteElementForSite($element, $siteId);
    }

    public function getMappableFields(Link $link): array
    {
        return [];
    }

    /**
     * Read the feed value for a mapping config, falling back to `default`
     * when the node is missing or empty. Shared between native-attribute
     * handlers across targets.
     */
    protected function resolveValue(array $item, array $config): mixed
    {
        $node = $config['node'] ?? null;
        $value = $node ? Hash::get($item, $node) : null;
        if ($value === null || $value === '') {
            $value = $config['default'] ?? null;
        }
        if ($value === '' || $value === null) {
            return null;
        }
        return $value;
    }
}
