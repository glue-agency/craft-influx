<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Tag as CraftTagElement;
use craft\fields\Tags as CraftTagsField;
use craft\models\FieldLayout;
use GlueAgency\Influx\sync\FieldContext;

class Tags extends GroupRelation
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
        return '{{%taggroups}}';
    }

    protected function groupLayout(string $uid): ?FieldLayout
    {
        return Craft::$app->getTags()->getTagGroupByUid($uid)?->getFieldLayout();
    }

    protected function newGroupElement(int $groupId, string $title): ElementInterface
    {
        $tag = new CraftTagElement();
        $tag->groupId = $groupId;
        $tag->title = $title;

        return $tag;
    }

    /**
     * Tags are cheap to create — auto-create when not found, in the field's
     * configured group. Mirrors how most Craft sites use Tags fields.
     */
    protected function shouldCreate(FieldContext $context): bool
    {
        return (bool) $context->mapping->option('create', true);
    }
}
