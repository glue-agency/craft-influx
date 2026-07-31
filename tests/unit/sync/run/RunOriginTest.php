<?php

namespace GlueAgency\Influx\Tests\unit\sync\run;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\sync\run\RunOrigin;

/**
 * Round-trip spec for a run's origin — {@see RunOrigin}.
 *
 * The regression class this pins: the user is captured once, in the controller,
 * and then has to survive every hop to the log — including a queued run's
 * re-push, where a dropped key means page two of a feed records a run nobody
 * triggered. {@see RunOrigin::payload()} / {@see RunOrigin::fromPayload()} are
 * the only place those key names exist, so this is the only place they can drift.
 *
 * No Craft boot: the value object only assigns and re-reads.
 */
class RunOriginTest extends Unit
{
    public function testThePayloadRoundTripsBothDimensions(): void
    {
        $payload = RunOrigin::cp(12)->payload();
        $origin = RunOrigin::fromPayload($payload['trigger'], $payload['userId'], SyncTrigger::QUEUE);

        $this->assertSame(SyncTrigger::CP, $origin->trigger);
        $this->assertSame(12, $origin->userId);
    }

    public function testThePayloadCarriesTheBackedTriggerValue(): void
    {
        $this->assertSame(['trigger' => 'element', 'userId' => 3], RunOrigin::element(3)->payload());
    }

    public function testAnUnknownStoredTriggerDegradesToTheFallback(): void
    {
        // A payload written by an older release (or hand-edited) must still run:
        // the label degrades, the run doesn't.
        $origin = RunOrigin::fromPayload('webhook', 7, SyncTrigger::QUEUE);

        $this->assertSame(SyncTrigger::QUEUE, $origin->trigger);
        $this->assertSame(7, $origin->userId);
    }

    public function testANullTriggerDegradesToTheFallback(): void
    {
        $this->assertSame(SyncTrigger::CP, RunOrigin::fromPayload(null, null, SyncTrigger::CP)->trigger);
    }

    public function testTheUserSurvivesBothAbsentAndPresent(): void
    {
        $this->assertNull(RunOrigin::fromPayload('cp', null, SyncTrigger::QUEUE)->userId);
        $this->assertSame(41, RunOrigin::fromPayload('cp', 41, SyncTrigger::QUEUE)->userId);
    }

    public function testEachFactorySetsTheTriggerItClaims(): void
    {
        $this->assertSame(SyncTrigger::CP, RunOrigin::cp(1)->trigger);
        $this->assertSame(SyncTrigger::ELEMENT, RunOrigin::element(1)->trigger);
        $this->assertSame(SyncTrigger::CONSOLE, RunOrigin::console()->trigger);
    }

    /**
     * A console run has no CP identity behind it — the factory takes no user, so
     * no caller can invent one.
     */
    public function testAConsoleRunIsAttributedToNobody(): void
    {
        $this->assertNull(RunOrigin::console()->userId);
    }
}
