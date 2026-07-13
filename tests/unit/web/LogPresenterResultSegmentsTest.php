<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use GlueAgency\Influx\web\LogPresenter;

/**
 * Behaviour spec for {@see LogPresenter}'s overview composition cores — the
 * pill segments a run resolves to, the duration label, and the status-dot
 * colour. Pure: they take primitives, so no Craft boot or real record is
 * needed (labels are applied later, in Twig).
 */
class LogPresenterResultSegmentsTest extends Unit
{
    public function testSettledRunListsOnlyNonZeroActionsInOrder(): void
    {
        $segments = LogPresenter::composeResultSegments([
            'seen'      => 128,
            'created'   => 3,
            'updated'   => 12,
            'unchanged' => 113,
            'skipped'   => 0,
            'disabled'  => 0,
            'deleted'   => 0,
        ], 'ok');

        // No `seen` pill on a settled run; only the actions that happened,
        // in fixed order, each with its result-palette colour.
        $this->assertSame([
            ['count' => 3, 'kind' => 'created', 'color' => 'green'],
            ['count' => 12, 'kind' => 'updated', 'color' => 'green'],
            ['count' => 113, 'kind' => 'unchanged', 'color' => 'gray'],
        ], $segments);
    }

    public function testDeletedIsRedEverythingZeroIsEmpty(): void
    {
        $this->assertSame(
            [['count' => 2, 'kind' => 'deleted', 'color' => 'red']],
            LogPresenter::composeResultSegments(['deleted' => 2], 'ok'),
        );

        $this->assertSame([], LogPresenter::composeResultSegments([], 'ok'));
    }

    public function testRunningRunLeadsWithSeenProgressPill(): void
    {
        $segments = LogPresenter::composeResultSegments([
            'seen'    => 84,
            'updated' => 2,
        ], 'running');

        $this->assertSame([
            ['count' => 84, 'kind' => 'seen', 'color' => 'blue'],
            ['count' => 2, 'kind' => 'updated', 'color' => 'green'],
        ], $segments);
    }

    public function testFormatDuration(): void
    {
        $this->assertSame('41s', LogPresenter::formatDuration(41));
        $this->assertSame('0s', LogPresenter::formatDuration(0));
        $this->assertNull(LogPresenter::formatDuration(null));
        $this->assertNull(LogPresenter::formatDuration(-3));
    }

    public function testStatusColor(): void
    {
        $this->assertSame('live', LogPresenter::statusColor('ok'));
        $this->assertSame('expired', LogPresenter::statusColor('error'));
        $this->assertSame('pending', LogPresenter::statusColor('running'));
        $this->assertSame('pending', LogPresenter::statusColor('pending'));
    }
}
