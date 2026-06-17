<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use DateTimeInterface;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\RemoteItem;
use Stringable;

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
     * methods first to translate values that aren't directly assignable (e.g.
     * coercing Entry's `enabled` to a bool) — see the convention documented
     * on {@see ElementTargetInterface::applyNativeAttribute()}.
     *
     * The caller ({@see \GlueAgency\Influx\sync\MappingApplier}) only invokes
     * this for an actively-mapped handle, so an empty resolved value here means
     * "actively mapped, now empty" — written through to clear the attribute
     * (the feed is authoritative). Returns whether the value actually changed
     * (generic before/after comparison for the default path; `parseFoo`
     * overrides own their own attribute-aware comparison).
     */
    public function applyNativeAttribute(
        ElementInterface $element,
        string $handle,
        RemoteItem $item,
        FieldMapping $mapping,
    ): bool {
        $method = 'parse' . ucfirst($handle);

        if (method_exists($this, $method)) {
            return (bool) $this->{$method}($element, $item, $mapping);
        }

        $value = $mapping->resolve($item);
        $isAttribute = in_array($handle, $element->attributes(), true) || property_exists($element, $handle);

        if ($isAttribute) {
            $before = $element->{$handle} ?? null;
            $element->{$handle} = $value;

            return $this->nativeValueChanged($before, $element->{$handle} ?? null);
        }

        $before = $element->getFieldValue($handle);
        $element->setFieldValue($handle, $value);

        return $this->nativeValueChanged($before, $element->getFieldValue($handle));
    }

    /**
     * Whether two native values differ, compared on a stable, type-aware
     * representation: a boolean false is a real value (not "empty"), dates
     * compare by timestamp, and related elements by id — so re-applying the
     * same author/date/flag isn't mistaken for a change.
     */
    protected function nativeValueChanged(mixed $before, mixed $after): bool
    {
        return $this->comparable($before) !== $this->comparable($after);
    }

    protected function comparable(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if ($value instanceof ElementInterface) {
            return (int) $value->id;
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            $str = (string) $value;

            return $str === '' ? null : $str;
        }

        return json_encode($value);
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

        if (! $attr) {
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
        Compat::deleteElementForSite($element, $siteId);

        return true;
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
