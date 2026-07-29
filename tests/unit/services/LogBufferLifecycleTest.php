<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\services\LogsService;

/**
 * Behaviour spec for the log-item buffer map's lifecycle in
 * {@see LogsService}.
 *
 * flush() empties a buffer OBJECT but deliberately keeps its map entry — the run
 * is still open and will buffer again on the next page. The entry itself is
 * released where the log's lifecycle ends (finish()/fail()), or a long-lived
 * queue worker keeps one dead buffer per log id it ever wrote. Reopening a log
 * after that has to keep working: bufferFor() recreates lazily, which is what
 * makes the flush-then-reopen path across queue steps safe.
 */
class LogBufferLifecycleTest extends Unit
{
    public function testFinishReleasesTheBufferEntry(): void
    {
        $logs = $this->service();
        $log = $this->log(7);

        $logs->recordItem($log, ItemAction::CREATED, 11, 'abc');
        $this->assertSame([7], $logs->bufferedLogIds());

        $logs->finish($log);

        $this->assertSame([], $logs->bufferedLogIds());
        $this->assertSame([7], $logs->flushedLogIds);
    }

    public function testFailReleasesTheBufferEntry(): void
    {
        $logs = $this->service();
        $log = $this->log(9);

        $logs->recordItem($log, ItemAction::ERROR, null, 'abc', 'boom');
        $this->assertSame([9], $logs->bufferedLogIds());

        $logs->fail($log, 'boom');

        $this->assertSame([], $logs->bufferedLogIds());
        $this->assertSame([9], $logs->flushedLogIds);
    }

    public function testFlushKeepsTheEntryWhileTheRunIsStillOpen(): void
    {
        $logs = $this->service();
        $log = $this->log(3);

        $logs->recordItem($log, ItemAction::UPDATED, 11, 'abc');
        $logs->flush($log);

        $this->assertSame([3], $logs->bufferedLogIds());
    }

    public function testAReopenedLogBuffersIntoAFreshBuffer(): void
    {
        $logs = $this->service();
        $log = $this->log(5);

        $logs->recordItem($log, ItemAction::CREATED, 11, 'abc');
        $logs->finish($log);

        $reopened = $this->log(5);
        $logs->recordItem($reopened, ItemAction::UPDATED, 12, 'def');

        $this->assertSame([5], $logs->bufferedLogIds());
        $this->assertSame(1, $logs->bufferedRowCount(5));
    }

    /**
     * The service with its two out-of-process reaches stubbed: the flush's batch
     * insert (recorded, and draining the buffer the way the real one does) and
     * the nav-badge cache invalidation.
     */
    protected function service(): LogsService
    {
        return new class() extends LogsService {
            /** @var list<int> */
            public array $flushedLogIds = [];

            public function flush(LogRecord $log): void
            {
                $this->flushedLogIds[] = (int) $log->id;
                $this->bufferFor($log)->clear();
            }

            /**
             * @return list<int>
             */
            public function bufferedLogIds(): array
            {
                return array_map('intval', array_keys($this->buffers));
            }

            public function bufferedRowCount(int $logId): int
            {
                return isset($this->buffers[$logId]) ? $this->buffers[$logId]->count() : -1;
            }

            protected function invalidateErrorLogCount(): void
            {
            }
        };
    }

    /**
     * A log record standing in for a saved one: attribute reads/writes go to a
     * plain array instead of the table schema, and save() is a no-op — enough for
     * the buffer lifecycle, and it keeps the spec free of a database.
     */
    protected function log(int $id): LogRecord
    {
        $log = new class() extends LogRecord {
            /** @var array<string, mixed> */
            public array $values = [];

            public int $saves = 0;

            public function __get($name)
            {
                return $this->values[$name] ?? null;
            }

            public function __set($name, $value)
            {
                $this->values[$name] = $value;
            }

            public function save($runValidation = true, $attributeNames = null)
            {
                $this->saves++;

                return true;
            }
        };
        $log->id = $id;

        return $log;
    }
}
