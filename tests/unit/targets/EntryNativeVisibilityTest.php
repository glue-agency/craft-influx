<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\models\EntryType;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\targets\EntryTarget;

/**
 * Entry natives follow the entry type's own visibility settings: a type with no
 * Title field, a hidden Slug or a hidden Status control doesn't REPORT that
 * native at all — the same treatment a custom field gets when it's removed from
 * the layout. Nothing downstream can then offer it, and a stored mapping for it
 * is pruned on the next save (see {@see EntryMappingPruneTest}).
 *
 * Exercised through the target's native declaration directly — the full
 * getMappableFields() path resolves the entry type through Craft, which the pure
 * suite has no boot for.
 */
class EntryNativeVisibilityTest extends Unit
{
    public function testUnresolvedCriteriaReportEveryNative(): void
    {
        // No entry type resolved yet, so no type setting can hide anything.
        $this->assertSame(
            ['title', 'slug', 'enabled', 'postDate', 'expiryDate', 'author'],
            $this->handles($this->natives(null)),
        );
    }

    public function testAFullyVisibleEntryTypeReportsEveryNative(): void
    {
        $this->assertSame(
            ['title', 'slug', 'enabled', 'postDate', 'expiryDate', 'author'],
            $this->handles($this->natives($this->entryType())),
        );
    }

    public function testATitlelessEntryTypeDropsTitle(): void
    {
        $handles = $this->handles($this->natives($this->entryType(['hasTitleField' => false])));

        $this->assertNotContains('title', $handles);
        $this->assertContains('slug', $handles);
    }

    public function testAHiddenSlugFieldDropsSlug(): void
    {
        $handles = $this->handles($this->natives($this->entryType(['showSlugField' => false])));

        $this->assertNotContains('slug', $handles);
        $this->assertContains('title', $handles);
    }

    public function testAHiddenStatusFieldDropsEnabled(): void
    {
        $handles = $this->handles($this->natives($this->entryType(['showStatusField' => false])));

        $this->assertNotContains('enabled', $handles);
    }

    public function testAllThreeHiddenLeavesOnlyTheUngatedNatives(): void
    {
        $fields = $this->natives($this->entryType([
            'hasTitleField'   => false,
            'showSlugField'   => false,
            'showStatusField' => false,
        ]));

        $this->assertSame(['postDate', 'expiryDate', 'author'], $this->handles($fields));
    }

    public function testDatesAndAuthorAreNeverGated(): void
    {
        // Entry types carry no visibility toggle for these, so they're always
        // reported — the gate must not over-reach.
        $handles = $this->handles($this->natives($this->entryType(['hasTitleField' => false])));

        foreach (['postDate', 'expiryDate', 'author'] as $handle) {
            $this->assertContains($handle, $handles);
        }
    }

    /**
     * The target's native declaration for a given (or unresolved) entry type.
     *
     * @return list<MappableField>
     */
    protected function natives(?EntryType $entryType): array
    {
        $target = new class() extends EntryTarget {
            public function natives(?EntryType $entryType): SchemaBuilder
            {
                return $this->nativeFieldDefinitions($entryType);
            }

            /** The author's match-by options read the global User field layout. */
            protected function authorMatchOptions(): array
            {
                return [];
            }
        };

        return $target->natives($entryType)->toArray();
    }

    protected function entryType(array $config = []): EntryType
    {
        return new EntryType($config + ['handle' => 'article', 'name' => 'Article']);
    }

    /**
     * @param list<MappableField> $fields
     * @return list<string>
     */
    protected function handles(array $fields): array
    {
        return array_map(static fn(MappableField $field): string => $field->handle, $fields);
    }
}
