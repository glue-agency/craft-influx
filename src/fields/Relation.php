<?php

namespace TDM\Influx\fields;

use Cake\Utility\Hash;
use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\BaseRelationField;

/**
 * Shared base for relational fields: Entries, Users, Categories, Tags, ...
 *
 *   options.match: 'id' | 'title' | 'slug' | <native attr or unique field handle>
 *   nativeFields:  recursive map written back to the related element itself
 *   fields:        recursive map for the related element's custom fields
 *                  (handled by populateSubElement)
 *
 * Subclasses just declare the Craft field class they cover and (optionally)
 * override `createMissing()` to create elements when no match is found.
 *
 * Mirrors FeedMe's craft\feedme\fields\Entries split into a shared base so
 * Users/Categories/Tags don't have to repeat the lookup loop.
 */
abstract class Relation extends Field
{
    /**
     * Element class this relation field points at — Entry / User / Category /
     * Tag. Subclasses MUST override.
     */
    abstract protected function elementType(): string;

    public function parseField(): mixed
    {
        $raw = $this->fetchSimpleValue();
        if ($raw === null || $raw === '') {
            return null;
        }

        $match = $this->fieldInfo['options']['match'] ?? 'id';
        $values = is_array($raw) ? $raw : [$raw];

        $ids = [];
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $element = $this->findOne($match, $value);
            if (!$element && $this->shouldCreate()) {
                $element = $this->createMissing($value);
            }
            if ($element) {
                $ids[] = $element->id;
                $this->populateSubElement($element);
            }
        }

        return $ids ?: null;
    }

    public function apply(ElementInterface $element, mixed $value): bool
    {
        $element->setFieldValue($this->fieldHandle, $value);
        return true;
    }

    public function hasChanged(ElementInterface $element, mixed $incoming): bool
    {
        if (!is_array($incoming)) {
            return true;
        }
        try {
            $currentIds = $element->getFieldValue($this->fieldHandle)?->ids() ?? [];
        } catch (\Throwable) {
            return true;
        }
        sort($currentIds);
        $incoming = array_values($incoming);
        sort($incoming);
        return $currentIds !== $incoming;
    }

    protected function shouldCreate(): bool
    {
        return !empty($this->fieldInfo['options']['create']);
    }

    /**
     * Look up an element by the configured match strategy. Returns the first
     * hit (relation fields are unordered by default).
     */
    protected function findOne(string $match, mixed $value): ?ElementInterface
    {
        $class = $this->elementType();
        /** @var ElementQueryInterface $query */
        $query = $class::find()->status(null);

        match ($match) {
            'id'    => $query->id((int)$value),
            'title' => $query->title($value),
            'slug'  => $query->slug($value),
            default => $query->$match($value),
        };

        $this->scopeBySources($query);

        return $query->one();
    }

    /**
     * Constrain the lookup query to the sources configured on the Craft field
     * (sectionIds for Entries, groupIds for Users/Tags/Categories). Subclasses
     * may override when their sources don't map onto a single id list.
     */
    protected function scopeBySources(ElementQueryInterface $query): void
    {
        // Default: no-op. Concrete strategies that need source scoping
        // override this (e.g. Entries narrowing by sectionId).
    }

    /**
     * Create the element when no match was found and `options.create` is on.
     * Default: return null (no create). Override per subclass.
     */
    protected function createMissing(mixed $value): ?ElementInterface
    {
        return null;
    }

    /**
     * Apply any configured sub-mappings to the related element and save it.
     * Recursive: a sub-field can itself be a relation with sub-fields, since
     * each sub-mapping is dispatched through the same FieldsService.
     */
    protected function populateSubElement(ElementInterface $element): void
    {
        $custom = $this->fieldInfo['fields'] ?? [];
        $native = $this->fieldInfo['nativeFields'] ?? [];
        if (empty($custom) && empty($native)) {
            return;
        }

        $touched = false;

        // Native sub-attributes (e.g. asset.alt, entry.title).
        foreach ($native as $handle => $sub) {
            $value = $this->resolveSubValue($sub);
            if ($value === null) {
                continue;
            }
            if ($element->hasAttribute($handle) || property_exists($element, $handle)) {
                $element->$handle = $value;
                $touched = true;
            }
        }

        // Custom sub-fields. Recurse through FieldsService so a sub-Asset
        // with its own sub-alt mapping just works.
        $fields = \TDM\Influx\Influx::getInstance()->fields;
        foreach ($custom as $handle => $sub) {
            $craftField = $element->getFieldLayout()?->getFieldByHandle($handle);
            if (!$craftField) {
                continue;
            }
            $strategy = $fields->forCraftField($craftField);
            $strategy->setContext($craftField, $handle, $sub, $this->feedData, $this->link, $element);
            $value = $strategy->parseField();
            if ($value === null) {
                continue;
            }
            $strategy->apply($element, $value);
            $touched = true;
        }

        if ($touched) {
            Craft::$app->getElements()->saveElement($element, false);
        }
    }

    private function resolveSubValue(mixed $sub): mixed
    {
        if (!is_array($sub)) {
            return null;
        }
        $node = $sub['node'] ?? null;
        $value = $node ? Hash::get($this->feedData, $node) : null;
        if ($value === null || $value === '') {
            $value = $sub['default'] ?? null;
        }
        if ($value === null || $value === '') {
            return null;
        }
        return $value;
    }
}
