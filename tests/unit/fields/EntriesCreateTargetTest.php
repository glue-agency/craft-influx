<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\Entries as CraftEntriesField;
use craft\models\EntryType;
use craft\models\Section;
use GlueAgency\Influx\fields\Entries;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Where {@see Entries::createMissing()} puts an entry it has to create.
 *
 * Read off the FIELD, not the mapping. An Entries field's `sources` already name
 * the sections it may relate, so the create target is a fact the field settles —
 * it used to be stored as `options.group.{section,type}` as well, which was a
 * second copy that no builder control could edit and the save-time prune
 * therefore stripped.
 *
 * The section lookup needs a booted Craft, so it goes through the same
 * {@see Entries::sectionByUid()} seam the rest of the flavour uses.
 */
class EntriesCreateTargetTest extends Unit
{
    public function testASingleSourceSectionIsTheTarget(): void
    {
        [$sectionId, $typeId] = $this->target(['section:news-uid'], [
            'news-uid' => $this->section(7, 'news', [[21, 'article']]),
        ]);

        $this->assertSame(7, $sectionId);
        $this->assertSame(21, $typeId);
    }

    public function testTheSectionsFirstEntryTypeIsTheDefault(): void
    {
        // What the CP opens for a new entry in a multi-type section.
        [, $typeId] = $this->target(['section:news-uid'], [
            'news-uid' => $this->section(7, 'news', [[21, 'article'], [22, 'column']]),
        ]);

        $this->assertSame(21, $typeId);
    }

    public function testTheFirstAllowedSourceWinsWhenAFieldNamesSeveral(): void
    {
        // Craft takes the same one for the field's own search hint.
        [$sectionId, $typeId] = $this->target(['section:news-uid', 'section:blog-uid'], [
            'news-uid' => $this->section(7, 'news', [[21, 'article']]),
            'blog-uid' => $this->section(8, 'blog', [[31, 'post']]),
        ]);

        $this->assertSame(7, $sectionId);
        $this->assertSame(21, $typeId);
    }

    public function testAnUnrestrictedFieldCreatesNothing(): void
    {
        // "Any section in the project" is not a target — filing entries into an
        // arbitrary one is worse than not creating them.
        $this->assertSame([null, null], $this->target('*', []));
    }

    public function testASourceNamingASectionThisEnvironmentLacksCreatesNothing(): void
    {
        $this->assertSame([null, null], $this->target(['section:gone-uid'], []));
    }

    /**
     * Resolve the create target for a field with these `sources`, against a stub
     * install holding these sections by uid.
     *
     * @param array<string, Section> $sections
     * @return array{0: ?int, 1: ?int}
     */
    protected function target(mixed $sources, array $sections): array
    {
        $strategy = new class($sections) extends Entries {
            /** @var array<string, Section> */
            public array $sections = [];

            public function __construct(array $sections)
            {
                $this->sections = $sections;
            }

            public function resolve(FieldContext $context): array
            {
                return $this->createTarget($context);
            }

            protected function sectionByUid(string $uid): ?Section
            {
                return $this->sections[$uid] ?? null;
            }

            protected function allSections(): array
            {
                return array_values($this->sections);
            }
        };

        $field = $this->createMock(CraftEntriesField::class);
        $field->sources = $sources;

        return $strategy->resolve(new FieldContext(
            craftField: $field,
            handle: 'related',
            mapping: FieldMapping::fromConfig('related', ['node' => 'related.slug', 'options' => ['create' => true]]),
            item: new RemoteItem(['related' => []]),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        ));
    }

    /**
     * A section carrying the entry types named, each `[id, handle]`.
     *
     * @param list<array{0: int, 1: string}> $types
     */
    protected function section(int $id, string $handle, array $types): Section
    {
        $entryTypes = [];

        foreach ($types as [$typeId, $typeHandle]) {
            $type = $this->createMock(EntryType::class);
            $type->id = $typeId;
            $type->handle = $typeHandle;
            $entryTypes[] = $type;
        }

        $section = $this->createMock(Section::class);
        $section->id = $id;
        $section->handle = $handle;
        $section->method('getEntryTypes')->willReturn($entryTypes);

        return $section;
    }
}
