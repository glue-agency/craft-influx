<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category as CraftCategoryElement;
use craft\fields\Categories as CraftCategoriesField;
use craft\models\FieldLayout;

/**
 * Relation strategy for the Categories field. Creation stays opt-in (no
 * {@see shouldCreate()} override) — categories are usually curated, unlike
 * {@see Tags}, which auto-creates.
 */
class Categories extends GroupRelation
{
    public static function craftFieldClass(): ?string
    {
        return CraftCategoriesField::class;
    }

    protected function elementType(): string
    {
        return CraftCategoryElement::class;
    }

    protected function sourcePrefix(): string
    {
        return 'group:';
    }

    protected function groupTable(): string
    {
        return '{{%categorygroups}}';
    }

    protected function groupLayout(string $uid): ?FieldLayout
    {
        return Craft::$app->getCategories()->getGroupByUid($uid)?->getFieldLayout();
    }

    protected function newGroupElement(int $groupId, string $title): ElementInterface
    {
        $category = new CraftCategoryElement();
        $category->groupId = $groupId;
        $category->title = $title;

        return $category;
    }
}
