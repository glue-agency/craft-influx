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
 *
 * WHERE a created entry goes is not among them: the field's own `sources`
 * already name the sections it may relate, so {@see createTarget()} reads that
 * instead of asking the mapping to repeat it.
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
     * The section and entry type a created entry goes in, resolved for THIS
     * environment from the FIELD's own allowed sources.
     *
     * Not from stored config, which is what this used to read
     * (`options.group.{section,type}`, written by the Feed Me converter). The
     * field already says which sections it may relate, so a mapping repeating
     * that was a second copy of one fact — one that goes stale when the field's
     * sources change, that no control in the builder could correct, and that the
     * save-time prune therefore stripped as an option nothing declared.
     *
     * Where the field leaves room, the answer follows Craft's: the FIRST allowed
     * source (Craft takes the same one for the field's search hint,
     * {@see \craft\fields\Entries::inputTemplateVariables()}) and that section's
     * FIRST entry type, which is what the CP opens for a new entry.
     *
     * An unrestricted field (`sources: '*'`) resolves to nothing. "Any section in
     * the project" isn't a target, and picking one would file entries somewhere
     * arbitrary — so nothing is created, the same restraint this kept when the
     * stored target was missing.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function createTarget(FieldContext $context): array
    {
        $field = $context->craftField;
        $sections = $field ? $this->sourceSections($field->sources ?? '*') : null;
        $section = $sections[0] ?? null;

        if (! $section) {
            return [null, null];
        }

        // Off the model rather than the stored source key: it came from a uid
        // lookup, so the id is the one this environment holds.
        $type = $section->getEntryTypes()[0] ?? null;

        return [(int) $section->id, $type ? (int) $type->id : null];
    }
}
