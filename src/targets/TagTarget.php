<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\TagQuery;
use craft\elements\Tag;
use craft\models\FieldLayout;
use craft\models\TagGroup;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\NativeAttributes;
use GlueAgency\Influx\schema\SchemaBuilder;

/**
 * Target for craft\elements\Tag.
 *
 * Recognized elementCriteria key — {@see CRITERIA_GROUP}, the handle of the tag
 * group (required for new tags, since the group carries the field layout). This
 * target OWNS that key name.
 *
 * The thinnest of the element types: a tag is a title and whatever custom fields
 * its group declares. No slug — Craft derives one from the title on save and its
 * own editor never shows the field, which is why
 * {@see NativeAttributes::tagWritable()} omits it and this target doesn't offer a
 * row that wouldn't stick. No parent either: tag groups aren't structures.
 *
 * A tag group IS the ownership boundary, so this target sweeps.
 */
class TagTarget extends AbstractElementTarget
{
    public const CRITERIA_GROUP = 'group';

    public static function elementType(): string
    {
        return Tag::class;
    }

    /** @return list<string> */
    public static function criteriaKeys(): array
    {
        return [self::CRITERIA_GROUP];
    }

    /** @return list<array> */
    public static function criteriaSchema(): array
    {
        $options = [self::criteriaPlaceholder()];

        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $options[] = ['value' => $group->handle, 'label' => $group->name];
        }

        return SchemaBuilder::make()
            ->select([
                'handle'  => self::CRITERIA_GROUP,
                'label'   => Craft::t('app', 'Tag Group'),
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
     * The group is compared by ID rather than through {@see Tag::getGroup()}, which
     * throws for a tag whose group is unset or since deleted — see
     * {@see CategoryTarget::targetsElement()} for why this predicate must never
     * throw.
     */
    public function targetsElement(Link $link, ElementInterface $element): bool
    {
        if (! ($element instanceof Tag)) {
            return false;
        }

        if (! $this->handles($link)) {
            return false;
        }

        $groupHandle = $link->criterion(self::CRITERIA_GROUP);

        return $groupHandle === null || $this->groupByHandle($groupHandle)?->id === $element->groupId;
    }

    /**
     * Tags partition by group — the group the link names, or every group there is
     * when it names none.
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

        foreach (Craft::$app->getTags()->getAllTagGroups() as $candidate) {
            $cells[] = $candidate->handle;
        }

        return $cells;
    }

    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?Tag
    {
        $matchAttr = $link->matchAttribute();

        if (! $matchAttr || $matchValue === null || $matchValue === '') {
            return null;
        }

        $query = Tag::find()
            ->status(null)
            ->{$matchAttr}($matchValue);

        return $this->scopeToLink($query, $link, $siteId)->one();
    }

    /**
     * Candidate set for the missing-elements sweep: every tag this link owns,
     * minus the ids the run just saw. See
     * {@see EntryTarget::missingElementsQuery()} for why a blank match value is a
     * candidate rather than an exclusion.
     */
    public function missingElementsQuery(Link $link, array $seenIds, ?int $siteId): ?ElementQueryInterface
    {
        if (! $link->matchAttribute()) {
            return null;
        }

        $query = $this->scopeToLink(Tag::find(), $link, $siteId);

        if ($seenIds !== []) {
            $query->id(array_merge(['not'], $seenIds));
        }

        return $query;
    }

    public function buildNew(Link $link, ?int $siteId = null): Tag
    {
        $tag = new Tag();
        $tag->groupId = $this->requireGroup($link)->id;

        if ($siteId) {
            $tag->siteId = $siteId;
        }

        return $tag;
    }

    /**
     * A tag is identified by its title alone, from the one list a Tags RELATION
     * field offers too ({@see NativeAttributes::tagMatchable()}).
     */
    public function matchableNativeAttributes(Link $link): array
    {
        return NativeAttributes::tagMatchable();
    }

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
     * THE definition of "which tags this link owns" — its group (only when set)
     * plus the site scope — shared by {@see findByMatchValue()} and
     * {@see missingElementsQuery()} so the two can't drift apart.
     */
    protected function scopeToLink(TagQuery $query, Link $link, ?int $siteId): TagQuery
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
     * The Tag-native mappable attributes: the title a tag IS, plus the `enabled`
     * flag every element type carries (written by the inherited
     * {@see AbstractElementTarget::parseEnabled()}).
     */
    protected function nativeFieldDefinitions(): MappingSchemaBuilder
    {
        return MappingSchemaBuilder::make()
            ->group(Craft::t('influx', 'Native'), function(MappingSchemaBuilder $group): void {
                foreach (NativeAttributes::tagWritable() as $attribute) {
                    $group->text([
                        'handle' => $attribute['handle'],
                        'name'   => $attribute['label'],
                    ]);
                }

                $group->select([
                    'handle'  => 'enabled',
                    'name'    => Craft::t('app', 'Enabled'),
                    'options' => [
                        'true'  => Craft::t('app', 'Enabled'),
                        'false' => Craft::t('app', 'Disabled'),
                    ],
                ]);
            });
    }

    /**
     * Lenient group resolution for UI/read paths — an unset or unknown handle
     * yields null, so a half-configured link still reports its natives.
     */
    protected function group(Link $link): ?TagGroup
    {
        $handle = $link->criterion(self::CRITERIA_GROUP);

        return $handle ? $this->groupByHandle($handle) : null;
    }

    /**
     * Strict group resolution for the write path, naming the offending handle.
     *
     * @throws InfluxException when the group criteria is missing or unknown.
     */
    protected function requireGroup(Link $link): TagGroup
    {
        $handle = $link->criterion(self::CRITERIA_GROUP);

        if (! $handle) {
            throw new InfluxException(
                "Link '{$link->handle}' must declare elementCriteria.group for Tag targets.",
            );
        }

        $group = $this->groupByHandle($handle);

        if (! $group) {
            throw new InfluxException("Tag group '{$handle}' does not exist.");
        }

        return $group;
    }

    /**
     * The one Craft lookup, isolated as a seam so the resolution above is
     * testable without a booted Craft.
     */
    protected function groupByHandle(string $handle): ?TagGroup
    {
        return Craft::$app->getTags()->getTagGroupByHandle($handle);
    }
}
