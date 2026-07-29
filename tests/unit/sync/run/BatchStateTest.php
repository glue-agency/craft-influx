<?php

namespace GlueAgency\Influx\Tests\unit\sync\run;

use Codeception\Test\Unit;
use GlueAgency\Influx\sync\run\BatchState;

/**
 * Round-trip spec for the resumable queue state — {@see BatchState}.
 *
 * The regression class this pins: the carried keys used to be echoed in
 * three places (the job's properties, the state array
 * {@see \GlueAgency\Influx\services\SynchronizationService::batchStep()} threads
 * through, and the re-push), so any of the three could quietly drop one.
 * Dropping `seenIds` in particular makes the missing-elements sweep
 * over-disable, since it would then only "see" the final page's items. Every key
 * must survive fromArray() → toArray() untouched.
 *
 * No Craft boot: the value object only assigns and re-reads.
 */
class BatchStateTest extends Unit
{
    public function testEveryCarriedKeySurvivesTheRoundTrip(): void
    {
        $state = [
            'logId'              => 42,
            'cursorUrl'          => 'https://example.test/articles?page=3',
            'page'               => 3,
            'seenIds'            => [7, 8, 9],
            'unattributedErrors' => 2,
            'firstPageSize'      => 25,
            'itemsSeen'          => 55,
        ];

        $this->assertSame($state + ['done' => false], BatchState::fromArray($state)->toArray());
    }

    public function testAFreshStateDefaultsToPageOneWithNothingSeen(): void
    {
        $this->assertSame([
            'logId'              => null,
            'cursorUrl'          => null,
            'page'               => 1,
            'seenIds'            => [],
            'unattributedErrors' => 0,
            'firstPageSize'      => null,
            'itemsSeen'          => 0,
            'done'               => false,
        ], BatchState::fromArray([])->toArray());
    }

    public function testDoneIsNeverReadBackIn(): void
    {
        // A state being handed TO a step is by definition unfinished; a stale
        // `done` in the payload must not short-circuit the step that resumes it.
        $this->assertFalse(BatchState::fromArray(['done' => true])->done);
    }

    public function testTheSeenSetIsDeduplicatedAndKeptInOrder(): void
    {
        // The set is held value-keyed so a re-processed tail (a retried step)
        // can't double-count an id; first occurrence wins the position.
        $state = BatchState::fromArray(['seenIds' => [5, 3, 5, 9, 3]]);
        $state->walk->markSeen(9);
        $state->walk->markSeen(11);

        $this->assertSame([5, 3, 9, 11], $state->toArray()['seenIds']);
    }

    public function testTheCarriedSliceOmitsDone(): void
    {
        // `done` is the step's answer, not part of the re-pushed job payload —
        // the job spreads carried() over its own properties.
        $carried = (new BatchState(logId: 1, cursorUrl: 'https://example.test/2', page: 2))->carried();

        $this->assertArrayNotHasKey('done', $carried);
        $this->assertSame(
            ['logId', 'cursorUrl', 'page', 'seenIds', 'unattributedErrors', 'firstPageSize', 'itemsSeen'],
            array_keys($carried),
        );
    }

    /**
     * The progress numerator is cumulative over the scope's pages, so a step has
     * to resume the count rather than restart it — a dropped `itemsSeen` walks
     * the queue's percentage back to 0 on every page.
     */
    public function testTheItemsSeenCountResumesFromTheCarriedState(): void
    {
        $state = BatchState::fromArray(['itemsSeen' => 100, 'firstPageSize' => 50]);

        $this->assertSame(100, $state->walk->itemsSeen);

        $state->walk->itemsSeen++;

        $this->assertSame(101, $state->carried()['itemsSeen']);
    }

    public function testWalkAdvancesArePickedUpByToArray(): void
    {
        $state = BatchState::fromArray(['page' => 1, 'seenIds' => [1]]);
        $state->walk->firstPageSize ??= 50;
        $state->walk->unattributedErrors++;
        $state->walk->markSeen(2);
        $state->walk->itemsSeen += 50;
        $state->cursorUrl = 'https://example.test/articles?page=2';
        $state->page++;

        $this->assertSame([
            'logId'              => null,
            'cursorUrl'          => 'https://example.test/articles?page=2',
            'page'               => 2,
            'seenIds'            => [1, 2],
            'unattributedErrors' => 1,
            'firstPageSize'      => 50,
            'itemsSeen'          => 50,
            'done'               => false,
        ], $state->toArray());
    }
}
