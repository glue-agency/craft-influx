<?php

namespace GlueAgency\Influx\sync\run;

use Craft;
use GlueAgency\Influx\enums\RunFailure;
use GlueAgency\Influx\events\SyncLinkEvent;
use GlueAgency\Influx\exceptions\FeedFetchException;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\services\SynchronizationService;
use Throwable;

/**
 * The ONE start/finish/fail scaffold every sync path runs inside, replacing the
 * three hand-rolled shapes the service used to carry (a re-throwing wrapper for
 * the single-element sync, an inline fail-and-continue per site, and a manual
 * start/reload/fail/finish for the queued path). A log opened here is always
 * closed — the failure mode that matters most is a log left on 'running' forever.
 *
 * The two failure policies are named ({@see RunFailure}) rather than copy-pasted,
 * because they are the real difference between the paths: a single-element sync
 * must surface its failure to the caller, while a per-site fan-out and the queued
 * path must record it and carry on.
 *
 * EVENT SENDER. `beforeSyncLink` / `afterSyncLink` are part of the plugin's
 * documented API and third-party listeners bind them with
 * `Event::on(SynchronizationService::class, …)`, so they must keep firing with
 * the SERVICE as sender. This class therefore holds the service and triggers
 * through it rather than becoming a sender of its own; the constants stay on the
 * service for the same reason.
 *
 * Event placement is the caller's, not this class's, because the paths genuinely
 * differ: the before-event fires ONCE PER RUN (a synchronous all-sites run fires
 * it once for every site it then opens a log for, so it can cancel the whole
 * run), while the after-event fires once per LOG — inside the guarded region on
 * the queued path (so a throw out of the closing sweep fails the log instead of
 * announcing it as finished) and unconditionally after it on the synchronous path
 * (so a failed site still reports its counters).
 */
class RunLifecycle
{
    /**
     * The service the link events are fired through — never to call back into
     * for sync work. See the class docblock: it exists only to keep the events'
     * sender identity intact.
     */
    protected SynchronizationService $service;

    public function __construct(SynchronizationService $service)
    {
        $this->service = $service;
    }

    /**
     * Fire the cancellable before-event for a run that is about to start. Called
     * once per run, so cancelling it cancels every site that run would cover.
     *
     * @throws InfluxException when a beforeSyncLink listener cancels the run.
     */
    public function announce(Link $link): void
    {
        $beforeEvent = new SyncLinkEvent(['link' => $link]);
        $this->service->trigger(SynchronizationService::EVENT_BEFORE_SYNC_LINK, $beforeEvent);

        if (! $beforeEvent->isValid) {
            throw new InfluxException("Link '{$link->handle}' run cancelled by a beforeSyncLink listener.");
        }
    }

    /**
     * Open the log for one scope. The pre-run backup is the trigger layer's job
     * ({@see SynchronizationService::queueSync()}), so nothing is dumped here.
     *
     * @param RunOrigin $origin What started this run and who asked for it,
     * recorded on the log so the viewer can name both.
     * @param string|null $siteHandle The site this run is scoped to (null = the
     * primary/unscoped scope), recorded on the log so the viewer can show which
     * site's endpoint was fetched.
     * @param string|null $offsetHandle The sliding-window preset the run was
     * triggered with (null = the full feed), recorded on the log.
     * @param int|null $elementId The resource a single-element run was triggered
     * for, recorded on the log so the viewer can name it.
     */
    public function openLog(
        Link $link,
        RunOrigin $origin,
        ?string $siteHandle = null,
        ?string $offsetHandle = null,
        ?int $elementId = null,
    ): LogRecord {
        return Influx::getInstance()->logs->start($link, $origin, $siteHandle, $offsetHandle, $elementId);
    }

    /**
     * Reload the log a resumed step is continuing. A vanished log is fatal rather
     * than silently re-opened: a second log would split one scope's rows and
     * counters across two runs.
     *
     * @throws InfluxException when the record is gone.
     */
    public function reopenLog(int $logId): LogRecord
    {
        $log = LogRecord::findOne($logId);

        if (! $log) {
            throw new InfluxException("Influx log #{$logId} vanished mid-run.");
        }

        return $log;
    }

    /**
     * Close a log as failed without running a body — the paths that fail before
     * (or instead of) any processing: a feed fetch that never returned a page, or
     * runaway pagination.
     */
    public function fail(LogRecord $log, string $message): void
    {
        Influx::getInstance()->logs->fail($log, $message);
    }

    /**
     * Fire EVENT_AFTER_SYNC_LINK for one finished log, carrying its site handle
     * and its own final counters. Fired once per site log — the one place the
     * after-event is assembled from a record.
     */
    public function fireAfterSyncLink(Link $link, LogRecord $log): void
    {
        $this->service->trigger(SynchronizationService::EVENT_AFTER_SYNC_LINK, new SyncLinkEvent([
            'link'           => $link,
            'siteHandle'     => $log->siteHandle,
            'itemsSeen'      => (int) $log->itemsSeen,
            'itemsCreated'   => (int) $log->itemsCreated,
            'itemsUpdated'   => (int) $log->itemsUpdated,
            'itemsUnchanged' => (int) $log->itemsUnchanged,
            'itemsSkipped'   => (int) $log->itemsSkipped,
            'itemsDeleted'   => (int) $log->itemsDeleted,
            'itemsDisabled'  => (int) $log->itemsDisabled,
        ]));
    }

    /**
     * Run `$body` under an already-open log and close that log exactly once:
     * finish on success, fail on a throw. Whether the throw then propagates is
     * `$onFailure`'s call.
     *
     * @param callable $body fn(LogRecord $log): void — the scope's actual work.
     * @param string|null $errorContext Phrase completing "Influx: link 'x' …:
     * message" in the Craft log, or null to log nothing there (the closed log is
     * signal enough). A {@see FeedFetchException} is never logged: an unreachable
     * or malformed feed is an operational fact already recorded on the log, not a
     * code fault worth an error trace.
     * @param bool $fireAfterSyncLink Fire the after-event inside the guarded
     * region, right after the log is finished — for callers whose after-event
     * must NOT fire when the body or the finish threw.
     * @throws \Throwable when $onFailure is {@see RunFailure::RETHROW}.
     */
    public function run(
        Link $link,
        LogRecord $log,
        callable $body,
        RunFailure $onFailure,
        ?string $errorContext = null,
        bool $fireAfterSyncLink = false,
    ): LogRecord {
        $logs = Influx::getInstance()->logs;

        try {
            $body($log);
            $logs->finish($log);

            if ($fireAfterSyncLink) {
                $this->fireAfterSyncLink($link, $log);
            }
        } catch (Throwable $e) {
            if ($errorContext !== null && ! $e instanceof FeedFetchException) {
                Craft::error("Influx: link '{$link->handle}' {$errorContext}: {$e->getMessage()}", __METHOD__);
            }

            $logs->fail($log, $e->getMessage());

            if ($onFailure === RunFailure::RETHROW) {
                throw $e;
            }
        }

        return $log;
    }
}
