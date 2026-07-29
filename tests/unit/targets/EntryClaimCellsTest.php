<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\elements\Entry;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\EntryTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * {@see EntryTarget::claimCells()}: how an entry link's section/type criteria
 * expand into the comparable `"{section} {entryType}"` cells
 * {@see Link::overlaps()} intersects. The expansion lives on the target because
 * partitioning an element type is the target's business — a custom element type
 * expands (or doesn't) by its own rules.
 *
 * The project-config section→entry-type map is injected through the target's
 * `sectionEntryTypeMap()` seam, so the set logic runs without a booted Craft.
 */
class EntryClaimCellsTest extends Unit
{
    public function testBothCriteriaYieldTheSingleCell(): void
    {
        $cells = $this->cells(
            ['news' => ['article', 'story'], 'blog' => ['post']],
            ['section' => 'news', 'type' => 'article'],
        );

        $this->assertSame(['news article'], $cells);
    }

    public function testSectionOnlyExpandsToEveryTypeInThatSection(): void
    {
        $cells = $this->cells(
            ['news' => ['article', 'story'], 'blog' => ['post']],
            ['section' => 'news'],
        );

        $this->assertSame(['news article', 'news story'], $cells);
    }

    public function testTypeOnlySpansEverySectionUsingThatType(): void
    {
        // Craft 5 shares entry types across sections: a type-only criterion
        // spans every section that uses the type.
        $cells = $this->cells(
            ['news' => ['article', 'story'], 'features' => ['article']],
            ['type' => 'article'],
        );

        $this->assertSame(['news article', 'features article'], $cells);
    }

    public function testNoCriteriaCoversEveryCell(): void
    {
        $cells = $this->cells(['news' => ['article', 'story'], 'blog' => ['post']], []);

        $this->assertSame(['news article', 'news story', 'blog post'], $cells);
    }

    public function testAnEmptyCriterionIsNoCriterion(): void
    {
        // Link::criterion() reads '' as "not scoped" — the same rule
        // targetsElement() and the resolvers apply.
        $cells = $this->cells(
            ['news' => ['article', 'story'], 'blog' => ['post']],
            ['section' => '', 'type' => ''],
        );

        $this->assertSame(['news article', 'news story', 'blog post'], $cells);
    }

    public function testAnUnknownSectionClaimsNothing(): void
    {
        // A since-deleted section leaves the link owning no cell at all, so it
        // can't overlap anything — better than silently claiming everything.
        $cells = $this->cells(['news' => ['article']], ['section' => 'gone']);

        $this->assertSame([], $cells);
    }

    /**
     * @param array<string, list<string>> $map
     * @param array<string, string> $criteria
     * @return list<string>
     */
    protected function cells(array $map, array $criteria): array
    {
        $link = FakeLink::make(['elementType' => Entry::class, 'elementCriteria' => $criteria]);

        return $this->target($map)->claimCells($link);
    }

    /**
     * An EntryTarget whose project-config section→type map is a fixed fixture.
     *
     * @param array<string, list<string>> $map
     */
    protected function target(array $map): EntryTarget
    {
        $target = new class() extends EntryTarget {
            /** @var array<string, list<string>> */
            public array $map = [];

            protected function sectionEntryTypeMap(): array
            {
                return $this->map;
            }
        };
        $target->map = $map;

        return $target;
    }
}
