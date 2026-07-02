<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use GlueAgency\Influx\sync\LogItemBuffer;
use RuntimeException;

/**
 * Behaviour spec for {@see LogItemBuffer}: rows accumulate in order, counter
 * deltas aggregate per column, itemsSeen counts every add (including a null
 * counter attribute), and clear() empties both.
 */
class LogItemBufferTest extends Unit
{
    public function testRowsAccumulateInInsertionOrder(): void
    {
        $buffer = new LogItemBuffer();
        $buffer->add(['a'], 'itemsCreated');
        $buffer->add(['b'], 'itemsUpdated');
        $buffer->add(['c'], 'itemsCreated');

        $this->assertSame([['a'], ['b'], ['c']], $buffer->rows());
        $this->assertSame(3, $buffer->count());
    }

    public function testCounterDeltasAggregatePerColumn(): void
    {
        $buffer = new LogItemBuffer();
        $buffer->add(['a'], 'itemsCreated');
        $buffer->add(['b'], 'itemsCreated');
        $buffer->add(['c'], 'itemsUpdated');

        $deltas = $buffer->counterDeltas();

        $this->assertSame(2, $deltas['itemsCreated']);
        $this->assertSame(1, $deltas['itemsUpdated']);
        $this->assertSame(3, $deltas['itemsSeen']);
    }

    public function testItemsSeenCountsEveryAddIncludingNullCounterAttribute(): void
    {
        $buffer = new LogItemBuffer();
        $buffer->add(['ok'], 'itemsCreated');
        // ERROR action carries a null counter attribute — still counts toward
        // itemsSeen but contributes no per-action column.
        $buffer->add(['err'], null);

        $deltas = $buffer->counterDeltas();

        $this->assertSame(2, $deltas['itemsSeen']);
        $this->assertSame(1, $deltas['itemsCreated']);
        $this->assertArrayNotHasKey('error', $deltas);
    }

    public function testIsEmptyReflectsRowState(): void
    {
        $buffer = new LogItemBuffer();

        $this->assertTrue($buffer->isEmpty());
        $this->assertSame(0, $buffer->count());

        $buffer->add(['a'], null);

        $this->assertFalse($buffer->isEmpty());
    }

    public function testClearEmptiesRowsAndDeltas(): void
    {
        $buffer = new LogItemBuffer();
        $buffer->add(['a'], 'itemsCreated');
        $buffer->clear();

        $this->assertTrue($buffer->isEmpty());
        $this->assertSame([], $buffer->rows());
        $this->assertSame([], $buffer->counterDeltas());
    }

    /**
     * The flush-on-exception guarantee: SynchronizationService::batchStep()
     * wraps the per-page item loop in try/finally { flush }, so rows recorded
     * before a mid-page throw must still be sitting in the buffer — complete
     * and in order, with their counter deltas — when the finally drains it.
     * This locks the buffer side of that contract; the DB write itself
     * (LogsService::flush) needs a booted Craft and stays on the manual
     * verification list.
     */
    public function testRowsRecordedBeforeAnExceptionSurviveForTheFinallyFlush(): void
    {
        $buffer = new LogItemBuffer();
        $drainedRows = null;
        $drainedDeltas = null;

        try {
            try {
                $buffer->add(['a'], 'itemsCreated');
                $buffer->add(['b'], 'itemsUpdated');

                throw new RuntimeException('mid-page failure');
            } finally {
                // What LogsService::flush() does at the page boundary: read
                // both, write them out, clear.
                $drainedRows = $buffer->rows();
                $drainedDeltas = $buffer->counterDeltas();
                $buffer->clear();
            }
        } catch (RuntimeException) {
            // The queue step rethrows/fails after the finally — by then the
            // buffer must already be drained.
        }

        $this->assertSame([['a'], ['b']], $drainedRows);
        $this->assertSame(2, $drainedDeltas['itemsSeen']);
        $this->assertSame(1, $drainedDeltas['itemsCreated']);
        $this->assertSame(1, $drainedDeltas['itemsUpdated']);
        // Nothing left behind for a retried step to double-write.
        $this->assertTrue($buffer->isEmpty());
    }
}
