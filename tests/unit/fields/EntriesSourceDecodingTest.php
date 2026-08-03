<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\models\Section;
use GlueAgency\Influx\fields\Entries;

/**
 * How an Entries field's `sources` setting decodes into sections — the read
 * behind its match options, its sub-field card and the scoping of its lookups.
 *
 * The interesting case is `singles`: Craft names a section source `section:UID`,
 * but a field restricted to Singles stores that one bare literal instead. Only
 * decoding the first shape left a Singles-only field looking sourceless, which
 * is a mapping row with no options on it at all.
 */
class EntriesSourceDecodingTest extends Unit
{
    public function testAnUnrestrictedFieldImposesNoConstraint(): void
    {
        // Null is "every section", which the callers turn into the full list —
        // distinct from the empty list, which is "these named sources resolved
        // to nothing" and must stay a constraint.
        $this->assertNull($this->decode('*'));
        $this->assertNull($this->decode(null));
        $this->assertNull($this->decode('some string'));
    }

    public function testNamedSectionsResolveByUid(): void
    {
        $sections = $this->decode(['section:news-uid', 'section:events-uid']);

        $this->assertSame(['news', 'events'], array_map(static fn(Section $s): string => $s->handle, $sections));
    }

    public function testTheSinglesKeyResolvesToEverySingleSection(): void
    {
        $sections = $this->decode(['singles']);

        $this->assertSame(['home', 'about'], array_map(static fn(Section $s): string => $s->handle, $sections));
    }

    public function testSinglesCombinesWithNamedSections(): void
    {
        // Craft lets a field pick Singles alongside individual sections.
        $sections = $this->decode(['singles', 'section:news-uid']);

        $this->assertSame(['home', 'about', 'news'], array_map(static fn(Section $s): string => $s->handle, $sections));
    }

    public function testAnUnknownKeyResolvesToNothing(): void
    {
        // An empty list, not null: the field IS restricted, to sources this
        // install no longer has.
        $this->assertSame([], $this->decode(['section:deleted-uid', 'volume:wrong-kind']));
    }

    /**
     * @return ?list<Section>
     */
    protected function decode(mixed $sources): ?array
    {
        $strategy = new class($this->sections()) extends Entries {
            /** @var list<Section> */
            public array $sections = [];

            public function __construct(array $sections)
            {
                $this->sections = $sections;
            }

            public function exposedSourceSections(mixed $sources): ?array
            {
                return $this->sourceSections($sources);
            }

            protected function allSections(): array
            {
                return $this->sections;
            }

            protected function sectionByUid(string $uid): ?Section
            {
                foreach ($this->sections as $section) {
                    if ($section->uid === $uid) {
                        return $section;
                    }
                }

                return null;
            }
        };

        return $strategy->exposedSourceSections($sources);
    }

    /**
     * Two Singles and one channel, so a spec can tell "every single" from
     * "everything".
     *
     * @return list<Section>
     */
    protected function sections(): array
    {
        return [
            $this->section('home', 'home-uid', Section::TYPE_SINGLE),
            $this->section('news', 'news-uid', Section::TYPE_CHANNEL),
            $this->section('about', 'about-uid', Section::TYPE_SINGLE),
            $this->section('events', 'events-uid', Section::TYPE_STRUCTURE),
        ];
    }

    protected function section(string $handle, string $uid, string $type): Section
    {
        $section = new Section();
        $section->handle = $handle;
        $section->uid = $uid;
        $section->type = $type;

        return $section;
    }
}
