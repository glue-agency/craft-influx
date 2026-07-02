<?php

namespace GlueAgency\Influx\sync;

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
     * name. Always carries an `itemsSeen` delta; a per-action counter column
     * (itemsCreated, itemsUpdated, ...) is added when the action has one.
     *
     * @var array<string, int>
     */
    protected array $counterDeltas = [];

    /**
     * Append one row and bump the counter deltas it contributes to. Every add
     * bumps `itemsSeen`; `$counterAttribute` (null for the ERROR action, which
     * still counts toward itemsSeen) bumps its own column when non-null —
     * mirroring LogsService::recordItem's original per-item counter logic.
     *
     * @param list<mixed> $row Column values in the service's fixed order.
     */
    public function add(array $row, ?string $counterAttribute): void
    {
        $this->rows[] = $row;

        $this->counterDeltas['itemsSeen'] = ($this->counterDeltas['itemsSeen'] ?? 0) + 1;

        if ($counterAttribute !== null) {
            $this->counterDeltas[$counterAttribute] = ($this->counterDeltas[$counterAttribute] ?? 0) + 1;
        }
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
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
