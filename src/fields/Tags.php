<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Tag as CraftTagElement;
use craft\fields\BaseRelationField;
use craft\fields\Tags as CraftTagsField;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Relation strategy for the Tags field. Tags are group-scoped
 * (`taggroup:UID` sources, the `taggroups` table): the field points at a
 * single tag group, so lookups scope to that group's id and created tags are
 * placed in it. Tags are cheap/uncurated, so creation defaults ON.
 *
 * Mirrors {@see Categories}' group-scoping hooks; the two are kept as
 * independent subclasses of {@see Relation} rather than sharing a base — the
 * shared surface is only a few lines and Tags is on its way out of Craft.
 */
class Tags extends Relation
{
    public static function craftFieldClass(): ?string
    {
        return CraftTagsField::class;
    }

    protected function elementType(): string
    {
        return CraftTagElement::class;
    }

    protected function sourceFieldLayouts(BaseRelationField $field): iterable
    {
        $uid = $this->sourceUid($field->source ?? null, 'taggroup:');

        if ($uid === null) {
            return;
        }

        $layout = Craft::$app->getTags()->getTagGroupByUid($uid)?->getFieldLayout();

        if ($layout) {
            yield $layout;
        }
    }

    protected function scopeBySources(FieldContext $context, ElementQueryInterface $query): void
    {
        $groupId = $this->sourceGroupId($context->craftField?->source ?? null);

        if ($groupId !== null) {
            /** @phpstan-ignore-next-line — tag queries expose groupId */
            $query->groupId($groupId);
        }
    }

    protected function createMissing(FieldContext $context, mixed $value): ?ElementInterface
    {
        $groupId = $this->sourceGroupId($context->craftField?->source ?? null);

        if ($groupId === null) {
            return null;
        }

        $tag = new CraftTagElement();
        $tag->groupId = $groupId;
        $tag->title = (string) $value;

        return Craft::$app->getElements()->saveElement($tag, true) ? $tag : null;
    }

    /**
     * Tags are cheap to create — auto-create when not found, in the field's
     * configured group. Mirrors how most Craft sites use Tags fields.
     */
    protected function shouldCreate(FieldContext $context): bool
    {
        return (bool) $context->mapping->option('create', true);
    }

    /** Tag-group id (this environment) from a `taggroup:UID` source key, or null. */
    protected function sourceGroupId(mixed $source): ?int
    {
        return $this->sourceIdByUid($source, 'taggroup:', '{{%taggroups}}');
    }
}
