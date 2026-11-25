<?php

namespace GlueAgency\Influx\Tests\unit\sync\run;

use Codeception\Test\Unit;
use GlueAgency\Influx\sync\run\SyncLock;
use yii\db\Exception as DbException;
use yii\mutex\Mutex;

/**
 * Spec for the run's sync lock — {@see SyncLock}.
 *
 * The regression class this pins: Craft's mutex is a database lock on a
 * connection the sync otherwise never touches, so a run longer than the
 * server's idle timeout (MySQL `wait_timeout`, 300 s on Combell) lost the
 * connection and the lock with it, and the release in the run's `finally` threw
 * "MySQL server has gone away", failing a run whose data was complete. The lock
 * must ping its connection while the run works, but no more than once per
 * interval, and its release must never fail the run.
 *
 * No Craft boot and no database: the mutex is an in-memory fake, the ping a
 * closure and the clock a hand-moved number.
 */
class SyncLockTest extends Unit
{
    protected float $now = 1000.0;

    protected int $pings = 0;

    protected bool $connectionAlive = true;

    public function testAPingWaitsForTheIntervalAndThenResetsIt(): void
    {
        $lock = $this->lock($this->mutex());
        $this->assertTrue($lock->acquire());

        $lock->beat();
        $this->now += SyncLock::HEARTBEAT_INTERVAL - 1;
        $lock->beat();
        $this->assertSame(0, $this->pings, 'no ping inside the first interval');

        $this->now += 1;
        $lock->beat();
        $this->assertSame(1, $this->pings);

        $this->now += SyncLock::HEARTBEAT_INTERVAL - 1;
        $lock->beat();
        $this->assertSame(1, $this->pings, 'the ping restarts the interval');

        $this->now += 1;
        $lock->beat();
        $this->assertSame(2, $this->pings);
    }

    public function testALongRunKeepsPingingWellInsideAnIdleTimeout(): void
    {
        $lock = $this->lock($this->mutex());
        $lock->acquire();

        // 30 minutes of items, one every 5 seconds.
        for ($i = 0; $i < 360; $i++) {
            $this->now += 5;
            $lock->beat();
        }

        $this->assertSame(30, $this->pings, 'one ping a minute: never 300 seconds without one');
        $this->assertFalse($lock->isLost());
    }

    public function testAFailedPingMarksTheLockLostAndStopsPinging(): void
    {
        $lock = $this->lock($this->mutex());
        $lock->acquire();
        $this->connectionAlive = false;

        $this->now += SyncLock::HEARTBEAT_INTERVAL;
        $lock->beat();
        $this->assertTrue($lock->isLost());
        $this->assertSame(1, $this->pings);

        $this->now += SyncLock::HEARTBEAT_INTERVAL;
        $lock->beat();
        $this->assertSame(1, $this->pings, 'a lost lock is not pinged again');
    }

    public function testNothingPingsWithoutADatabaseLockOrWithoutTheLock(): void
    {
        $withoutPing = new SyncLock($this->mutex(), 'influx:sync:test', null, fn(): float => $this->now);
        $withoutPing->acquire();
        $this->now += SyncLock::HEARTBEAT_INTERVAL * 10;
        $withoutPing->beat();

        $refused = $this->mutex();
        $refused->grant = false;
        $notHeld = $this->lock($refused);
        $this->assertFalse($notHeld->acquire());
        $this->now += SyncLock::HEARTBEAT_INTERVAL * 10;
        $notHeld->beat();

        $this->assertSame(0, $this->pings);
    }

    public function testReleaseReleasesOnceAndOnlyWhatItHeld(): void
    {
        $mutex = $this->mutex();
        $lock = $this->lock($mutex);
        $lock->acquire();

        $lock->release();
        $lock->release();
        $this->assertSame(['acquire:influx:sync:test', 'release:influx:sync:test'], $mutex->calls);
        $this->assertFalse($lock->isHeld());

        $refused = $this->mutex();
        $refused->grant = false;
        $notHeld = $this->lock($refused);
        $notHeld->acquire();
        $notHeld->release();
        $this->assertSame(['acquire:influx:sync:test'], $refused->calls, 'a lock it never got is not released');
    }

    public function testAReleaseOnADroppedConnectionDoesNotFailTheRun(): void
    {
        $mutex = $this->mutex();
        $mutex->releaseThrows = true;
        $lock = $this->lock($mutex);
        $lock->acquire();

        $lock->release();

        $this->assertFalse($lock->isHeld());
        $this->assertSame(['acquire:influx:sync:test', 'release:influx:sync:test'], $mutex->calls);
    }

    protected function lock(Mutex $mutex): SyncLock
    {
        return new SyncLock(
            $mutex,
            'influx:sync:test',
            function(): bool {
                $this->pings++;

                return $this->connectionAlive;
            },
            fn(): float => $this->now,
        );
    }

    protected function mutex(): Mutex
    {
        return new class(['autoRelease' => false]) extends Mutex {
            public bool $grant = true;

            public bool $releaseThrows = false;

            /** @var string[] */
            public array $calls = [];

            protected function acquireLock($name, $timeout = 0): bool
            {
                $this->calls[] = "acquire:$name";

                return $this->grant;
            }

            protected function releaseLock($name): bool
            {
                $this->calls[] = "release:$name";

                if ($this->releaseThrows) {
                    throw new DbException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
                }

                return true;
            }
        };
    }
}
