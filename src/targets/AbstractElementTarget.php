<?php

namespace TDM\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use TDM\Influx\models\FieldMapping;
use TDM\Influx\models\Link;
use TDM\Influx\sync\RemoteItem;

abstract class AbstractElementTarget implements ElementTargetInterface
{
    public function handles(Link $link): bool
    {
        return ltrim($link->elementType, '\\') === ltrim(static::elementType(), '\\');
    }

    /**
     * Default: delegate to the element class's own `displayName()`. Subclasses
     * override only when they need a label distinct from Craft's own.
     */
    public static function friendlyName(): string
    {
        $class = static::elementType();
        if (is_subclass_of($class, ElementInterface::class)) {
            return $class::displayName();
        }
        $parts = explode('\\', ltrim($class, '\\'));
        return end($parts) ?: $class;
    }

    /**
     * Default native-attribute apply: resolve the remote value (`node` then
     * `default`) and assign it via setAttribute, falling back to setFieldValue
     * for attrs Craft exposes that way. Subclasses dispatch to `parseFoo`
     * methods first to translate values that aren't directly settable (e.g.
     * Entry's `status` → `enabled`) — see the convention documented on
     * {@see ElementTargetInterface::applyNativeAttribute()}.
     *
     * Returns true when a write happened, so the sync engine can flag the
     * element as changed.
     */
    public function applyNativeAttribute(
        ElementInterface $element,
        string $handle,
        RemoteItem $item,
        FieldMapping $mapping,
    ): bool {
        $method = 'parse' . ucfirst($handle);
        if (method_exists($this, $method)) {
            return (bool)$this->{$method}($element, $item, $mapping);
        }

        $value = $mapping->resolve($item);
        if ($value === null) {
            return false;
        }

        if (in_array($handle, $element->attributes(), true) || property_exists($element, $handle)) {
            $element->{$handle} = $value;
        } else {
            $element->setFieldValue($handle, $value);
        }
        return true;
    }

    /**
     * Default: nothing is owned. Targets override when buildNew() already
     * assigned an attribute that would otherwise be re-applied by the
     * generic mapping loop.
     */
    public function ownsAttribute(Link $link, string $handle): bool
    {
        return false;
    }

    /**
     * Default: assign the match value as a native attribute when one exists,
     * otherwise treat it as a custom field. Works for every element type so
     * far; targets only need to override for non-standard match storage.
     */
    public function assignMatchValue(ElementInterface $element, Link $link, mixed $matchValue): void
    {
        $attr = $link->matchAttribute();
        if (!$attr) {
            return;
        }
        if (in_array($attr, $element->attributes(), true) || property_exists($element, $attr)) {
            $element->{$attr} = $matchValue;
        } else {
            $element->setFieldValue($attr, $matchValue);
        }
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
     * Default matchable natives: only `id` — the one identifier every
     * Craft element is guaranteed to have. Targets extend with whatever
     * their element type actually exposes (see {@see EntryTarget}).
     */
    public function matchableNativeAttributes(Link $link): array
    {
        return [
            ['value' => 'id', 'label' => Craft::t('influx', 'ID (id)')],
        ];
    }
}
