<?php

namespace GlueAgency\Influx\sync\run;

/**
 * Accumulates the pending log-item rows and counter deltas for ONE log record
 * so {@see \GlueAgency\Influx\services\LogsService} can write them in a single
 * batch insert plus a single counter update, instead of one INSERT + one
 * UPDATE per item. Bounds the write cost of a huge feed page to two queries.
 *
 * Plain value object — no Craft/DB knowledge. The service owns the fixed
 * column order the rows are built in (see LogsService::ITEM_COLUMNS) and the
 * flushing; this class only aggregates.
 *
 * Not intended for concurrent use: steps for a given log run strictly
 * sequentially, so a buffer never sees interleaved writers.
 */
class LogItemBuffer
{
    /**
     * Pending log-item rows, each a list of column values in the service's
     * fixed column order.
     *
     * @var list<list<mixed>>
     */
    protected array $rows = [];

    /**
     * Counter increments to apply to the log record on flush, keyed by column
     * name. A per-action counter column (itemsCreated, itemsUpdated, ...) comes
     * from {@see add()} when the action has one; `itemsSeen` comes from
     * {@see addSeen()} alone.
     *
     * @var array<string, int>
     */
    protected array $counterDeltas = [];

    /**
     * Append one row and bump the per-action counter it contributes to.
     * `$counterAttribute` is null for the ERROR action, which has no column of
     * its own.
     *
     * Deliberately does NOT touch `itemsSeen`: rows and feed items aren't the
     * same thing — the missing-elements sweep writes a row per element the feed
     * never mentioned — so the seen count is bumped per feed item by
     * {@see addSeen()} instead.
     *
     * @param list<mixed> $row Column values in the service's fixed order.
     */
    public function add(array $row, ?string $counterAttribute): void
    {
        $this->rows[] = $row;

        if ($counterAttribute !== null) {
            $this->counterDeltas[$counterAttribute] = ($this->counterDeltas[$counterAttribute] ?? 0) + 1;
        }
    }

    /**
     * Bump the `itemsSeen` delta by the number of feed items walked, without
     * writing a row — see {@see add()} for why the two are separate.
     */
    public function addSeen(int $count = 1): void
    {
        $this->counterDeltas['itemsSeen'] = ($this->counterDeltas['itemsSeen'] ?? 0) + $count;
    }

    /**
     * Whether there is nothing left to write. Pending counter deltas count:
     * {@see addSeen()} can leave a delta with no row behind it, and a flush that
     * skipped on rows alone would drop it.
     */
    public function isEmpty(): bool
    {
        return $this->rows === [] && $this->counterDeltas === [];
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * @return list<list<mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<string, int>
     */
    public function counterDeltas(): array
    {
        return $this->counterDeltas;
    }

    public function clear(): void
    {
        $this->rows = [];
        $this->counterDeltas = [];
    }
}
