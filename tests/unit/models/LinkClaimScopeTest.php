<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use craft\elements\Entry;
use craft\elements\User;
use GlueAgency\Influx\models\Link;

/**
 * {@see Link::claimScope()} + {@see Link::overlaps()}: the structural
 * scope-overlap detection that warns when two links both define a resource
 * mapping for the same elements.
 *
 * The section→entry-type expansion reads project config in production; here it
 * runs against a fixed map injected through the `sectionEntryTypeMap()` seam,
 * so the set logic is exercised without a booted Craft.
 */
class LinkClaimScopeTest extends Unit
{
    public function testUserLinksAlwaysOverlapEachOther(): void
    {
        $a = $this->userLink();
        $b = $this->userLink();

        // No sub-partition for users — a single sentinel cell keyed by type.
        $this->assertSame(['type' => ltrim(User::class, '\\'), 'cells' => ['*']], $a->claimScope());
        $this->assertTrue($a->overlaps($b));
    }

    public function testDifferentElementTypesNeverOverlap(): void
    {
        $entry = $this->entryLink(['news' => ['article']], ['section' => 'news', 'type' => 'article']);
        $user = $this->userLink();

        $this->assertFalse($entry->overlaps($user));
        $this->assertFalse($user->overlaps($entry));
    }

    public function testDisjointSectionTypeCellsDoNotOverlap(): void
    {
        $map = ['news' => ['article', 'story'], 'blog' => ['post']];
        $a = $this->entryLink($map, ['section' => 'news', 'type' => 'article']);
        $b = $this->entryLink($map, ['section' => 'blog', 'type' => 'post']);

        $this->assertFalse($a->overlaps($b));
    }

    public function testIdenticalCellsOverlap(): void
    {
        $map = ['news' => ['article', 'story']];
        $a = $this->entryLink($map, ['section' => 'news', 'type' => 'article']);
        $b = $this->entryLink($map, ['section' => 'news', 'type' => 'article']);

        $this->assertTrue($a->overlaps($b));
    }

    public function testSectionOnlyExpandsToEveryTypeInThatSection(): void
    {
        $map = ['news' => ['article', 'story'], 'blog' => ['post']];
        $sectionOnly = $this->entryLink($map, ['section' => 'news']);

        $this->assertSame(['news article', 'news story'], $sectionOnly->claimScope()['cells']);

        // Overlaps a link scoped to one of its types, but not a disjoint section.
        $this->assertTrue($sectionOnly->overlaps($this->entryLink($map, ['section' => 'news', 'type' => 'article'])));
        $this->assertFalse($sectionOnly->overlaps($this->entryLink($map, ['section' => 'blog'])));
    }

    public function testTypeOnlySpansEverySectionUsingThatType(): void
    {
        // Craft 5 shares entry types across sections: a type-only criterion
        // spans every section that uses the type.
        $map = ['news' => ['article', 'story'], 'features' => ['article']];
        $typeOnly = $this->entryLink($map, ['type' => 'article']);

        $this->assertSame(['news article', 'features article'], $typeOnly->claimScope()['cells']);
    }

    public function testNoCriteriaCoversEveryCellAndOverlapsAnyScopedLink(): void
    {
        $map = ['news' => ['article', 'story'], 'blog' => ['post']];
        $unscoped = $this->entryLink($map, []);

        $this->assertSame(['news article', 'news story', 'blog post'], $unscoped->claimScope()['cells']);
        $this->assertTrue($unscoped->overlaps($this->entryLink($map, ['section' => 'blog', 'type' => 'post'])));
    }

    protected function userLink(): Link
    {
        $link = $this->scopedLink([]);
        $link->elementType = User::class;

        return $link;
    }

    protected function entryLink(array $map, array $criteria): Link
    {
        $link = $this->scopedLink($map);
        $link->elementType = Entry::class;
        $link->elementCriteria = $criteria;

        return $link;
    }

    /**
     * A Link whose project-config section→type map is a fixed fixture, so
     * scope expansion is deterministic and Craft-free.
     */
    protected function scopedLink(array $map): Link
    {
        $link = new class() extends Link {
            /** @var array<string, list<string>> */
            public array $map = [];

            protected function sectionEntryTypeMap(): array
            {
                return $this->map;
            }
        };
        $link->map = $map;

        return $link;
    }
}
