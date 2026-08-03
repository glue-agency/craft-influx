<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\db\Table as CraftTable;
use craft\elements\Category as CraftCategoryElement;
use craft\fields\BaseRelationField;
use craft\fields\Categories as CraftCategoriesField;
use craft\models\FieldLayout;
use GlueAgency\Influx\schema\NativeAttributes;

/**
 * Relation strategy for the Categories field: `group:UID` sources resolving
 * against the `categorygroups` table, with {@see GroupScopedRelation} doing the
 * group scoping and creation.
 *
 * Creation stays opt-in (no {@see shouldCreate()} override) — categories are
 * usually curated, unlike {@see Tags}, which auto-creates.
 */
class Categories extends GroupScopedRelation
{
    public static function craftFieldClass(): ?string
    {
        return CraftCategoriesField::class;
    }

    public function childrenKind(): ?string
    {
        return 'categories';
    }

    protected function elementType(): string
    {
        return CraftCategoryElement::class;
    }

    protected function nativeMatchAttributes(BaseRelationField $field): array
    {
        return NativeAttributes::categoryMatchable();
    }

    protected function nativeWritableAttributes(BaseRelationField $field): array
    {
        return NativeAttributes::categoryWritable();
    }

    protected function sourcePrefix(): string
    {
        return 'group:';
    }

    protected function groupTable(): string
    {
        return CraftTable::CATEGORYGROUPS;
    }

    protected function groupFieldLayout(string $uid): ?FieldLayout
    {
        return Craft::$app->getCategories()->getGroupByUid($uid)?->getFieldLayout();
    }
}
