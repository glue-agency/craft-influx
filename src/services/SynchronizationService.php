<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use GlueAgency\Influx\data\PagedFeed;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\RunFailure;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\exceptions\FeedFetchException;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\models\OffsetPreset;
use GlueAgency\Influx\queue\jobs\BackupJob;
use GlueAgency\Influx\queue\jobs\SyncLinkJob;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\sync\item\ItemRunner;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\run\BatchState;
use GlueAgency\Influx\sync\run\MissingElementsSweeper;
use GlueAgency\Influx\sync\run\PageWalk;
use GlueAgency\Influx\sync\run\PageWalker;
use GlueAgency\Influx\sync\run\RunLifecycle;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\ElementTargetInterface;
use Throwable;

/**
 * Orchestrates the sync lifecycle for a link. Every step below is owned by a
 * collaborator under `src/sync/`, split by scope: `sync\run` holds the per-run
 * machinery (log lifecycle, page walking, the sweep, the resumable queue state),
 * `sync\item` the per-item pipeline, and `sync\` itself only the contexts both
 * levels thread through. This class decides which scopes a run touches, where the
 * lock and the log lifecycle go, and in which order those collaborators are
 * called:
 *
 *   1. Pre-run hooks — the cancellable before-event, fired once per run by
 *      {@see RunLifecycle::announce()} (the pre-run backup is taken by the trigger
 *      layer, not here).
 *   2. Scope fan-out — {@see syncScopes()} expands a run into one scope per
 *      configured site (or the single `[null]` scope of a no-site-endpoints link),
 *      each with its own log. An all-sites queue trigger fans out to one
 *      {@see SyncLinkJob} per scope.
 *   3. Page walking — {@see processSite()} walks a scope's pages synchronously,
 *      {@see batchStep()} walks one page per queue step; both fold each page
 *      through the same {@see PageWalker} into the same {@see PageWalk}
 *      accumulator, and both run each item through the same {@see ItemRunner}.
 *   4. The missing-elements sweep — {@see MissingElementsSweeper}, fired once per
 *      pass as soon as that scope's pages are exhausted.
 *   5. Post-run hooks — the log is finished (or failed) and the after-event fired,
 *      once per scope log, through {@see RunLifecycle}.
 *
 * THE EVENT CONSTANTS AND SENDER STAY HERE. All five events are part of the
 * plugin's documented API and third-party listeners bind them with
 * `Event::on(SynchronizationService::class, …)`, so the collaborators that fire
 * them hold this service and trigger through it rather than becoming senders of
 * their own.
 */
class SynchronizationService extends Component
{
    public const EVENT_BEFORE_SYNC_LINK = 'beforeSyncLink';
    public const EVENT_AFTER_SYNC_LINK = 'afterSyncLink';
    public const EVENT_BEFORE_ITEM = 'beforeItem';
    public const EVENT_AFTER_ITEM_MAPPING = 'afterItemMapping';
    public const EVENT_AFTER_ITEM = 'afterItem';

    /** The one start/finish/fail scaffold, and the link events. */
    protected RunLifecycle $lifecycle;

    /** One remote item: pipeline, item events, log row. */
    protected ItemRunner $itemRunner;

    /** One fetched page: the item loop, the flush, the per-item progress report. */
    protected PageWalker $pageWalker;

    /** The end-of-pass disable/delete sweep. */
    protected MissingElementsSweeper $sweeper;

    public function init(): void
    {
        parent::init();
        $this->lifecycle = new RunLifecycle($this);
        $this->itemRunner = new ItemRunner($this);
        $this->pageWalker = new PageWalker($this->itemRunner);
        $this->sweeper = new MissingElementsSweeper();
    }

    /**
     * Run a full link sync synchronously (console / per-element / CP-direct);
     * the queued, page-per-step path lives in {@see batchStep()}.
     *
     * The cancellable before-event fires once per run, so cancelling it cancels
     * every site. No backup is taken here — the trigger layer takes one before
     * this runs ({@see queueSync()}), leaving this path backup-agnostic. The
     * whole run is serialised under {@see syncLockKey()} so a concurrent run of
     * the same link can't race find-or-create into duplicate elements.
     *
     * ONE LOG PER SITE: an all-sites run over N configured sites produces N
     * logs, each with its `siteHandle` set and every counter/item/sweep row
     * (including its own per-pass missing-elements sweep) contained to that
     * site. A site-scoped run, or a link with no site endpoints, produces one
     * log (siteHandle carries the requested scope, or null).
     *
     * A site whose feed fails to fetch fails THAT site's log and the run
     * CONTINUES with the next site (per-site isolation, spelled out as
     * {@see RunFailure::FAIL_AND_CONTINUE}); a non-fetch failure inside a site's
     * processing does the same — that site's log closes as failed (never left
     * 'running') and the next site still runs.
     *
     * @param string|null $offset Key into $link->offset presets, applied as a query param.
     * @param string|null $siteHandle Restrict the run to a single configured
     * site; null runs every site the link is configured for.
     * @param callable|null $onProgress fn(int $seen, ?int $total): called once
     * per feed item with THIS site's running items-seen count and the feed's
     * reported total (null when it doesn't report one). Null for synchronous
     * runs that don't need it.
     * @return list<LogRecord> Every log produced — one per site. The console
     * caller ignores this (the queue-side path reports progress); it exists so
     * programmatic callers can inspect each site's outcome.
     * @throws InfluxException when a beforeSyncLink listener cancels the run,
     * when the requested site has no endpoint, or when the link's sync lock is
     * already held by another run.
     * @throws \Throwable for failures outside a site's own processing (opening
     * its log, or an afterSyncLink listener) — both fetch failures
     * ({@see FeedFetchException}) and processing failures are isolated to the
     * site's log and do not propagate.
     */
    public function syncLink(
        Link $link,
        ?string $offset = null,
        SyncTrigger $trigger = SyncTrigger::CONSOLE,
        ?string $siteHandle = null,
        ?callable $onProgress = null,
    ): array {
        $this->lifecycle->announce($link);

        $target = $this->resolveTarget($link);
        $this->requireSiteEndpoint($link, $siteHandle);
        $siteHandles = $this->syncScopes($link, $siteHandle);

        $preset = OffsetPreset::forLink($link, $offset);
        [$queryParams] = $preset?->resolve() ?? [[], null];

        $mutex = Craft::$app->getMutex();
        $lockKey = $this->syncLockKey($link);

        if (! $mutex->acquire($lockKey, 15)) {
            throw new InfluxException("Could not acquire the sync lock for link '{$link->handle}' — another run is already in progress.");
        }

        $logs = [];

        try {
            foreach ($siteHandles as $handle) {
                $log = $this->lifecycle->openLog($link, $trigger, $handle, $preset?->handle);

                $this->lifecycle->run(
                    $link,
                    $log,
                    function(LogRecord $log) use ($link, $target, $handle, $trigger, $preset, $queryParams, $onProgress): void {
                        $context = SyncContext::forSite($link, $target, $handle, $trigger, offsetHandle: $preset?->handle);
                        $this->processSite($context, $queryParams, $log, $onProgress);
                    },
                    RunFailure::FAIL_AND_CONTINUE,
                    "failed for site '" . ($handle ?? 'primary') . "'",
                );

                $logs[] = $log;
                $this->lifecycle->fireAfterSyncLink($link, $log);
            }
        } finally {
            $mutex->release($lockKey);
        }

        return $logs;
    }

    /**
     * Mutex key serialising runs of one link. Keyed on the handle (not the
     * site) so a per-site fan-out can't create the same canonical element
     * twice from two sites at once.
     */
    protected function syncLockKey(Link $link): string
    {
        return "influx:sync:{$link->handle}";
    }

    /**
     * Queue a link sync from a trigger (the CP button / endpoint). When the link
     * wants a pre-run DB backup, that's taken in its own {@see BackupJob} — so
     * the request returns instantly and the dump happens once for the whole
     * fan-out. When it doesn't, the per-site sync jobs are enqueued directly,
     * skipping that hop entirely.
     */
    public function queueSync(Link $link, ?string $offset, ?string $site, SyncTrigger $trigger): void
    {
        if ($link->backup) {
            Craft::$app->getQueue()->push(new BackupJob([
                'linkHandle' => $link->handle,
                'offset'     => $offset,
                'site'       => $site,
                'trigger'    => $trigger->value,
            ]));

            return;
        }

        $this->queueSyncJobs($link, $offset, $site, $trigger);
    }

    /**
     * Fan out the per-site sync jobs: one {@see SyncLinkJob} per scope
     * {@see syncScopes()} resolves. Called directly by {@see queueSync()} when
     * no pre-run backup is needed, and by {@see BackupJob} once its backup has
     * been taken.
     */
    public function queueSyncJobs(Link $link, ?string $offset, ?string $site, SyncTrigger $trigger): void
    {
        foreach ($this->syncScopes($link, $site) as $handle) {
            Craft::$app->getQueue()->push(new SyncLinkJob([
                'linkHandle' => $link->handle,
                'offset'     => $offset,
                'site'       => $handle,
                'trigger'    => $trigger->value,
            ]));
        }
    }

    /**
     * The scopes one run covers: the requested site alone, else every site the
     * link is configured for. THE one place "which sites does this run touch" is
     * decided — the synchronous fan-out, the queued fan-out and the CP's
     * "queued for N sites" message all read it from here, so they can't disagree
     * about how many runs a trigger produces.
     *
     * Defers to {@see Link::syncSiteHandles()} — the single owner of the "no
     * sites = primary site" rule — instead of re-deriving it. A link with
     * exactly ONE site endpoint must still resolve THAT handle: the unscoped
     * `[null]` scope would fetch the base endpoint (legitimately absent when
     * site endpoints exist) and sweep missing elements cross-site.
     *
     * Deliberately unvalidated, so it stays pure and unit-testable without a
     * Craft boot; validating a requested handle is {@see requireSiteEndpoint()}'s
     * job, applied by the paths that actually run a scope.
     *
     * @return list<string|null>
     */
    public function syncScopes(Link $link, ?string $site): array
    {
        return $site !== null ? [$site] : $link->syncSiteHandles();
    }

    /**
     * Reject a requested site the link has no endpoint for, before anything is
     * fetched or logged. Shared by the two paths that run a scope for real
     * ({@see syncLink()} and {@see batchStep()}); the queue fan-out doesn't need
     * it, since its callers validate before pushing and the step revalidates on
     * the way in.
     *
     * @throws InfluxException when the site isn't one of the link's endpoints.
     */
    protected function requireSiteEndpoint(Link $link, ?string $siteHandle): void
    {
        if ($siteHandle !== null && ! in_array($siteHandle, $link->siteHandles(), true)) {
            throw new InfluxException("Link '{$link->handle}' has no endpoint for site '{$siteHandle}'.");
        }
    }

    /**
     * Advance a queued, resumable run by one feed page. {@see SyncLinkJob}
     * calls this each step and re-queues itself with the returned state until
     * `done` — so one log spans this job's single scope while each page is its
     * own queue step (it survives worker timeouts; the synchronous
     * {@see syncLink()} path is left untouched). A fetch failure fails the run
     * and stops, as does a scope that no longer resolves
     * ({@see SyncContext::forSite()} throws when a configured site is gone —
     * treated exactly like a fetch failure); per-item failures still become
     * error rows and the run carries on, and a throw out of the closing
     * sweep/finish fails the log rather than leaving it 'running' forever.
     *
     * ONE SCOPE PER JOB: each job walks a single scope's pages — one configured
     * site (an all-sites run fans out to one job per site in the controller),
     * or the single `[null]` scope of a no-site-endpoints link. When the last
     * page is done it fires the single missing-elements sweep, closes the log,
     * and reports `done`. There is no cross-site advance.
     *
     * The state array is the queue payload's carried state and converts to a
     * {@see BatchState} on the way in and back on the way out — the accumulated
     * seen-set, unattributed-error count, first-page size and items-seen count
     * have to survive across steps, since the sweep and both halves of the
     * progress fraction are derived from them (see {@see BatchState} for why
     * they're spelled out in one place).
     *
     * Retry-safe by construction: the step runs under {@see syncLockKey()}, so
     * no two steps of the same link can race find-or-create into duplicate
     * elements, and a step that can't take the lock returns unprocessed for the
     * job to re-queue. The seen-set is a value-keyed map so a re-processed tail
     * can't double-count, and the page's rows flush in a `finally`
     * ({@see PageWalker}): a throw still persists what this step saved, leaving a
     * retried step only the un-flushed tail to redo.
     *
     * @param array{logId: ?int, cursorUrl: ?string, page: int, seenIds?: list<int>, unattributedErrors?: int, firstPageSize?: ?int, itemsSeen?: int} $state
     * @param callable|null $onProgress fn(int $seen, ?int $total)
     * @return array{logId: ?int, cursorUrl: ?string, page: int, seenIds: list<int>, unattributedErrors: int, firstPageSize: ?int, itemsSeen: int, done: bool}
     */
    public function batchStep(
        string $linkHandle,
        ?string $offset,
        SyncTrigger $trigger,
        ?string $requestedSite,
        array $state,
        ?callable $onProgress = null,
    ): array {
        $plugin = Influx::getInstance();
        $link = $plugin->links->getLinkByHandle($linkHandle);

        if (! $link) {
            throw new InfluxException("Cannot sync link '{$linkHandle}' — no link with that handle exists.");
        }

        $batch = BatchState::fromArray($state);
        $target = $this->resolveTarget($link);

        $this->requireSiteEndpoint($link, $requestedSite);

        $preset = OffsetPreset::forLink($link, $offset);
        [$queryParams] = $preset?->resolve() ?? [[], null];

        if ($batch->logId === null) {
            $this->lifecycle->announce($link);
            $log = $this->lifecycle->openLog($link, $trigger, $requestedSite, $preset?->handle);
            $batch->logId = $log->id;
        } else {
            $log = $this->lifecycle->reopenLog($batch->logId);
        }

        try {
            $context = SyncContext::forSite($link, $target, $requestedSite, $trigger, offsetHandle: $preset?->handle);
            $page = $plugin->data->page($link, $requestedSite, $batch->cursorUrl, $queryParams, $batch->page);
        } catch (Throwable $e) {
            $this->lifecycle->fail($log, $e->getMessage());
            $batch->done = true;

            return $batch->toArray();
        }

        $mutex = Craft::$app->getMutex();
        $lockKey = $this->syncLockKey($link);

        if (! $mutex->acquire($lockKey, 15)) {
            return $batch->toArray();
        }

        try {
            $this->pageWalker->walk($context, $page, $log, $batch->walk, $onProgress);
        } finally {
            $mutex->release($lockKey);
        }

        if ($page->nextUrl !== null) {
            if ($batch->page >= PagedFeed::MAX_PAGES) {
                $this->lifecycle->fail($log, 'Pagination exceeded ' . PagedFeed::MAX_PAGES . ' pages — aborting.');
                $batch->done = true;

                return $batch->toArray();
            }

            $batch->cursorUrl = $page->nextUrl;
            $batch->page++;

            return $batch->toArray();
        }

        $this->lifecycle->run(
            $link,
            $log,
            function(LogRecord $log) use ($context, $batch): void {
                $this->sweeper->sweep($context, $batch->walk->seenIds(), $batch->walk->unattributedErrors, $log);
            },
            RunFailure::FAIL_AND_CONTINUE,
            'failed during the missing-elements sweep',
            fireAfterSyncLink: true,
        );

        $batch->done = true;

        return $batch->toArray();
    }

    /**
     * Sync a single existing element from its link's itemEndpoint (the
     * per-entry "Sync from remote" button).
     *
     * The run always resolves to exactly one scope — the element's own site, or
     * null for a no-site-endpoints link ({@see elementSyncSites()}) — so it
     * takes the single handle that comes back. The single-resource response
     * carries the same envelope as the list feed, so it has to be unwrapped
     * through `rootNode` or every match path misses.
     *
     * Fires no link events: it isn't a link run, it's one element's refresh.
     * A failure re-throws ({@see RunFailure::RETHROW}) so the CP endpoint can
     * turn it into a message for the editor who pressed the button.
     */
    public function syncElement(Link $link, ElementInterface $element): LogRecord
    {
        $plugin = Influx::getInstance();
        $target = $this->resolveTarget($link);

        if (! ($matchAttr = $link->matchAttribute())) {
            throw new InfluxException("Link '{$link->handle}' has no match attribute.");
        }

        if (! $element->$matchAttr) {
            throw new InfluxException("Element #{$element->id} has no value on '{$matchAttr}'.");
        }

        $siteHandles = $this->elementSyncSites($link, $element);

        if ($siteHandles === []) {
            throw new InfluxException(
                "Link '{$link->handle}' has no endpoint for element #{$element->id}'s site.",
            );
        }

        $siteHandle = $siteHandles[0];
        $log = $this->lifecycle->openLog($link, SyncTrigger::ELEMENT, $siteHandle, null, $element->id);

        return $this->lifecycle->run(
            $link,
            $log,
            function(LogRecord $log) use ($plugin, $link, $target, $element, $siteHandle): void {
                $context = SyncContext::forSite($link, $target, $siteHandle, SyncTrigger::ELEMENT);
                $tokens = $plugin->endpointTokens->tokensForElement($link, $element, $siteHandle);

                $item = RemoteItem::fromItemResponse($plugin->data->fetchOne($link, $tokens), $link->rootNode);
                $plugin->logs->recordSeen($log);

                try {
                    $this->itemRunner->run($context, $item, $log);
                } catch (Throwable $e) {
                    $plugin->logs->recordItem($log, ItemAction::ERROR, $element->id, null, $e->getMessage(), $item->raw());
                }

                $plugin->cooldown->mark($link, $element);
            },
            RunFailure::RETHROW,
        );
    }

    /**
     * The site(s) a SINGLE-element "Sync from remote" runs. A link with no
     * per-site endpoints always runs the single primary scope (`[null]`).
     *
     * With per-site endpoints it runs ONLY the element's current site — the one
     * the editor triggered the sync from (the element is loaded in that site by
     * {@see \GlueAgency\Influx\controllers\SynchronizationController::actionElement}),
     * and only when the link is configured for it. Each site's elements are
     * owned by that site's own feed, so the other sites are synced from there,
     * not by reaching across from here.
     *
     * @return list<string|null>
     */
    protected function elementSyncSites(Link $link, ElementInterface $element): array
    {
        if ($link->siteHandles() === []) {
            return [null];
        }

        $siteHandle = Craft::$app->getSites()->getSiteById((int) $element->siteId)?->handle;

        return $siteHandle !== null && in_array($siteHandle, $link->siteHandles(), true)
            ? [$siteHandle]
            : [];
    }

    /**
     * Walk every page of the feed for one scope — one site, or the single [null]
     * scope of a no-site-endpoints link — and sweep once at the end. Pagination
     * mechanics (fetching, cycle guards, URL normalization) live in
     * {@see PagedFeed}; everything per-page (the item loop, the flush, the
     * progress report) lives in {@see PageWalker}, which the queued path calls
     * with the same accumulator so the two can't drift.
     *
     * The single {@see PageWalk} instance is what makes this pass different from a
     * queue step: it accumulates across every page, so the sweep sees the whole
     * scope's seen-set and the progress denominator is fixed on the first page.
     *
     * @throws FeedFetchException on fetch failures, paginator URL cycles, or
     * runaway pagination.
     */
    protected function processSite(SyncContext $context, array $queryParams, LogRecord $log, ?callable $onProgress = null): void
    {
        $walk = new PageWalk();

        foreach (Influx::getInstance()->data->pages($context->link, $context->siteHandle, $queryParams) as $page) {
            $this->pageWalker->walk($context, $page, $log, $walk, $onProgress);
        }

        $this->sweeper->sweep($context, $walk->seenIds(), $walk->unattributedErrors, $log);
    }

    protected function resolveTarget(Link $link): ElementTargetInterface
    {
        $target = Influx::getInstance()->targets->forLink($link);

        if (! $target) {
            throw new InfluxException("No element target registered for '{$link->elementType}'.");
        }

        return $target;
    }
}
