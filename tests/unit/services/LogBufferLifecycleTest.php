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
 *
 * Plus the shape of what gets buffered, specced here because it is the same
 * recordItem() → flush() seam: the row is built POSITIONALLY and inserted
 * against ITEM_COLUMNS, so a drift between the two silently writes every value
 * into its neighbour's column. The row assertions below read it back BY COLUMN
 * NAME, which is what makes such a drift a failure rather than a corruption.
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

    public function testEveryBufferedValueLandsUnderItsOwnColumn(): void
    {
        $logs = $this->service();
        $log = $this->log(11);
        $mappings = [['handle' => 'title', 'changed' => true, 'children' => null]];

        $logs->recordItem(
            $log,
            ItemAction::UPDATED,
            42,
            'abc',
            'saved',
            ['id'   => 'abc'],
            ['body' => 'Bad HTML'],
            ['title'],
            $mappings,
        );

        $this->assertSame([
            'logId'         => 11,
            'elementId'     => 42,
            'matchValue'    => 'abc',
            'action'        => ItemAction::UPDATED->value,
            'message'       => 'saved',
            'fieldErrors'   => '{"body":"Bad HTML"}',
            'changedFields' => '["title"]',
            'payload'       => '{"id":"abc"}',
            'mappings'      => '[{"handle":"title","changed":true,"children":null}]',
        ], $logs->bufferedRowByColumn(11));

        // The drill-down reads it straight back out of the column.
        $this->assertSame($mappings, json_decode($logs->bufferedRowByColumn(11)['mappings'], true));
    }

    public function testAnOversizedSnapshotIsDroppedRatherThanStored(): void
    {
        $logs = $this->service();
        $log = $this->log(12);

        // One outlier item must not push a whole page's batch insert past
        // max_allowed_packet — it just loses its drill-down detail.
        $oversized = [['handle' => 'body', 'rawValue' => str_repeat('a', $logs->mappingsMaxBytes())]];

        $logs->recordItem($log, ItemAction::UPDATED, 42, 'abc', mappings: $oversized);

        $this->assertNull($logs->bufferedRowByColumn(12)['mappings']);
    }

    public function testASnapshotWithInvalidUtf8SurvivesBySubstitution(): void
    {
        $logs = $this->service();
        $log = $this->log(21);

        // An element's stored content can carry bad bytes the feed never sent
        // (seen in the wild on a CKEditor field); losing every row over one bad
        // value is the wrong trade, so the bytes become U+FFFD instead.
        $tainted = [['handle' => 'body', 'currentValue' => "Ludwig \xC3caf\xE9", 'children' => null]];

        $logs->recordItem($log, ItemAction::UPDATED, 42, 'abc', mappings: $tainted);

        $stored = json_decode($logs->bufferedRowByColumn(21)['mappings'], true);

        $this->assertSame('body', $stored[0]['handle']);
        $this->assertStringContainsString("\u{FFFD}", $stored[0]['currentValue']);
    }

    public function testACallWithoutASnapshotStoresNull(): void
    {
        $logs = $this->service();
        $log = $this->log(13);

        $logs->recordItem($log, ItemAction::SKIPPED, null, 'abc', 'no update');

        $this->assertNull($logs->bufferedRowByColumn(13)['mappings']);
    }

    public function testAnEmptySnapshotIsStoredAsAnEmptyList(): void
    {
        $logs = $this->service();
        $log = $this->log(14);

        // "Presented, maps no fields" is not "nothing recorded": only the latter
        // may read as a missing snapshot in the drill-down.
        $logs->recordItem($log, ItemAction::UNCHANGED, 42, 'abc', mappings: []);

        $this->assertSame('[]', $logs->bufferedRowByColumn(14)['mappings']);
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

            /**
             * A buffered row keyed by the columns flush() would insert it under —
             * the alignment the batch insert depends on.
             *
             * @return array<string, mixed>
             */
            public function bufferedRowByColumn(int $logId, int $index = 0): array
            {
                return array_combine(self::ITEM_COLUMNS, $this->buffers[$logId]->rows()[$index]);
            }

            public function mappingsMaxBytes(): int
            {
                return self::MAPPINGS_MAX_BYTES;
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
