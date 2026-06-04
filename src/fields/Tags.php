<?php

namespace TDM\Influx\fields;

use Craft;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Tag as TagElement;
use craft\helpers\Db;

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

    protected function scopeBySources(ElementQueryInterface $query): void
    {
        if (!$this->craftField) {
            return;
        }
        $source = $this->craftField->source ?? null;
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
    protected function shouldCreate(): bool
    {
        return $this->fieldInfo['options']['create'] ?? true;
    }

    protected function createMissing(mixed $value): ?TagElement
    {
        if (!$this->craftField) {
            return null;
        }
        $source = $this->craftField->source ?? null;
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
