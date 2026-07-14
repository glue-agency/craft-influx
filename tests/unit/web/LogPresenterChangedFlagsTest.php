<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use GlueAgency\Influx\web\LogPresenter;

/**
 * Behaviour spec for {@see LogPresenter::overlayChangedFlags()}, which stamps
 * the run-time "which fields changed" flags back onto the drill-down's dry-run
 * mapping rows. The stored flags are authoritative — the re-inspection compares
 * against the element's LIVE state, so a successfully-updated item reads "no
 * change" on every row. Pure: the JSON decode + row overlay touch nothing but
 * the passed arrays, so no Craft boot is needed.
 */
class LogPresenterChangedFlagsTest extends Unit
{
    protected LogPresenter $presenter;

    protected function _before(): void
    {
        $this->presenter = new LogPresenter();
    }

    public function testStoredListMarksListedHandlesChanged(): void
    {
        $mappings = [
            ['handle' => 'title', 'changed' => false],
            ['handle' => 'summary', 'changed' => false],
            ['handle' => 'body', 'changed' => false],
        ];

        $result = $this->presenter->overlayChangedFlags($mappings, '["title","body"]');

        $this->assertTrue($result[0]['changed']);
        $this->assertFalse($result[1]['changed']);
        $this->assertTrue($result[2]['changed']);
    }

    public function testEmptyListMarksEveryRowUnchanged(): void
    {
        $mappings = [
            ['handle' => 'title', 'changed' => true],
            ['handle' => 'body', 'changed' => true],
        ];

        // `[]` is "compared, nothing changed" — every row false, none null.
        $result = $this->presenter->overlayChangedFlags($mappings, '[]');

        $this->assertFalse($result[0]['changed']);
        $this->assertFalse($result[1]['changed']);
    }

    public function testNullColumnResetsEveryRowToUnknown(): void
    {
        $mappings = [
            ['handle' => 'title', 'changed' => true],
            ['handle' => 'body', 'changed' => false],
        ];

        // Null column (no populate) → the viewer's "?" state, not a misleading
        // live recalculation.
        $result = $this->presenter->overlayChangedFlags($mappings, null);

        $this->assertNull($result[0]['changed']);
        $this->assertNull($result[1]['changed']);
    }

    public function testMalformedJsonResetsEveryRowToUnknown(): void
    {
        $mappings = [
            ['handle' => 'title', 'changed' => true],
        ];

        $this->assertNull($this->presenter->overlayChangedFlags($mappings, '{not json')[0]['changed']);
    }

    public function testNonArrayJsonResetsEveryRowToUnknown(): void
    {
        $mappings = [
            ['handle' => 'title', 'changed' => true],
        ];

        // A bare scalar decodes to a non-array; treated as "unknown".
        $this->assertNull($this->presenter->overlayChangedFlags($mappings, '42')[0]['changed']);
    }

    public function testEmptyMappingsReturnsEmpty(): void
    {
        $this->assertSame([], $this->presenter->overlayChangedFlags([], '["title"]'));
    }
}
