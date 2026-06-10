<?php

namespace TDM\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\models\FieldLayout;

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

    public function fieldMeta(\craft\base\FieldInterface $field): array
    {
        /** @var \craft\fields\BaseRelationField $field */
        return [
            'kind'         => 'relation',
            'elementType'  => $field::elementType(),
            'matchOptions' => $this->matchOptions($field),
            'labels'       => self::extrasLabels() + self::commonExtrasLabels(),
        ];
    }

    public function hasMappingExtras(): bool
    {
        return true;
    }

    /**
     * UI strings rendered inside the relation extras block. Static so the
     * native `author` mapping on {@see \TDM\Influx\targets\EntryTarget} can
     * reuse the exact same set without building a field instance.
     *
     * @return array<string, string>
     */
    public static function extrasLabels(): array
    {
        return [
            'matchBy'        => Craft::t('influx', 'Match by'),
            'createMissing'  => Craft::t('influx', 'Create when not found'),
        ];
    }

    /**
     * Options offered in the CP "Match by" dropdown — native identifiers
     * (id / slug / title) plus every custom-field handle defined on the
     * related element type's configured sources. The runtime in
     * {@see findOne()} already routes unknown match keys through the
     * dynamic query method, so this only widens the *UI surface*, not the
     * underlying matching logic.
     *
     * Shape is grouped — the Vue dropdown renders each group as an
     * `<optgroup>` ("Native" first, then "Fields" when there are custom
     * fields to surface). Empty groups are omitted so a relation field
     * pointing at an element type without custom fields doesn't render an
     * empty heading.
     *
     * @return list<array{label: string, options: list<array{value: string, label: string}>}>
     */
    protected function matchOptions(\craft\fields\BaseRelationField $field): array
    {
        $groups = [
            [
                'label'   => Craft::t('influx', 'Native'),
                'options' => [
                    ['value' => 'id',    'label' => Craft::t('influx', 'Element ID')],
                    ['value' => 'slug',  'label' => Craft::t('influx', 'Slug')],
                    ['value' => 'title', 'label' => Craft::t('influx', 'Title')],
                ],
            ],
        ];

        $customFields = [];
        $seen = ['id' => true, 'slug' => true, 'title' => true];
        foreach ($this->sourceFieldLayouts($field) as $layout) {
            if (!$layout instanceof FieldLayout) {
                continue;
            }
            foreach ($layout->getCustomFields() as $customField) {
                $handle = $customField->handle;
                if (isset($seen[$handle])) {
                    continue;
                }
                $seen[$handle] = true;
                $customFields[] = [
                    'value' => $handle,
                    'label' => $customField->name . ' (' . $handle . ')',
                ];
            }
        }

        if ($customFields) {
            $groups[] = [
                'label'   => Craft::t('influx', 'Fields'),
                'options' => $customFields,
            ];
        }

        return $groups;
    }

    /**
     * Field layouts of the elements this relation field points at, resolved
     * from the field's configured sources. Subclasses know how to translate
     * source keys (`section:UID`, `group:UID`, ...) into the right layouts
     * and override accordingly; the base returns nothing so unknown
     * relation flavors still build a sensible (built-ins-only) matchOptions.
     *
     * @return iterable<FieldLayout|null>
     */
    protected function sourceFieldLayouts(\craft\fields\BaseRelationField $field): iterable
    {
        return [];
    }

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
            if (!$element && $this->shouldCreate() && !$this->dryRun) {
                $element = $this->createMissing($value);
            }
            if ($element) {
                $ids[] = $element->id;
                if (!$this->dryRun) {
                    $this->populateSubElement($element);
                }
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
     * Inherited by every relation strategy ({@see \TDM\Influx\fields\Entries},
     * {@see \TDM\Influx\fields\Categories}, {@see \TDM\Influx\fields\Tags},
     * {@see \TDM\Influx\fields\Users}) — they all get sub-mapping support
     * "for free" through this base.
     *
     * Recursive: a sub-field can itself be a relation with sub-fields, since
     * each sub-mapping is dispatched through the same FieldsService.
     */
    protected function populateSubElement(ElementInterface $element): void
    {
        (new SubElementPopulator())->populate($element, $this->item, $this->fieldInfo, $this->link);
    }
}
