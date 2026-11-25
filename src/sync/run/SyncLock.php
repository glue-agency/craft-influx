<?php

namespace GlueAgency\Influx\sync\run;

use Closure;
use Craft;
use Throwable;
use yii\mutex\DbMutex;
use yii\mutex\Mutex;

/**
 * A link's sync lock, kept alive for as long as the run holds it.
 *
 * Craft's mutex is a database lock (MySQL `GET_LOCK`, a Postgres advisory lock)
 * taken on a connection of its own (`db2` on Craft 5). A sync touches that
 * connection only to take and release the lock, so it sits idle for the whole
 * run, and once a run outlasts the server's idle timeout (MySQL's
 * `wait_timeout`, 300 seconds on some hosts) the server closes it. The lock goes
 * with the connection: a second run of the same link can start next to this one,
 * and the release at the end fails with "MySQL server has gone away", failing a
 * run whose data is complete. Whether that happens depended on the run: element
 * saves take short locks of their own on the same connection, so a run that saves
 * often kept it busy, and a run of mostly unchanged items didn't.
 *
 * {@see beat()} pings the lock's connection at most once per
 * {@see HEARTBEAT_INTERVAL}, so a working run keeps it open. When a ping finds the
 * connection already gone, the lock went with it: the connection is reopened,
 * so the release and the mutex's own release at shutdown run on a live connection
 * and simply find nothing to release, and a warning says the lock was lost. It
 * can't be taken back: the mutex still counts it as held and won't ask the
 * server again. {@see release()} never fails the run either way.
 *
 * Collaborators are injected so the behaviour is testable without a database;
 * {@see forKey()} builds one on Craft's mutex.
 */
class SyncLock
{
    /**
     * Seconds between pings: well under the shortest idle timeout a host is
     * likely to set, and rare enough to cost nothing.
     */
    public const HEARTBEAT_INTERVAL = 60;

    protected Mutex $mutex;

    protected string $key;

    /**
     * Pings the lock's connection: returns true when it answered, false when it
     * was gone (and has been reopened). Null when the mutex isn't a database lock,
     * which has no connection to lose.
     *
     * @var (Closure(): bool)|null
     */
    protected ?Closure $ping;

    /**
     * @var Closure(): float
     */
    protected Closure $clock;

    protected bool $held = false;

    protected bool $lost = false;

    protected float $lastBeat = 0.0;

    /**
     * @param (Closure(): bool)|null $ping
     * @param (Closure(): float)|null $clock
     */
    public function __construct(Mutex $mutex, string $key, ?Closure $ping = null, ?Closure $clock = null)
    {
        $this->mutex = $mutex;
        $this->key = $key;
        $this->ping = $ping;
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /**
     * The lock for a mutex key, on Craft's mutex. Craft wraps the actual lock in
     * `craft\mutex\Mutex::$mutex`; only a database lock gets a ping.
     */
    public static function forKey(string $key): self
    {
        $mutex = Craft::$app->getMutex();
        $inner = property_exists($mutex, 'mutex') && $mutex->mutex instanceof Mutex ? $mutex->mutex : $mutex;
        $ping = null;

        if ($inner instanceof DbMutex) {
            $ping = static function() use ($inner): bool {
                try {
                    $inner->db->createCommand('SELECT 1')->queryScalar();

                    return true;
                } catch (Throwable) {
                    // The server closed the connection, and the lock with it. Reopen it so
                    // the release and the mutex's shutdown release don't throw on it.
                    $inner->db->close();

                    try {
                        $inner->db->open();
                    } catch (Throwable) {
                        // Nothing more to do: the release is guarded too.
                    }

                    return false;
                }
            };
        }

        return new self($mutex, $key, $ping);
    }

    public function acquire(int $timeout = 15): bool
    {
        $this->held = $this->mutex->acquire($this->key, $timeout);
        $this->lost = false;
        $this->lastBeat = ($this->clock)();

        return $this->held;
    }

    /**
     * Keeps the lock's connection alive. Cheap to call per item: it pings at most
     * once per {@see HEARTBEAT_INTERVAL}.
     */
    public function beat(): void
    {
        if (! $this->held || $this->lost || $this->ping === null) {
            return;
        }

        $now = ($this->clock)();

        if ($now - $this->lastBeat < static::HEARTBEAT_INTERVAL) {
            return;
        }

        $this->lastBeat = $now;

        if (! ($this->ping)()) {
            $this->lost = true;
            Craft::warning("Influx: the sync lock '{$this->key}' lost its database connection, and the database released the lock with it. The run carries on, but another run of this link could have started next to it.", __METHOD__);
        }
    }

    /**
     * Releases the lock, and never throws: the run's data is saved by now, and a
     * lock the database already dropped with its connection is released anyway.
     */
    public function release(): void
    {
        if (! $this->held) {
            return;
        }

        $this->held = false;

        try {
            $this->mutex->release($this->key);
        } catch (Throwable $e) {
            Craft::warning("Influx: releasing the sync lock '{$this->key}' failed ({$e->getMessage()}); the database drops a lock with its connection, so it is released anyway.", __METHOD__);
        }
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    public function isLost(): bool
    {
        return $this->lost;
    }
}
