<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category as CraftCategoryElement;
use craft\elements\db\ElementQueryInterface;
use craft\fields\BaseRelationField;
use craft\fields\Categories as CraftCategoriesField;
use craft\helpers\Db;
use GlueAgency\Influx\sync\FieldContext;

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
        $source = $field->source ?? null;

        if (! is_string($source) || ! str_starts_with($source, 'group:')) {
            return;
        }
        [, $uid] = explode(':', $source);
        $group = Craft::$app->getCategories()->getGroupByUid($uid);

        if ($group) {
            yield $group->getFieldLayout();
        }
    }

    protected function scopeBySources(FieldContext $context, ElementQueryInterface $query): void
    {
        if (! $context->craftField) {
            return;
        }
        $source = $context->craftField->source ?? null;

        if (! is_string($source) || ! str_starts_with($source, 'group:')) {
            return;
        }
        [, $uid] = explode(':', $source);
        $id = Db::idByUid('{{%categorygroups}}', $uid);

        if ($id) {
            /** @phpstan-ignore-next-line */
            $query->groupId($id);
        }
    }

    /**
     * Create the category in the field's configured group when the extras'
     * "Create when not found" toggle is on. Mirrors {@see Tags} but without
     * its auto-create default — categories are usually curated, so creation
     * stays opt-in.
     */
    protected function createMissing(FieldContext $context, mixed $value): ?ElementInterface
    {
        if (! $context->craftField) {
            return null;
        }
        $source = $context->craftField->source ?? null;

        if (! is_string($source) || ! str_starts_with($source, 'group:')) {
            return null;
        }
        [, $uid] = explode(':', $source);
        $groupId = Db::idByUid('{{%categorygroups}}', $uid);

        if (! $groupId) {
            return null;
        }

        $category = new CraftCategoryElement();
        $category->groupId = $groupId;
        $category->title = (string) $value;

        if (! Craft::$app->getElements()->saveElement($category, true)) {
            return null;
        }

        return $category;
    }
}
