<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\db\Table as CraftTable;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry as CraftEntryElement;
use craft\fields\BaseRelationField;
use craft\fields\Entries as CraftEntriesField;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Relation strategy for the Entries field.
 *
 * Extras (under options):
 *   match:  'id' | 'title' | 'slug' | <any unique attr / field handle>
 *   create: bool        (create a new Entry when no match is found)
 *   group:  { section, type }  (where to create — required when create=true)
 *
 * `group.section` / `group.type` are handles: section and entry-type ids
 * differ per environment (they're not part of Project Config), so the
 * stored config must carry the stable identifier and resolve it at sync
 * time. (The Feed Me converter rewrites Feed Me's raw ids to handles at
 * conversion time, so ids never reach this config.)
 */
class Entries extends Relation
{
    public static function craftFieldClass(): ?string
    {
        return CraftEntriesField::class;
    }

    protected function elementType(): string
    {
        return CraftEntryElement::class;
    }

    protected function sourceFieldLayouts(BaseRelationField $field): iterable
    {
        $sources = $field->sources ?? '*';

        $sections = [];

        if ($sources === '*' || ! is_array($sources)) {
            $sections = Compat::getAllSections();
        } else {
            foreach ($sources as $source) {
                $uid = $this->sourceUid($source, 'section:');

                if ($uid === null) {
                    continue;
                }
                $section = Compat::getSectionByUid($uid);

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
        if (! $context->craftField) {
            return;
        }
        $sources = $context->craftField->sources ?? '*';

        if ($sources === '*' || ! is_array($sources)) {
            return;
        }

        $sectionIds = [];

        foreach ($sources as $source) {
            $id = $this->sourceIdByUid($source, 'section:', CraftTable::SECTIONS);

            if ($id !== null) {
                $sectionIds[] = $id;
            }
        }

        if (! empty($sectionIds)) {
            /** @phpstan-ignore-next-line — Entries query exposes sectionId */
            $query->sectionId($sectionIds);
        }
    }

    protected function createMissing(FieldContext $context, mixed $value): ?ElementInterface
    {
        [$sectionId, $typeId] = $this->createTarget($context);

        if (! $sectionId || ! $typeId) {
            // No explicit target — bail rather than guess a section
            return null;
        }

        $entry = new CraftEntryElement();
        $entry->sectionId = $sectionId;
        $entry->typeId = $typeId;
        $entry->title = (string) $value;

        if (! Craft::$app->getElements()->saveElement($entry, true)) {
            return null;
        }

        return $entry;
    }

    /**
     * Resolve the create-target section/type ids for this environment from
     * the `group.section` / `group.type` handles — the environment-stable
     * form. A resolvable section without a resolvable type defaults to the
     * section's first entry type, same as a new entry in the CP.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function createTarget(FieldContext $context): array
    {
        $section = null;

        $sectionHandle = $context->mapping->option('group.section');

        if (is_string($sectionHandle) && $sectionHandle !== '') {
            $section = Compat::getSectionByHandle($sectionHandle);
        }

        if (! $section) {
            return [null, null];
        }

        $types = $section->getEntryTypes();
        $typeHandle = $context->mapping->option('group.type');

        foreach ($types as $type) {
            if (is_string($typeHandle) && $typeHandle !== '' && $type->handle === $typeHandle) {
                return [(int) $section->id, (int) $type->id];
            }
        }

        $first = $types[0] ?? null;

        return [(int) $section->id, $first ? (int) $first->id : null];
    }
}
