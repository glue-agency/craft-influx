<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\models\FieldLayout;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\SubElementApplier;

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
 * Users/Categories/Tags don't have to repeat the lookup loop. Deliberately
 * NOT mirrored from FeedMe: side effects (creating elements, saving sub
 * elements) are dry-run-gated via {@see FieldContext::$dryRun}.
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

    /**
     * UI strings rendered inside the relation extras block. Static so the
     * native `author` mapping on {@see \GlueAgency\Influx\targets\EntryTarget} can
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
     * Shape is grouped — the Vue dropdown renders each group with a heading
     * (the related element type's display name first — "Entry", "User",
     * "Category", ... — then "Fields" when there are custom fields to
     * surface). Empty groups are omitted so a relation field pointing at an
     * element type without custom fields doesn't render an empty heading.
     *
     * @return list<array{label: string, kind: string, options: list<array{value: string, label: string}>}>
     */
    protected function matchOptions(\craft\fields\BaseRelationField $field): array
    {
        $elementType = $this->elementType();
        $nativeLabel = is_subclass_of($elementType, ElementInterface::class)
            ? $elementType::displayName()
            : Craft::t('influx', 'Native');

        $groups = [
            [
                'label'   => $nativeLabel,
                'kind'    => 'element',
                'options' => [
                    ['value' => 'id',    'label' => Craft::t('influx', 'ID (id)')],
                    ['value' => 'slug',  'label' => Craft::t('influx', 'Slug (slug)')],
                    ['value' => 'title', 'label' => Craft::t('influx', 'Title (title)')],
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
                'kind'    => 'fields',
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

    public function defineExtrasSchema(\craft\base\FieldInterface $field): array
    {
        /** @var \craft\fields\BaseRelationField $field */
        return [
            \GlueAgency\Influx\helpers\BuilderSchema::select(
                'match',
                Craft::t('influx', 'Match by'),
                $this->matchOptions($field),
                ['default' => 'id'],
            ),
            \GlueAgency\Influx\helpers\BuilderSchema::lightswitch(
                'create',
                Craft::t('influx', 'Create when not found'),
            ),
        ];
    }

    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);
        if ($raw === null || $raw === '') {
            return null;
        }

        $match = (string)$context->mapping->option('match', 'id');
        $values = is_array($raw) ? $raw : [$raw];

        $ids = [];
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $element = $this->findOne($context, $match, $value);
            if (!$element && !$context->dryRun && $this->shouldCreate($context)) {
                $element = $this->createMissing($context, $value);
            }
            if ($element) {
                $ids[] = $element->id;
                $this->populateSubElement($context, $element);
            }
        }

        return $ids ?: null;
    }

    public function hasChanged(FieldContext $context, mixed $incoming): bool
    {
        if (!is_array($incoming)) {
            return true;
        }
        try {
            $currentIds = $context->element->getFieldValue($context->handle)?->ids() ?? [];
        } catch (\Throwable) {
            return true;
        }
        sort($currentIds);
        $incoming = array_values($incoming);
        sort($incoming);
        return $currentIds !== $incoming;
    }

    protected function shouldCreate(FieldContext $context): bool
    {
        return !empty($context->mapping->option('create'));
    }

    /**
     * Look up an element by the configured match strategy. Returns the first
     * hit (relation fields are unordered by default).
     */
    protected function findOne(FieldContext $context, string $match, mixed $value): ?ElementInterface
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

        $this->scopeBySources($context, $query);

        return $query->one();
    }

    /**
     * Constrain the lookup query to the sources configured on the Craft field
     * (sectionIds for Entries, groupIds for Users/Tags/Categories). Subclasses
     * may override when their sources don't map onto a single id list.
     */
    protected function scopeBySources(FieldContext $context, ElementQueryInterface $query): void
    {
        // Default: no-op. Concrete strategies that need source scoping
        // override this (e.g. Entries narrowing by sectionId).
    }

    /**
     * Create the element when no match was found and `options.create` is on.
     * Never called under dry-run. Default: return null (no create). Override
     * per subclass.
     */
    protected function createMissing(FieldContext $context, mixed $value): ?ElementInterface
    {
        return null;
    }

    /**
     * Apply any configured sub-mappings to the related element and persist it
     * when something changed. Inherited by every relation strategy
     * ({@see Entries}, {@see Categories}, {@see Tags}, {@see Users}) — they
     * all get sub-mapping support "for free" through this base.
     *
     * Skipped entirely under dry-run: the related element is a real, saved
     * element that the debug inspector must not mutate. The walk itself
     * ({@see SubElementApplier}) never saves; persistence is decided here.
     */
    protected function populateSubElement(FieldContext $context, ElementInterface $element): void
    {
        if ($context->dryRun) {
            return;
        }
        if ((new SubElementApplier())->apply($element, $context)) {
            Craft::$app->getElements()->saveElement($element, false);
        }
    }
}
