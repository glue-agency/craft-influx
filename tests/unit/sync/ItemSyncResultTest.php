<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\sync\ItemSyncResult;
use GlueAgency\Influx\sync\MappingResult;

/**
 * Behaviour spec for {@see ItemSyncResult::changedFieldHandles()} — the run-time
 * "which fields changed" list the log drill-down persists. Pure: the result is
 * a plain value object over {@see MappingResult} rows, so no Craft boot needed.
 */
class ItemSyncResultTest extends Unit
{
    public function testReturnsHandlesOfChangedRowsOnly(): void
    {
        $result = $this->resultWith([
            $this->mapping('title', true),
            $this->mapping('summary', false),
            $this->mapping('body', true),
        ]);

        $this->assertSame(['title', 'body'], $result->changedFieldHandles());
    }

    public function testEmptyListWhenNothingChanged(): void
    {
        $result = $this->resultWith([
            $this->mapping('title', false),
            $this->mapping('summary', false),
        ]);

        // Compared, nothing changed — an empty list, NOT null. That distinction
        // is what lets the drill-down tell "unchanged" from "unknown".
        $this->assertSame([], $result->changedFieldHandles());
    }

    public function testErrorRowsNeverCountAsChanged(): void
    {
        // A field that threw has changed === null (never true), so it's
        // excluded — mirroring ItemProcessor::populate()'s own aggregate.
        $result = $this->resultWith([
            $this->mapping('title', true),
            $this->mapping('body', null),
        ]);

        $this->assertSame(['title'], $result->changedFieldHandles());
    }

    public function testNullWhenNoMappingResults(): void
    {
        // No mappings at all (a sweep row, or an item that errored before
        // populate ran) — unknown, distinct from an empty list.
        $this->assertNull($this->resultWith([])->changedFieldHandles());
    }

    /** @param list<MappingResult> $mappingResults */
    protected function resultWith(array $mappingResults): ItemSyncResult
    {
        return new ItemSyncResult(
            SyncDecision::UPDATE,
            ItemAction::UPDATED,
            'abc',
            null,
            false,
            $mappingResults !== [],
            $mappingResults,
        );
    }

    protected function mapping(string $handle, ?bool $changed): MappingResult
    {
        return new MappingResult(
            handle: $handle,
            node: null,
            default: null,
            native: false,
            rawValue: null,
            changed: $changed,
        );
    }
}
