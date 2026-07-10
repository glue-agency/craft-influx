<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category as CraftCategoryElement;
use craft\elements\db\ElementQueryInterface;
use craft\fields\BaseRelationField;
use craft\fields\Categories as CraftCategoriesField;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Relation strategy for the Categories field. Categories are group-scoped
 * (`group:UID` sources, the `categorygroups` table): the field points at a
 * single category group, so lookups scope to that group's id and any created
 * category is placed in it.
 *
 * Creation stays opt-in (no {@see shouldCreate()} override) — categories are
 * usually curated, unlike {@see Tags}, which auto-creates. The group-scoping
 * hooks below mirror {@see Tags}; the two are kept as independent subclasses
 * of {@see Relation} rather than sharing a base — the shared surface is only a
 * few lines and Tags is on its way out of Craft.
 */
class Categories extends Relation
{
    public static function craftFieldClass(): ?string
    {
        return CraftCategoriesField::class;
    }

    protected function elementType(): string
    {
        return CraftCategoryElement::class;
    }

    protected function sourceFieldLayouts(BaseRelationField $field): iterable
    {
        $uid = $this->sourceUid($field->source ?? null, 'group:');

        if ($uid === null) {
            return;
        }

        $layout = Craft::$app->getCategories()->getGroupByUid($uid)?->getFieldLayout();

        if ($layout) {
            yield $layout;
        }
    }

    protected function scopeBySources(FieldContext $context, ElementQueryInterface $query): void
    {
        $groupId = $this->sourceGroupId($context->craftField?->source ?? null);

        if ($groupId !== null) {
            /** @phpstan-ignore-next-line — category queries expose groupId */
            $query->groupId($groupId);
        }
    }

    protected function createMissing(FieldContext $context, mixed $value): ?ElementInterface
    {
        $groupId = $this->sourceGroupId($context->craftField?->source ?? null);

        if ($groupId === null) {
            return null;
        }

        $category = new CraftCategoryElement();
        $category->groupId = $groupId;
        $category->title = (string) $value;

        return Craft::$app->getElements()->saveElement($category, true) ? $category : null;
    }

    /** Category-group id (this environment) from a `group:UID` source key, or null. */
    protected function sourceGroupId(mixed $source): ?int
    {
        return $this->sourceIdByUid($source, 'group:', '{{%categorygroups}}');
    }
}
