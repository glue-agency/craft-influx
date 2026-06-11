<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry as EntryElement;
use craft\helpers\Db;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Relation strategy for the Entries field.
 *
 * Extras (under options):
 *   match:  'id' | 'title' | 'slug' | <any unique attr / field handle>
 *   create: bool        (create a new Entry when no match is found)
 *   group:  { sectionId, typeId }  (where to create — required when create=true)
 */
class Entries extends Relation
{
    public static function craftFieldClass(): ?string
    {
        return \craft\fields\Entries::class;
    }

    protected function elementType(): string
    {
        return EntryElement::class;
    }

    protected function sourceFieldLayouts(\craft\fields\BaseRelationField $field): iterable
    {
        $sources = $field->sources ?? '*';
        $entriesService = Craft::$app->getEntries();

        $sections = [];
        if ($sources === '*' || !is_array($sources)) {
            $sections = $entriesService->getAllSections();
        } else {
            foreach ($sources as $source) {
                if (!is_string($source) || !str_starts_with($source, 'section:')) {
                    continue;
                }
                [, $uid] = explode(':', $source);
                $section = $entriesService->getSectionByUid($uid);
                if ($section) {
                    $sections[] = $section;
                }
            }
        }

        foreach ($sections as $section) {
            foreach ($section->getEntryTypes() as $type) {
                yield $type->getFieldLayout();
            }
        }
    }

    protected function scopeBySources(FieldContext $context, ElementQueryInterface $query): void
    {
        // Constrain by the Craft field's configured sources (section UIDs).
        if (!$context->craftField) {
            return;
        }
        $sources = $context->craftField->sources ?? '*';
        if ($sources === '*' || !is_array($sources)) {
            return;
        }

        $sectionIds = [];
        foreach ($sources as $source) {
            if (str_starts_with($source, 'section:')) {
                [, $uid] = explode(':', $source);
                $id = Db::idByUid('{{%sections}}', $uid);
                if ($id) {
                    $sectionIds[] = $id;
                }
            }
        }
        if (!empty($sectionIds)) {
            /** @phpstan-ignore-next-line — Entries query exposes sectionId */
            $query->sectionId($sectionIds);
        }
    }

    protected function createMissing(FieldContext $context, mixed $value): ?ElementInterface
    {
        $sectionId = $context->mapping->option('group.sectionId');
        $typeId = $context->mapping->option('group.typeId');
        if (!$sectionId || !$typeId) {
            // Without an explicit target we'd be guessing — bail rather than
            // dropping the entry into the first section we find.
            return null;
        }

        $entry = new EntryElement();
        $entry->sectionId = (int)$sectionId;
        $entry->typeId = (int)$typeId;
        $entry->title = (string)$value;

        if (!Craft::$app->getElements()->saveElement($entry, true)) {
            return null;
        }
        return $entry;
    }
}
