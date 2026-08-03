<?php

namespace GlueAgency\Influx\fields;

use craft\base\ElementInterface;
use craft\db\Table as CraftTable;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry as CraftEntryElement;
use craft\fields\BaseRelationField;
use craft\fields\Entries as CraftEntriesField;
use craft\models\Section;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\schema\NativeAttributes;
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

    public function childrenKind(): ?string
    {
        return 'entries';
    }

    protected function elementType(): string
    {
        return CraftEntryElement::class;
    }

    protected function nativeMatchAttributes(BaseRelationField $field): array
    {
        return NativeAttributes::entryMatchable($this->sourceEntryTypes($field));
    }

    protected function nativeWritableAttributes(BaseRelationField $field): array
    {
        return NativeAttributes::entryWritable($this->sourceEntryTypes($field));
    }

    protected function sourceFieldLayouts(BaseRelationField $field): iterable
    {
        foreach ($this->sourceEntryTypes($field) as $type) {
            yield $type->getFieldLayout();
        }
    }

    /**
     * The entry types this field may relate — every type of every allowed
     * section, which is what both the schema (title / slug gating, custom
     * sub-fields, match options) and the layout walk are built from.
     *
     * @return list<\craft\models\EntryType>
     */
    protected function sourceEntryTypes(BaseRelationField $field): array
    {
        $types = [];

        foreach ($this->sourceSections($field->sources ?? '*') ?? $this->allSections() as $section) {
            foreach ($section->getEntryTypes() as $type) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * The sections a source list names, or null for "no constraint" — which an
     * unrestricted field (`'*'`) and a malformed setting both mean.
     *
     * Two key shapes, and the second one is easy to miss: a section is named
     * `section:UID`, but a field restricted to Singles stores the bare literal
     * `singles` instead (there's no vendor constant for the key, only
     * `Section::TYPE_SINGLE` for the type). Decoding only the first shape left a
     * Singles-only field looking sourceless: no match options, no sub-field card
     * and no scoping on its lookups.
     *
     * @return ?list<\craft\models\Section>
     */
    protected function sourceSections(mixed $sources): ?array
    {
        if ($sources === '*' || ! is_array($sources)) {
            return null;
        }

        $sections = [];

        foreach ($sources as $source) {
            if ($source === 'singles') {
                foreach ($this->allSections() as $section) {
                    if ($section->type === Section::TYPE_SINGLE) {
                        $sections[] = $section;
                    }
                }

                continue;
            }

            $uid = $this->sourceUid($source, 'section:');
            $section = $uid !== null ? $this->sectionByUid($uid) : null;

            if ($section) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * The two section reads, behind seams: they're static Compat calls that
     * need a booted Craft, and the source decoding above is worth specifying
     * without one.
     *
     * @return list<Section>
     */
    protected function allSections(): array
    {
        return Compat::getAllSections();
    }

    protected function sectionByUid(string $uid): ?Section
    {
        return Compat::getSectionByUid($uid);
    }

    protected function scopeBySources(FieldContext $context, ElementQueryInterface $query): void
    {
        if (! $context->craftField) {
            return;
        }

        $sections = $this->sourceSections($context->craftField->sources ?? '*');

        if ($sections === null) {
            return;
        }

        $sectionIds = [];

        foreach ($sections as $section) {
            // By uid rather than off the model: a section id isn't Project
            // Config, so the resolved id is the one this environment stores.
            $id = $this->sourceIdByUid('section:' . $section->uid, 'section:', CraftTable::SECTIONS);

            if ($id !== null) {
                $sectionIds[] = $id;
            }
        }

        if (! empty($sectionIds)) {
            /** @phpstan-ignore-next-line — Entries query exposes sectionId */
            $query->sectionId($sectionIds);
        }
    }

    /**
     * Without an explicit, resolvable create target nothing is created — bailing
     * beats guessing a section.
     *
     * A REFUSED save throws instead ({@see Relation::persistNewElement()}):
     * resolving to nothing would thin the relation the feed spelled out, or clear
     * it when no reference survives.
     *
     * @throws \GlueAgency\Influx\exceptions\MappingValueException when the new
     * entry refuses to save
     */
    protected function createMissing(FieldContext $context, mixed $value): ?ElementInterface
    {
        [$sectionId, $typeId] = $this->createTarget($context);

        if (! $sectionId || ! $typeId) {
            return null;
        }

        $class = $this->elementType();
        /** @var CraftEntryElement $entry */
        $entry = new $class();
        $entry->sectionId = $sectionId;
        $entry->typeId = $typeId;
        $entry->title = (string) $value;

        return $this->persistNewElement($entry, $value);
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
