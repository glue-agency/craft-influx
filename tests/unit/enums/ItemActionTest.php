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
}
