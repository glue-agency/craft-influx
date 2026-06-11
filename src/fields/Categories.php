<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\elements\Category as CategoryElement;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use GlueAgency\Influx\sync\FieldContext;

class Categories extends Relation
{
    public static function craftFieldClass(): ?string
    {
        return \craft\fields\Categories::class;
    }

    protected function elementType(): string
    {
        return CategoryElement::class;
    }

    protected function sourceFieldLayouts(\craft\fields\BaseRelationField $field): iterable
    {
        $source = $field->source ?? null;
        if (!is_string($source) || !str_starts_with($source, 'group:')) {
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
        if (!$context->craftField) {
            return;
        }
        $source = $context->craftField->source ?? null;
        if (!is_string($source) || !str_starts_with($source, 'group:')) {
            return;
        }
        [, $uid] = explode(':', $source);
        $id = Db::idByUid('{{%categorygroups}}', $uid);
        if ($id) {
            /** @phpstan-ignore-next-line */
            $query->groupId($id);
        }
    }
}
