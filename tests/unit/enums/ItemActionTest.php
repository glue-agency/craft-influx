<?php

namespace GlueAgency\Influx\Tests\unit\enums;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\ItemAction;

/**
 * Behaviour spec for {@see ItemAction::filterGroup()} — the set of action values
 * a log-detail filter should match. The UI's filters are the grouped counters,
 * so a base action must pull in its per-site sibling or the filtered list
 * undercounts against the counter it was clicked from.
 */
class ItemActionTest extends Unit
{
    public function testDeletedGroupsWithItsPerSiteSibling(): void
    {
        $group = ItemAction::DELETED->filterGroup();

        $this->assertContains('deleted', $group);
        $this->assertContains('deleted-for-site', $group);
        $this->assertCount(2, $group);
    }

    public function testDisabledGroupsWithItsPerSiteSibling(): void
    {
        $group = ItemAction::DISABLED->filterGroup();

        $this->assertContains('disabled', $group);
        $this->assertContains('disabled-for-site', $group);
        $this->assertCount(2, $group);
    }

    public function testPerSiteVariantResolvesToTheSameGroup(): void
    {
        // Filtering from either member of a counter group yields the whole
        // group, so the entry point doesn't matter.
        $this->assertEqualsCanonicalizing(
            ItemAction::DELETED->filterGroup(),
            ItemAction::DELETED_FOR_SITE->filterGroup(),
        );
    }

    public function testSinglyCountedActionMatchesOnlyItself(): void
    {
        $this->assertSame(['created'], ItemAction::CREATED->filterGroup());
        $this->assertSame(['updated'], ItemAction::UPDATED->filterGroup());
    }

    public function testUncountedErrorMatchesOnlyItself(): void
    {
        // ERROR has no counter, so it can't group by one — itself only.
        $this->assertSame(['error'], ItemAction::ERROR->filterGroup());
    }

    public function testCountedCasesAreOneBasePerCounterInDisplayOrder(): void
    {
        $this->assertSame([
            'created',
            'updated',
            'unchanged',
            'skipped',
            'disabled',
            'deleted',
        ], array_map(
            static fn(ItemAction $case): string => $case->value,
            ItemAction::countedCases(),
        ));
    }

    public function testCountedCasesCoverEveryCounterExactlyOnce(): void
    {
        $counters = array_map(
            static fn(ItemAction $case): ?string => $case->counterAttribute(),
            ItemAction::countedCases(),
        );

        $this->assertSame($counters, array_unique($counters));
        $this->assertNotContains(null, $counters);

        // Every counted case is reachable through some case's counter.
        $all = array_filter(array_map(
            static fn(ItemAction $case): ?string => $case->counterAttribute(),
            ItemAction::cases(),
        ));
        $this->assertEqualsCanonicalizing(array_values(array_unique($all)), $counters);
    }

    public function testColorIsTheCraftStatusPalette(): void
    {
        $this->assertSame('live', ItemAction::CREATED->color());
        $this->assertSame('live', ItemAction::UPDATED->color());
        $this->assertSame('pending', ItemAction::UNCHANGED->color());
        $this->assertSame('pending', ItemAction::SKIPPED->color());
        $this->assertSame('expired', ItemAction::ERROR->color());
        $this->assertSame('expired', ItemAction::DISABLED->color());
        $this->assertSame('expired', ItemAction::DELETED->color());
    }

    public function testPerSiteVariantIsColouredLikeItsGlobalSibling(): void
    {
        $this->assertSame(ItemAction::DISABLED->color(), ItemAction::DISABLED_FOR_SITE->color());
        $this->assertSame(ItemAction::DELETED->color(), ItemAction::DELETED_FOR_SITE->color());
        $this->assertSame(ItemAction::DISABLED->pillColor(), ItemAction::DISABLED_FOR_SITE->pillColor());
        $this->assertSame(ItemAction::DELETED->pillColor(), ItemAction::DELETED_FOR_SITE->pillColor());
    }

    public function testPillColorIsTheResultPaletteAndDivergesOnDisabled(): void
    {
        $this->assertSame('green', ItemAction::CREATED->pillColor());
        $this->assertSame('green', ItemAction::UPDATED->pillColor());
        $this->assertSame('gray', ItemAction::UNCHANGED->pillColor());
        $this->assertSame('gray', ItemAction::SKIPPED->pillColor());
        $this->assertSame('red', ItemAction::DELETED->pillColor());

        // The one deliberate divergence between the two palettes: a disabled
        // element reads as neutral in a run's result summary, but destructive on
        // the badge of the row that caused it.
        $this->assertSame('gray', ItemAction::DISABLED->pillColor());
        $this->assertSame('expired', ItemAction::DISABLED->color());
    }
}
