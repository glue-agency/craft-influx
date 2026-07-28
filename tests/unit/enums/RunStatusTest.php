<?php

namespace GlueAgency\Influx\Tests\unit\enums;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\RunStatus;

/**
 * Behaviour spec for {@see RunStatus} — the run-lifecycle vocabulary. The backed
 * values are what sits in the log record's `status` column, so they're pinned
 * literally here; everything else derives from the case.
 */
class RunStatusTest extends Unit
{
    public function testStoredValuesArePinned(): void
    {
        $this->assertSame(['running', 'ok', 'error'], array_map(
            static fn(RunStatus $case): string => $case->value,
            RunStatus::cases(),
        ));
    }

    public function testOnlyRunningIsLive(): void
    {
        $this->assertTrue(RunStatus::RUNNING->isLive());
        $this->assertFalse(RunStatus::OK->isLive());
        $this->assertFalse(RunStatus::ERROR->isLive());
    }

    public function testColorMapsToCraftStatusClasses(): void
    {
        $this->assertSame('pending', RunStatus::RUNNING->color());
        $this->assertSame('live', RunStatus::OK->color());
        $this->assertSame('expired', RunStatus::ERROR->color());
    }

    public function testLabelsAreTranslatedSourceStrings(): void
    {
        // No booted Craft, so Craft::t() hands the source string straight back.
        $this->assertSame('Running', RunStatus::RUNNING->label());
        $this->assertSame('OK', RunStatus::OK->label());
        $this->assertSame('Failed', RunStatus::ERROR->label());
    }

    public function testUnknownValueDoesNotResolve(): void
    {
        // The phantom 'pending' status was checked for in four places but never
        // written anywhere — a run is created as 'running'. It's not a case.
        $this->assertNull(RunStatus::tryFrom('pending'));
        $this->assertNull(RunStatus::tryFrom(''));
    }
}
