<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\db\Table as CraftTable;
use craft\elements\Tag as CraftTagElement;
use craft\fields\Tags as CraftTagsField;
use craft\models\FieldLayout;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Relation strategy for the Tags field: `taggroup:UID` sources resolving
 * against the `taggroups` table, with {@see GroupScopedRelation} doing the group
 * scoping and creation.
 *
 * Tags are cheap and uncurated, so this flavour flips creation ON by default —
 * the one behavioural difference from {@see Categories}.
 */
class Tags extends GroupScopedRelation
{
    public static function craftFieldClass(): ?string
    {
        return CraftTagsField::class;
    }

    protected function elementType(): string
    {
        return CraftTagElement::class;
    }

    protected function sourcePrefix(): string
    {
        return 'taggroup:';
    }

    protected function groupTable(): string
    {
        return CraftTable::TAGGROUPS;
    }

    protected function groupFieldLayout(string $uid): ?FieldLayout
    {
        return Craft::$app->getTags()->getTagGroupByUid($uid)?->getFieldLayout();
    }

    /**
     * Auto-create when not found, in the field's configured group. Mirrors how
     * most Craft sites use Tags fields.
     */
    protected function shouldCreate(FieldContext $context): bool
    {
        return (bool) $context->mapping->option('create', true);
    }
}
