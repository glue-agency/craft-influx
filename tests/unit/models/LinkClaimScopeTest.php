<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\elements\User;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\targets\ElementTargetInterface;
use GlueAgency\Influx\targets\UserTarget;
use RuntimeException;

/**
 * {@see Link::claimScope()} + {@see Link::overlaps()}: the structural
 * scope-overlap detection that warns when two links both define a resource
 * mapping for the same elements.
 *
 * The model only supplies the element type and delegates the cells to the link's
 * target ({@see ElementTargetInterface::claimCells()}), so the cells here come
 * from a stub target injected through the `target()` seam — the entry-specific
 * section×type expansion is specced where it now lives, in
 * {@see \GlueAgency\Influx\Tests\unit\targets\EntryClaimCellsTest}. No Craft
 * boot either way.
 */
class LinkClaimScopeTest extends Unit
{
    public function testUserLinksAlwaysOverlapEachOther(): void
    {
        $a = $this->userLink();
        $b = $this->userLink();

        // No sub-partition for users — the real UserTarget inherits the base's
        // single sentinel cell, keyed by type.
        $this->assertSame(['type' => ltrim(User::class, '\\'), 'cells' => ['*']], $a->claimScope());
        $this->assertTrue($a->overlaps($b));
    }

    public function testDifferentElementTypesNeverOverlap(): void
    {
        $entry = $this->entryLink(['news article']);
        $user = $this->userLink();

        $this->assertFalse($entry->overlaps($user));
        $this->assertFalse($user->overlaps($entry));
    }

    public function testDisjointCellsDoNotOverlap(): void
    {
        $a = $this->entryLink(['news article']);
        $b = $this->entryLink(['blog post']);

        $this->assertFalse($a->overlaps($b));
    }

    public function testIdenticalCellsOverlap(): void
    {
        $a = $this->entryLink(['news article']);
        $b = $this->entryLink(['news article']);

        $this->assertTrue($a->overlaps($b));
    }

    public function testAWiderCellSetOverlapsANarrowerOne(): void
    {
        // A section-only link expands to every type in the section, so it
        // overlaps a link scoped to one of them — but not a disjoint section.
        $sectionOnly = $this->entryLink(['news article', 'news story']);

        $this->assertTrue($sectionOnly->overlaps($this->entryLink(['news article'])));
        $this->assertFalse($sectionOnly->overlaps($this->entryLink(['blog post'])));
    }

    public function testTheCellsComeFromTheTargetVerbatim(): void
    {
        $link = $this->entryLink(['news article', 'news story']);

        $this->assertSame(
            ['type' => ltrim(Entry::class, '\\'), 'cells' => ['news article', 'news story']],
            $link->claimScope(),
        );
    }

    public function testAnUnresolvableTargetClaimsEverything(): void
    {
        // No registered target — an unknown element type, or (as here) a link in
        // a process where the plugin isn't bootstrapped. Falling back to the
        // sentinel keeps the warning conservative: two such links overlap.
        $a = $this->targetlessLink();
        $b = $this->targetlessLink();

        $this->assertSame(
            ['type' => 'vendor\elements\Widget', 'cells' => [ElementTargetInterface::CLAIM_ALL]],
            $a->claimScope(),
        );
        $this->assertTrue($a->overlaps($b));
    }

    protected function userLink(): Link
    {
        return $this->linkWithTarget(User::class, new UserTarget());
    }

    /**
     * An entry link whose target reports the given cells — standing in for the
     * expansion {@see \GlueAgency\Influx\targets\EntryTarget::claimCells()} does
     * against project config.
     *
     * @param list<string> $cells
     */
    protected function entryLink(array $cells): Link
    {
        return $this->linkWithTarget(Entry::class, $this->target($cells));
    }

    protected function targetlessLink(): Link
    {
        return $this->linkWithTarget('vendor\elements\Widget', null);
    }

    /**
     * A Link whose registered target is injected rather than resolved through the
     * plugin singleton (null in a bootless test).
     */
    protected function linkWithTarget(string $elementType, ?ElementTargetInterface $target): Link
    {
        $link = new class() extends Link {
            public ?ElementTargetInterface $targetStub = null;

            protected function target(): ?ElementTargetInterface
            {
                return $this->targetStub;
            }
        };
        $link->elementType = $elementType;
        $link->targetStub = $target;

        return $link;
    }

    /**
     * A target reporting fixed claim cells.
     *
     * @param list<string> $cells
     */
    protected function target(array $cells): ElementTargetInterface
    {
        $target = new class() extends AbstractElementTarget {
            /** @var list<string> */
            public array $cells = [];

            public static function elementType(): string
            {
                return Entry::class;
            }

            public function claimCells(Link $link): array
            {
                return $this->cells;
            }

            public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface
            {
                return null;
            }

            public function buildNew(Link $link, ?int $siteId = null): ElementInterface
            {
                throw new RuntimeException('not needed');
            }
        };
        $target->cells = $cells;

        return $target;
    }
}
