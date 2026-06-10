<?php

namespace TDM\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Tag as TagElement;
use craft\helpers\Db;
use TDM\Influx\sync\FieldContext;

class Tags extends Relation
{
    public static function craftFieldClass(): ?string
    {
        return \craft\fields\Tags::class;
    }

    protected function elementType(): string
    {
        return TagElement::class;
    }

    protected function sourceFieldLayouts(\craft\fields\BaseRelationField $field): iterable
    {
        $source = $field->source ?? null;
        if (!is_string($source) || !str_starts_with($source, 'taggroup:')) {
            return;
        }
        [, $uid] = explode(':', $source);
        $group = Craft::$app->getTags()->getTagGroupByUid($uid);
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
        if (!is_string($source) || !str_starts_with($source, 'taggroup:')) {
            return;
        }
        [, $uid] = explode(':', $source);
        $id = Db::idByUid('{{%taggroups}}', $uid);
        if ($id) {
            /** @phpstan-ignore-next-line */
            $query->groupId($id);
        }
    }

    /**
     * Tags are cheap to create — auto-create when not found, in the field's
     * configured group. Mirrors how most Craft sites use Tags fields.
     */
    protected function shouldCreate(FieldContext $context): bool
    {
        return (bool)$context->mapping->option('create', true);
    }

    protected function createMissing(FieldContext $context, mixed $value): ?ElementInterface
    {
        if (!$context->craftField) {
            return null;
        }
        $source = $context->craftField->source ?? null;
        if (!is_string($source) || !str_starts_with($source, 'taggroup:')) {
            return null;
        }
        [, $uid] = explode(':', $source);
        $groupId = Db::idByUid('{{%taggroups}}', $uid);
        if (!$groupId) {
            return null;
        }

        $tag = new TagElement();
        $tag->groupId = $groupId;
        $tag->title = (string)$value;
        if (!Craft::$app->getElements()->saveElement($tag, true)) {
            return null;
        }
        return $tag;
    }
}
