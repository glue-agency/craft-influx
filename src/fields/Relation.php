<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\BaseRelationField;
use craft\helpers\Db;
use craft\models\FieldLayout;
use GlueAgency\Influx\helpers\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Shared base for relational fields: Entries, Users, Categories, Tags, ...
 *
 *   options.match: 'id' | 'title' | 'slug' | <native attr or unique field handle>
 *   nativeFields:  recursive map written back to the related element itself
 *   fields:        recursive map for the related element's custom fields
 *                  (persisted via RelationalField::persistSubElement)
 *
 * Subclasses just declare the Craft field class they cover and (optionally)
 * override `createMissing()` to create elements when no match is found.
 *
 * Mirrors FeedMe's craft\feedme\fields\Entries split into a shared base so
 * Users/Categories/Tags don't have to repeat the lookup loop. Deliberately
 * NOT mirrored from FeedMe: side effects (creating elements, saving sub
 * elements) are dry-run-gated via {@see FieldContext::$dryRun}.
 */
abstract class Relation extends RelationalField
{
    /**
     * Element class this relation field points at — Entry / User / Category /
     * Tag. Subclasses MUST override.
     */
    abstract protected function elementType(): string;

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
    protected function matchOptions(BaseRelationField $field): array
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
            if (! $layout instanceof FieldLayout) {
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
    protected function sourceFieldLayouts(BaseRelationField $field): iterable
    {
        return [];
    }

    public function schema(CraftFieldInterface $field): array
    {
        /** @var BaseRelationField $field */
        $nativeSubFields = $this->nativeSubFields($field);

        return SchemaBuilder::make()
            ->matchBy(['options' => $this->matchOptions($field)])
            ->createWhenMissing()
            ->when($nativeSubFields, fn(SchemaBuilder $builder) => $builder->elementSubFields([
                'label'     => Craft::t('influx', 'Sub-fields'),
                'subFields' => $nativeSubFields,
            ]))
            ->toArray();
    }

    /**
     * Native attributes (title / slug) the related element can receive values
     * for, rendered as an `elementSubFields` editor and applied via the
     * mapping's `nativeFields` channel
     * ({@see \GlueAgency\Influx\sync\MappingApplier::applyNativeSubField()}).
     *
     * Driven by the related element's own field layout(s): each is offered
     * only when a source layout actually includes it — entry types can
     * auto-generate the title (titleFormat) or hide the slug, category groups
     * vary too. The union across sources is offered; an unsupported attr that
     * slips through is inert at apply time anyway.
     *
     * @return list<array>
     */
    protected function nativeSubFields(BaseRelationField $field): array
    {
        $builder = SchemaBuilder::make();
        $seen = [];

        foreach ($this->sourceFieldLayouts($field) as $layout) {
            if (! $layout instanceof FieldLayout) {
                continue;
            }

            // Keyed by handle so a relation spanning several source layouts
            // (multiple entry types / category groups) contributes each native
            // field at most once — the first layout that includes it wins.
            if (! isset($seen['title']) && $layout->isFieldIncluded('title')) {
                $seen['title'] = true;
                $builder->text(['handle' => 'title', 'label' => $layout->getField('title')->label() ?: Craft::t('app', 'Title')]);
            }

            if (! isset($seen['slug']) && $layout->isFieldIncluded('slug')) {
                $seen['slug'] = true;
                $builder->text(['handle' => 'slug', 'label' => Craft::t('app', 'Slug')]);
            }
        }

        return $builder->toArray();
    }

    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        // resolve() already normalises empty to null; empty entries within a
        // list value are dropped by referenceValues().
        if ($raw === null) {
            return null;
        }

        $match = (string) $context->mapping->option('match', 'id');

        $ids = [];

        foreach ($this->referenceValues($raw) as $value) {
            $element = $this->lookup($context, $match, $value);

            if (! $element && ! $context->dryRun && $this->shouldCreate($context)) {
                $element = $this->createMissing($context, $value);

                // Flip the cached miss to a hit: without this, every later item
                // carrying the same value would re-create the element (dupes).
                $context->lookups?->put($this->elementType(), $match, $this->lookupScope($context), $value, $element);
            }

            if ($element) {
                $ids[] = $element->id;
                $this->persistSubElement($context, $element);
            }
        }

        return $ids ?: null;
    }

    /**
     * findOne(), memoized on the run's element-lookup cache. Feeds repeat the
     * same relation values across many items; the cache collapses those to a
     * single query (and caches misses too). Falls straight through to an
     * uncached {@see findOne()} when no cache is present (contexts built
     * directly, e.g. in tests).
     */
    protected function lookup(FieldContext $context, string $match, mixed $value): ?ElementInterface
    {
        if ($context->lookups === null) {
            return $this->findOne($context, $match, $value);
        }

        return $context->lookups->remember(
            $this->elementType(),
            $match,
            $this->lookupScope($context),
            $value,
            fn(): ?ElementInterface => $this->findOne($context, $match, $value),
        );
    }

    /**
     * Cache scope for this field's lookups: the Craft field's id, plus the
     * lookup site when one applies. The field id matters because
     * {@see scopeBySources()} narrows by the field's own sources, so the same
     * value can resolve to different elements per field; the site matters
     * because {@see scopesBySite()} scopes localized lookups per-site, so a
     * value must not reuse another site's cached hit. The site suffix is
     * omitted when there's none (a non-site-scoped relation like Users, or no
     * siteId), leaving the bare field id — so native/test contexts still key
     * consistently.
     */
    protected function lookupScope(FieldContext $context): string
    {
        $scope = (string) ($context->craftField->id ?? '');
        $site = $this->scopesBySite() ? (string) ($context->element->siteId ?? '') : '';

        return $site !== '' ? "{$scope}:{$site}" : $scope;
    }

    protected function shouldCreate(FieldContext $context): bool
    {
        return ! empty($context->mapping->option('create'));
    }

    /**
     * Resolve a Craft field source key to the matching row id in THIS
     * environment's given table. Source keys carry a Project-Config UID
     * (`section:UID`, `group:UID`, ...) that's stable across environments;
     * the row id it maps to is not, so it has to be looked up per environment.
     * Returns null when the key doesn't match the prefix or no row carries
     * that UID (an unknown/stale source key resolves to nothing rather than
     * erroring).
     */
    protected function sourceIdByUid(mixed $source, string $prefix, string $table): ?int
    {
        $uid = $this->sourceUid($source, $prefix);

        if ($uid === null) {
            return null;
        }

        $id = Db::idByUid($table, $uid);

        return $id ? (int) $id : null;
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
            'id'    => $query->id((int) $value),
            'title' => $query->title($value),
            'slug'  => $query->slug($value),
            default => $query->$match($value),
        };

        if ($this->scopesBySite()) {
            // Match within the synced element's site — slug/title/localized
            // fields are per-site, so Craft's ambient "current site" (the
            // primary site in a queue/console run) would mis-match or miss.
            $siteId = $context->element->siteId ?? null;
            $query->siteId($siteId ?: '*');

            if (! $siteId) {
                $query->unique();
            }
        }

        $this->scopeBySources($context, $query);

        return $query->one();
    }

    /**
     * Whether lookups are constrained to the synced element's site. True for
     * localized relations (Entries / Categories / Tags); overridden to false
     * by non-localized ones (Users), whose rows are global.
     */
    protected function scopesBySite(): bool
    {
        return true;
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
}
