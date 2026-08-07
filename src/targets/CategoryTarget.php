<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\db\CategoryQuery;
use craft\elements\db\ElementQueryInterface;
use craft\models\CategoryGroup;
use craft\models\FieldLayout;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\NativeAttributes;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;

/**
 * Target for craft\elements\Category.
 *
 * Recognized elementCriteria key — {@see CRITERIA_GROUP}, the handle of the
 * category group (required for new categories, since the group carries both the
 * field layout and the structure the category is placed in). This target OWNS
 * that key name: every reader goes through {@see Link::criterion()} with the
 * constant below.
 *
 * A category group IS the ownership boundary, so this target sweeps: every
 * category in the configured group is managed by the link, which is what makes
 * "everything the feed didn't mention" a safe set to act on.
 *
 * Category groups are structures, so `parent` is offered as a native — resolved
 * the same way {@see EntryTarget::parseAuthor()} resolves an author, through the
 * run's element-lookup cache and a configurable match strategy. Craft places the
 * category in its group's structure on save from {@see Category::setParentId()},
 * so nothing here touches the Structures service.
 */
class CategoryTarget extends AbstractElementTarget
{
    public const CRITERIA_GROUP = 'group';

    public static function elementType(): string
    {
        return Category::class;
    }

    /**
     * Categories are scoped by their group — the one dropdown the builder's
     * General tab renders for this element type.
     *
     * @return list<string>
     */
    public static function criteriaKeys(): array
    {
        return [self::CRITERIA_GROUP];
    }

    /** @return list<array> */
    public static function criteriaSchema(): array
    {
        $options = [self::criteriaPlaceholder()];

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $options[] = ['value' => $group->handle, 'label' => $group->name];
        }

        return SchemaBuilder::make()
            ->select([
                'handle'  => self::CRITERIA_GROUP,
                'label'   => Craft::t('app', 'Category Group'),
                'options' => $options,
            ])
            ->toArray();
    }

    public function criteriaLabel(Link $link): ?string
    {
        $handle = $link->criterion(self::CRITERIA_GROUP);

        if (! $handle) {
            return null;
        }

        return $this->groupByHandle($handle)?->name ?? $handle;
    }

    /**
     * Structural targeting: the element is a Category this link handles, inside
     * the link's configured group (the criterion only bites when set). Says
     * nothing about the match value — that gap is {@see claimsElement()}'s.
     *
     * The group is compared by ID rather than through {@see Category::getGroup()},
     * which THROWS for a category whose group is unset or since deleted. This
     * predicate runs on every element edit screen in the CP (the mapped-field
     * indicators ask it of whatever is being edited), so it has to be able to
     * answer "not mine" instead of breaking an unrelated page.
     */
    public function targetsElement(Link $link, ElementInterface $element): bool
    {
        if (! ($element instanceof Category)) {
            return false;
        }

        if (! $this->handles($link)) {
            return false;
        }

        $groupHandle = $link->criterion(self::CRITERIA_GROUP);

        return $groupHandle === null || $this->groupByHandle($groupHandle)?->id === $element->groupId;
    }

    /**
     * Categories partition by group, so a link's claim is the group it names —
     * or every group there is when it names none.
     *
     * @return list<string>
     */
    public function claimCells(Link $link): array
    {
        $group = $link->criterion(self::CRITERIA_GROUP);

        if ($group !== null) {
            return [$group];
        }

        $cells = [];

        foreach (Craft::$app->getCategories()->getAllGroups() as $candidate) {
            $cells[] = $candidate->handle;
        }

        return $cells;
    }

    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?Category
    {
        $matchAttr = $link->matchAttribute();

        if (! $matchAttr || $matchValue === null || $matchValue === '') {
            return null;
        }

        $query = Category::find()
            ->status(null)
            ->{$matchAttr}($matchValue);

        return $this->scopeToLink($query, $link, $siteId)->one();
    }

    /**
     * Candidate set for the missing-elements sweep: every category this link owns
     * ({@see scopeToLink()}, the same scoping {@see findByMatchValue()} uses),
     * minus the ids the run just saw. Null only when the link has no match
     * attribute — such a link can't sync, so there's nothing to sweep.
     *
     * A category with an EMPTY match value is a candidate too, for the reason
     * {@see EntryTarget::missingElementsQuery()} spells out: the group scope
     * already answers "is this ours", and no feed item can ever match a blank key.
     */
    public function missingElementsQuery(Link $link, array $seenIds, ?int $siteId): ?ElementQueryInterface
    {
        if (! $link->matchAttribute()) {
            return null;
        }

        $query = $this->scopeToLink(Category::find(), $link, $siteId);

        if ($seenIds !== []) {
            $query->id(array_merge(['not'], $seenIds));
        }

        return $query;
    }

    public function buildNew(Link $link, ?int $siteId = null): Category
    {
        $category = new Category();
        $category->groupId = $this->requireGroup($link)->id;

        if ($siteId) {
            $category->siteId = $siteId;
        }

        return $category;
    }

    /**
     * The category identifiers, from the one list a Categories RELATION field
     * offers too ({@see NativeAttributes::categoryMatchable()}) — including its
     * `id` lead, so the base's guarantee holds without merging into it.
     */
    public function matchableNativeAttributes(Link $link): array
    {
        return NativeAttributes::categoryMatchable();
    }

    /**
     * Custom fields come from the configured group's own field layout, so they
     * keep their category-editor grouping; an unresolvable group leaves the
     * natives alone.
     */
    public function getMappableFields(Link $link): array
    {
        return array_merge(
            $this->nativeFieldDefinitions()->toArray(),
            $this->customFieldDescriptors(
                $this->fieldLayout($link),
                Craft::t('influx', 'Content'),
            ),
        );
    }

    public function fieldLayout(Link $link): ?FieldLayout
    {
        return $this->group($link)?->getFieldLayout();
    }

    /**
     * The link's ownership scope as query criteria: its group (only when set) plus
     * the site scope — one site for a site-scoped run, otherwise one row per
     * canonical category across sites. THE definition of "which categories this
     * link owns", so {@see findByMatchValue()} and {@see missingElementsQuery()}
     * can't drift apart.
     */
    protected function scopeToLink(CategoryQuery $query, Link $link, ?int $siteId): CategoryQuery
    {
        if (($group = $link->criterion(self::CRITERIA_GROUP)) !== null) {
            $query->group($group);
        }

        if ($siteId) {
            $query->siteId($siteId);
        } else {
            $query->siteId('*')->unique();
        }

        return $query;
    }

    /**
     * Place the category under the category the feed names, resolved through the
     * configured match strategy (id / title / slug / custom field). An empty
     * value — or one matching nothing — moves it to the root of its group's
     * structure, since the feed is authoritative about the tree.
     *
     * A row naming ITSELF as its parent is ignored rather than allowed to fail the
     * save: feeds that carry a `parent` column routinely use the row's own key as
     * the "no parent" sentinel.
     *
     * Change detection compares parent ids — see {@see currentParentId()} for how
     * the "before" is read without paying for a query on a flat vocabulary.
     */
    protected function parseParent(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        /** @var Category $element */
        $before = $this->currentParentId($element);
        $newId = $this->resolveParentId($context, $element, $item, $mapping);

        $element->setParentId($newId);

        return $before !== $newId;
    }

    /**
     * The parent this category already has, or null when it has none.
     *
     * A brand-new category has none — and asking an id-less element would run an
     * ancestors query against a structure it isn't in yet. An existing one at level
     * 1 is at the root, which `level` answers outright; only a genuinely nested
     * category costs the query {@see Category::getParentId()} runs. Most category
     * groups are flat, so most syncs never pay it.
     */
    protected function currentParentId(Category $element): ?int
    {
        if (! $element->id || $element->level === 1) {
            return null;
        }

        return $element->getParentId();
    }

    /**
     * The parent category id for one item. A feed *node* value is matched via the
     * configured `match` strategy; the mapping's `default` is a category picked in
     * the CP through the element selector, so it's matched by id regardless — the
     * strategy applies to feed values, not to the picked default (the same split
     * {@see EntryTarget::resolveAuthorId()} documents).
     */
    protected function resolveParentId(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): ?int
    {
        $nodeValue = $mapping->rawValue($item);

        $id = null;

        if ($nodeValue !== null && $nodeValue !== '') {
            $id = $this->findCategory($context, (string) $mapping->option('match', 'id'), $nodeValue)?->id;
        } elseif ($mapping->useDefault && $mapping->default !== null && $mapping->default !== '') {
            $id = $this->findCategory($context, 'id', $mapping->default)?->id;
        }

        return $id === $element->id ? null : $id;
    }

    /**
     * Resolve a category by the given match strategy, memoized on the run's lookup
     * cache under the `parent` scope. A tree's feed repeats the same parent across
     * every one of its children, so caching collapses those to a single query.
     *
     * The lookup is scoped to the link's group: a parent outside it would be
     * placed in a structure the category doesn't belong to.
     */
    protected function findCategory(SyncContext $context, string $match, mixed $value): ?Category
    {
        $element = $context->lookups->remember(Category::class, $match, 'parent', (string) $value, function() use ($context, $match, $value) {
            $query = Category::find()->status(null);

            if (($group = $context->link->criterion(self::CRITERIA_GROUP)) !== null) {
                $query->group($group);
            }

            match ($match) {
                'id'    => $query->id((int) $value),
                'title' => $query->title($value),
                'slug'  => $query->slug($value),
                default => $query->$match($value),
            };

            return $query->one();
        });

        return $element instanceof Category ? $element : null;
    }

    /**
     * The Category-native mappable attributes. Title and slug are the two
     * writables a Categories relation offers too
     * ({@see NativeAttributes::categoryWritable()}) — one list, two surfaces —
     * and neither is hideable per group the way an entry type can hide them, so
     * there's nothing to gate. `enabled` rides the inherited
     * {@see AbstractElementTarget::parseEnabled()}, and `parent` is this element
     * type's own structural native.
     */
    protected function nativeFieldDefinitions(): MappingSchemaBuilder
    {
        return MappingSchemaBuilder::make()
            ->group(Craft::t('influx', 'Native'), function(MappingSchemaBuilder $group): void {
                foreach (NativeAttributes::categoryWritable() as $attribute) {
                    $group->text([
                        'handle' => $attribute['handle'],
                        'name'   => $attribute['label'],
                    ]);
                }

                $group
                    ->select([
                        'handle'  => 'enabled',
                        'name'    => Craft::t('app', 'Enabled'),
                        'options' => [
                            'true'  => Craft::t('app', 'Enabled'),
                            'false' => Craft::t('app', 'Disabled'),
                        ],
                    ])
                    ->element([
                        'handle'      => 'parent',
                        'name'        => Craft::t('app', 'Parent'),
                        'elementType' => Category::class,
                        'extras'      => fn(MappingSchemaBuilder $builder)      => $builder->matchBy(['options' => $this->parentMatchOptions()]),
                    ]);
            });
    }

    /**
     * Match-by options for the native parent dropdown: the shared category
     * identifiers, then any custom fields on the group's layout so a unique handle
     * like an external `importId` can match. Mirrors
     * {@see EntryTarget::authorMatchOptions()}.
     *
     * @return list<array{label: string, kind: string, options: list<array{value: string, label: string}>}>
     */
    protected function parentMatchOptions(): array
    {
        return [
            [
                'label'   => Craft::t('app', 'Category'),
                'kind'    => 'element',
                'options' => NativeAttributes::categoryMatchable(),
            ],
        ];
    }

    /**
     * Lenient group resolution for UI/read paths: an unset or unknown handle
     * yields null, so a half-configured link still reports its natives.
     */
    protected function group(Link $link): ?CategoryGroup
    {
        $handle = $link->criterion(self::CRITERIA_GROUP);

        return $handle ? $this->groupByHandle($handle) : null;
    }

    /**
     * Strict group resolution for the write path, naming the offending handle —
     * the same discipline {@see support\EntryTypeResolver::resolve()} applies to a
     * section.
     *
     * @throws InfluxException when the group criteria is missing or unknown.
     */
    protected function requireGroup(Link $link): CategoryGroup
    {
        $handle = $link->criterion(self::CRITERIA_GROUP);

        if (! $handle) {
            throw new InfluxException(
                "Link '{$link->handle}' must declare elementCriteria.group for Category targets.",
            );
        }

        $group = $this->groupByHandle($handle);

        if (! $group) {
            throw new InfluxException("Category group '{$handle}' does not exist.");
        }

        return $group;
    }

    /**
     * The one Craft lookup, isolated as a seam so the resolution above is
     * testable without a booted Craft.
     */
    protected function groupByHandle(string $handle): ?CategoryGroup
    {
        return Craft::$app->getCategories()->getGroupByHandle($handle);
    }
}
