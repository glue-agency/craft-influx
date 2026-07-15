<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\Db;
use GlueAgency\Influx\data\PagedFeed;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\events\SyncItemEvent;
use GlueAgency\Influx\events\SyncLinkEvent;
use GlueAgency\Influx\exceptions\FeedFetchException;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\models\OffsetPreset;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\sync\ItemProcessor;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\ElementTargetInterface;
use Throwable;

/**
 * Owns the full sync lifecycle for a link:
 *
 *   1. Pre-run hooks (the cancellable before-event; the pre-run backup is
 *      taken once by the trigger layer, not here).
 *   2. For each configured site (or the primary site if none configured),
 *      fetch the JSON payload, walk paginated pages, and process every item.
 *      Each site runs under its own log (an all-sites run fans out to one queue
 *      job per site).
 *   3. Per item: match-or-build the target element, apply mappings, check for
 *      changes, save (or skip), and log.
 *   4. The missing-elements sweep — disable/delete the elements this scope owns
 *      that the feed didn't mention. One pass resolves ONE policy
 *      ({@see perSitePolicy()}) and applies it in that same pass, once the
 *      scope's pages are exhausted, gated by a clean-pass guard (a pass with
 *      any item that failed without a resolvable element does NOT sweep, since
 *      the seen-set can't be trusted). Policy precedence:
 *      DELETE > DELETE_FOR_SITE > DISABLE > DISABLE_FOR_SITE. The global
 *      DELETE/DISABLE only ever resolve on a no-site-endpoints link
 *      ({@see Link::migrateProcessingForEndpointShape()} swaps them for the
 *      -for-site forms when site endpoints exist), so they sweep the single
 *      `[null]` scope unscoped; the -for-site pair sweeps scoped to the
 *      running site (see {@see sweepMissing()}).
 *   5. Post-run hooks (event, log finalisation) — once per site log.
 */
class SynchronizationService extends Component
{
    public const EVENT_BEFORE_SYNC_LINK = 'beforeSyncLink';
    public const EVENT_AFTER_SYNC_LINK = 'afterSyncLink';
    public const EVENT_BEFORE_ITEM = 'beforeItem';
    public const EVENT_AFTER_ITEM_MAPPING = 'afterItemMapping';
    public const EVENT_AFTER_ITEM = 'afterItem';

    /**
     * The shared per-item pipeline. Also used (dry-run) by
     * {@see InspectorService} — the logic exists exactly once.
     */
    protected ItemProcessor $itemProcessor;

    public function init(): void
    {
        parent::init();
        $this->itemProcessor = new ItemProcessor();
    }

    /**
     * Run a full link sync synchronously (console / per-element / CP-direct);
     * the queued, page-per-step path lives in {@see batchStep()}.
     *
     * ONE LOG PER SITE: an all-sites run over N configured sites produces N
     * logs, each with its `siteHandle` set and every counter/item/sweep row
     * (including its own per-pass missing-elements sweep) contained to that
     * site. A site-scoped run, or a link with no site endpoints, produces one
     * log (siteHandle carries the requested scope, or null).
     *
     * A site whose feed fails to fetch fails THAT site's log and the run
     * CONTINUES with the next site (per-site isolation); a non-fetch failure
     * still propagates.
     *
     * @param string|null $offset Key into $link->offset presets, applied as a query param.
     * @param string|null $siteHandle Restrict the run to a single configured
     * site; null runs every site the link is configured for.
     * @param callable|null $onProgress fn(int $seen, ?int $total): called once
     * per fetched page with THIS site's running items-seen count and the feed's
     * reported total (null when it doesn't report one). Null for synchronous
     * runs that don't need it.
     * @return list<LogRecord> Every log produced — one per site. The console
     * caller ignores this (the queue-side path reports progress); it exists so
     * programmatic callers can inspect each site's outcome.
     * @throws InfluxException when a beforeSyncLink listener cancels the run.
     * @throws \Throwable for non-fetch failures escaping a site's processing
     * (fetch failures are isolated per site via {@see FeedFetchException} and
     * do not propagate).
     */
    public function syncLink(
        Link $link,
        ?string $offset = null,
        SyncTrigger $trigger = SyncTrigger::CONSOLE,
        ?string $siteHandle = null,
        ?callable $onProgress = null,
    ): array {
        $plugin = Influx::getInstance();

        // The before-event fires once per run; cancelling it cancels every site
        $beforeEvent = new SyncLinkEvent(['link' => $link]);
        $this->trigger(self::EVENT_BEFORE_SYNC_LINK, $beforeEvent);

        if (! $beforeEvent->isValid) {
            throw new InfluxException("Link '{$link->handle}' run cancelled by a beforeSyncLink listener.");
        }

        // No backup here: the trigger layer takes one before this runs; syncLink is backup-agnostic

        $target = $this->resolveTarget($link);
        $siteHandles = $this->runSites($link, $siteHandle);

        $preset = OffsetPreset::forLink($link, $offset);
        [$queryParams] = $preset?->resolve() ?? [[], null];

        // Serialise against any other run of the same link so find-or-create can't race into duplicate elements
        $mutex = Craft::$app->getMutex();
        $lockKey = $this->syncLockKey($link);

        if (! $mutex->acquire($lockKey, 15)) {
            throw new InfluxException("Could not acquire the sync lock for link '{$link->handle}' — another run is already in progress.");
        }

        // Each site runs under its own log; a failure fails that site's log and the run continues (per-site isolation)
        $logs = [];

        try {
            foreach ($siteHandles as $handle) {
                $log = $plugin->logs->start($link, $trigger, $handle, $preset?->handle);

                try {
                    $context = SyncContext::forSite($link, $target, $handle, $trigger);
                    $this->processSite($context, $queryParams, $log, $onProgress);
                    $plugin->logs->finish($log);
                } catch (FeedFetchException $e) {
                    $plugin->logs->fail($log, $e->getMessage());
                } catch (Throwable $e) {
                    // A non-fetch failure still closes this site's log as failed (never left 'running') and the run goes on
                    Craft::error("Influx: link '{$link->handle}' failed for site '" . ($handle ?? 'primary') . "': {$e->getMessage()}", __METHOD__);
                    $plugin->logs->fail($log, $e->getMessage());
                }

                $logs[] = $log;
                $this->fireAfterSyncLink($link, $log);
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
     * Fire EVENT_AFTER_SYNC_LINK for one finished log, carrying its site handle
     * and its own final counters. Fired once per site log — the one place the
     * after-event is assembled from a record.
     */
    protected function fireAfterSyncLink(Link $link, LogRecord $log): void
    {
        $this->trigger(self::EVENT_AFTER_SYNC_LINK, new SyncLinkEvent([
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
     * Advance a queued, resumable run by one feed page. {@see SyncLinkJob}
     * calls this each step and re-queues itself with the returned state until
     * `done` — so one log spans this job's single scope while each page is its
     * own queue step (it survives worker timeouts; the synchronous
     * {@see syncLink()} path is left untouched). A fetch failure fails the run
     * and stops; per-item failures still become error rows and the run carries
     * on.
     *
     * ONE SCOPE PER JOB: each job walks a single scope's pages — one configured
     * site (an all-sites run fans out to one job per site in the controller),
     * or the single `[null]` scope of a no-site-endpoints link. When the last
     * page is done it fires the single missing-elements sweep, closes the log,
     * and reports `done`. There is no cross-site advance.
     *
     * The missing-elements sweep needs the scope's seen-set, but the set is
     * built one page per step — so `seenIds`/`unattributedErrors` ride the
     * state array, accumulated across steps and consumed once the last page is
     * done. The set rides the queue payload: fine for feeds up to tens of
     * thousands of ids; flag as a known bound (a feed far larger would bloat
     * the job row and should page the sweep differently).
     *
     * @param array{logId: ?int, cursorUrl: ?string, page: int, seenIds?: list<int>, unattributedErrors?: int} $state
     * @param callable|null $onProgress fn(int $seen, ?int $total)
     * @return array{logId: ?int, cursorUrl: ?string, page: int, seenIds: list<int>, unattributedErrors: int, done: bool}
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

        $state['done'] = false;
        $state['seenIds'] ??= [];
        $state['unattributedErrors'] ??= 0;
        $target = $this->resolveTarget($link);

        // Validate the requested scope (null = the no-site-endpoints scope; a handle must be one the link is configured for)
        if ($requestedSite !== null && ! in_array($requestedSite, $link->siteHandles(), true)) {
            throw new InfluxException("Link '{$link->handle}' has no endpoint for site '{$requestedSite}'.");
        }

        $preset = OffsetPreset::forLink($link, $offset);
        [$queryParams] = $preset?->resolve() ?? [[], null];

        // The first step opens the log; later steps reload the run in progress.
        if (($state['logId'] ?? null) === null) {
            $log = $this->startSync($link, $trigger, $requestedSite, $preset?->handle);
            $state['logId'] = $log->id;
        } else {
            $log = LogRecord::findOne($state['logId']);

            if (! $log) {
                throw new InfluxException("Influx log #{$state['logId']} vanished mid-run.");
            }
        }

        try {
            // forSite may throw when a configured site no longer resolves (M4); treat it like a fetch failure and fail this log
            $context = SyncContext::forSite($link, $target, $requestedSite, $trigger);
            $page = $plugin->data->page($link, $requestedSite, $state['cursorUrl'], $queryParams, $state['page']);
        } catch (Throwable $e) {
            // A page fetch (or scope resolution) failed — fail the run and stop.
            $plugin->logs->fail($log, $e->getMessage());
            $state['done'] = true;

            return $state;
        }

        // Dedupe the seen-set as a value-keyed map so a re-processed tail after a retried step can't double-count
        $seen = array_fill_keys($state['seenIds'], true);

        // Serialise against any other step of the same link so find-or-create can't race. If the lock isn't
        // acquired, leave this step unprocessed for the job to re-queue (idempotent — seenIds/creates carry across)
        $mutex = Craft::$app->getMutex();
        $lockKey = $this->syncLockKey($link);

        if (! $mutex->acquire($lockKey, 15)) {
            return $state;
        }

        // Flush at the page boundary in a finally, so a throw still persists rows already saved this step;
        // a retried step re-processes only the un-flushed tail
        try {
            foreach ($page->items as $item) {
                try {
                    $elementId = $this->processItem($context, $item, $log);

                    if ($elementId !== null) {
                        $seen[$elementId] = true;
                    }
                } catch (Throwable $e) {
                    $plugin->logs->recordItem($log, ItemAction::ERROR, null, null, $e->getMessage(), $item->raw());
                    $state['unattributedErrors']++;
                }
            }
        } finally {
            $mutex->release($lockKey);
            $plugin->logs->flush($log);
            $state['seenIds'] = array_keys($seen);
        }

        if ($onProgress !== null) {
            $total = $page->totalCount
                ?? ($page->pageCount !== null ? $page->pageCount * max(1, count($page->items)) : null);
            $onProgress((int) $log->itemsSeen, $total);
        }

        $nextUrl = $page->nextUrl;

        if ($nextUrl !== null) {
            if ($state['page'] >= PagedFeed::MAX_PAGES) {
                $plugin->logs->fail($log, 'Pagination exceeded ' . PagedFeed::MAX_PAGES . ' pages — aborting.');
                $state['done'] = true;

                return $state;
            }

            $state['cursorUrl'] = $nextUrl;
            $state['page']++;

            return $state;
        }

        // Scope finished: run the missing-elements sweep over the accumulated set, then close the log
        try {
            $this->sweepMissing($context, $state['seenIds'], $state['unattributedErrors'], $log);
            $this->finishSync($link, $log);
        } catch (Throwable $e) {
            // A non-fetch failure in the sweep/finish fails the log rather than leaving it 'running' forever
            Craft::error("Influx: link '{$link->handle}' failed during the missing-elements sweep: {$e->getMessage()}", __METHOD__);
            $plugin->logs->fail($log, $e->getMessage());
        }
        $state['done'] = true;

        return $state;
    }

    /**
     * Begin a run: fire the cancellable before-event and open the log. Shared
     * with {@see batchStep()}; {@see syncLink()} does this inline. The pre-run
     * backup is the trigger layer's job (see {@see \GlueAgency\Influx\queue\jobs\PrepareSyncJob}).
     *
     * @param string|null $siteHandle The site this run was scoped to (null =
     * an all-sites run), recorded on the log so the viewer can show which
     * site's endpoint was fetched.
     * @param string|null $offsetHandle The sliding-window preset the run was
     * triggered with (null = the full feed), recorded on the log.
     */
    protected function startSync(Link $link, SyncTrigger $trigger, ?string $siteHandle = null, ?string $offsetHandle = null): LogRecord
    {
        $beforeEvent = new SyncLinkEvent(['link' => $link]);
        $this->trigger(self::EVENT_BEFORE_SYNC_LINK, $beforeEvent);

        if (! $beforeEvent->isValid) {
            throw new InfluxException("Link '{$link->handle}' run cancelled by a beforeSyncLink listener.");
        }

        // No backup here: PrepareSyncJob takes one for the whole fan-out before enqueuing the per-site jobs

        return Influx::getInstance()->logs->start($link, $trigger, $siteHandle, $offsetHandle);
    }

    /**
     * Finalise a run: close the log and fire the after-event with the final
     * counters. Carries the log's `siteHandle` so the batch path's after-event
     * matches the synchronous path's ({@see fireAfterSyncLink()}) shape.
     */
    protected function finishSync(Link $link, LogRecord $log): void
    {
        Influx::getInstance()->logs->finish($log);

        $this->fireAfterSyncLink($link, $log);
    }

    /**
     * Sync a single existing element from its link's itemEndpoint (the
     * per-entry "Sync from remote" button).
     */
    public function syncElement(Link $link, ElementInterface $element): LogRecord
    {
        $plugin = Influx::getInstance();
        $target = $this->resolveTarget($link);

        if (! ($matchAttr = $link->matchAttribute())) {
            throw new InfluxException("Link '{$link->handle}' has no match attribute.");
        }

        $matchValue = $element->$matchAttr;

        if (! $matchValue) {
            throw new InfluxException("Element #{$element->id} has no value on '{$matchAttr}'.");
        }

        $siteHandles = $this->elementSyncSites($link, $element);

        if ($siteHandles === []) {
            throw new InfluxException(
                "Link '{$link->handle}' has no endpoint for element #{$element->id}'s site.",
            );
        }

        // A single-element sync resolves to one scope: the element's current site (or null for a no-site-endpoints link)
        $siteHandle = $siteHandles[0];

        return $this->runWithLog($link, SyncTrigger::ELEMENT, $element->id, $siteHandle, function(LogRecord $log) use ($plugin, $link, $target, $element, $siteHandle) {
            $context = SyncContext::forSite($link, $target, $siteHandle, SyncTrigger::ELEMENT);
            $tokens = $plugin->endpointTokens->tokensForElement($link, $element, $siteHandle);

            // Single-resource responses carry the same envelope as the list feed — unwrap via rootNode or every match path misses
            $item = RemoteItem::fromItemResponse($plugin->data->fetchOne($link, $tokens), $link->rootNode);

            try {
                $this->processItem($context, $item, $log);
            } catch (Throwable $e) {
                $plugin->logs->recordItem($log, ItemAction::ERROR, $element->id, null, $e->getMessage(), $item->raw());
            }

            $plugin->cooldown->mark($link, $element);
        });
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
     * Sites a run should cover: a single requested site (validated against the
     * link's configured endpoints), or every configured site when none is
     * requested. The one place "which sites does this run touch" is decided.
     *
     * @return list<string|null>
     */
    protected function runSites(Link $link, ?string $siteHandle): array
    {
        if ($siteHandle === null) {
            return $link->syncSiteHandles();
        }

        if (! in_array($siteHandle, $link->siteHandles(), true)) {
            throw new InfluxException("Link '{$link->handle}' has no endpoint for site '{$siteHandle}'.");
        }

        return [$siteHandle];
    }

    /**
     * Run a body within the log lifecycle: start a log, run the body (which
     * does the actual per-site work and receives the {@see LogRecord}), then
     * finish — or, if anything throws, fail the log and re-throw. The one place
     * the start/finish/fail/re-throw scaffold lives — {@see syncElement()}'s
     * lifecycle wrapper.
     *
     * @param int|null $elementId The resource a single-element run was
     * triggered for, recorded on the log so the viewer can name it.
     * @param string|null $siteHandle The site the run is scoped to (a
     * site-specific element sync), recorded on the log; null = primary.
     */
    protected function runWithLog(Link $link, SyncTrigger $trigger, ?int $elementId, ?string $siteHandle, callable $body): LogRecord
    {
        $logs = Influx::getInstance()->logs;
        $log = $logs->start($link, $trigger, $siteHandle, null, $elementId);

        try {
            $body($log);
            $logs->finish($log);
        } catch (Throwable $e) {
            $logs->fail($log, $e->getMessage());

            throw $e;
        }

        return $log;
    }

    /**
     * Walk every page of the feed for one site (or the single [null] scope of a
     * no-site-endpoints link) and process every item. Pagination mechanics
     * (fetching, cycle guards, URL normalization) live in
     * {@see \GlueAgency\Influx\data\PagedFeed}.
     *
     * The missing-elements sweep fires here once this scope's pages are
     * exhausted — one pass, one resolved policy ({@see sweepMissing()}). For a
     * site pass it's scoped to that site (disable / delete-for-site); for the
     * no-site-endpoints [null] pass a global delete sweeps cross-site.
     *
     * @throws FeedFetchException on fetch failures, paginator URL cycles, or
     * runaway pagination.
     */
    protected function processSite(SyncContext $context, array $queryParams, LogRecord $log, ?callable $onProgress = null): void
    {
        $plugin = Influx::getInstance();

        // The feed's reported total, captured once so the job can show a real % instead of the eased heuristic
        $total = null;
        $firstPageSize = null;

        // The sweep's inputs, accumulated across every page: seenIds protects present items from the sweep;
        // any unattributedError (an item that failed without a resolvable element) leaves the seen-set incomplete, so the sweep bails
        $seenIds = [];
        $unattributedErrors = 0;

        foreach ($plugin->data->pages($context->link, $context->siteHandle, $queryParams) as $page) {
            if ($firstPageSize === null) {
                $firstPageSize = count($page->items);
            }

            foreach ($page->items as $item) {
                // One bad item must not kill the run: per-item failures become an error row and the run goes on
                try {
                    $elementId = $this->processItem($context, $item, $log);

                    if ($elementId !== null) {
                        $seenIds[$elementId] = true;
                    }
                } catch (Throwable $e) {
                    $plugin->logs->recordItem($log, ItemAction::ERROR, null, null, $e->getMessage(), $item->raw());
                    $unattributedErrors++;
                }
            }

            // Flush per page so the DB rows/counters match what progress reports
            $plugin->logs->flush($log);

            // Report progress once per page (not per item) to keep queue writes bounded
            if ($onProgress !== null) {
                if ($total === null) {
                    $total = $page->totalCount
                        ?? ($page->pageCount !== null && $firstPageSize > 0 ? $page->pageCount * $firstPageSize : null);
                }

                $onProgress((int) $log->itemsSeen, $total);
            }
        }

        // Scope fully walked — run the missing-elements sweep over the elements this scope owns but its feed never mentioned
        $this->sweepMissing($context, array_keys($seenIds), $unattributedErrors, $log);
    }

    /**
     * Run one remote item through the shared pipeline, firing the item
     * events at the phase seams and logging the outcome. The logic itself
     * lives in {@see ItemProcessor} — this method only owns events + logs.
     *
     * Returns the id of the element this item resolved to (or null when it
     * matched none), regardless of the row outcome — SKIPPED and ERROR rows
     * included. The callers collect these ids as the run's "seen set": an
     * item PRESENT in the feed must never be swept as missing, whatever its
     * per-item result. A no-match item contributes null (nothing to protect).
     */
    protected function processItem(SyncContext $context, RemoteItem $item, LogRecord $log): ?int
    {
        $plugin = Influx::getInstance();
        $link = $context->link;

        $resolution = $this->itemProcessor->resolve($context, $item);

        // No-match items never reach listeners — there's nothing to act on.
        if ($resolution->decision !== SyncDecision::SKIP_NO_MATCH) {
            $beforeEvent = new SyncItemEvent([
                'link'       => $link,
                'item'       => $item->raw(),
                'element'    => $resolution->element,
                'siteHandle' => $context->siteHandle,
            ]);
            $this->trigger(self::EVENT_BEFORE_ITEM, $beforeEvent);

            if ($beforeEvent->skip) {
                $plugin->logs->recordItem($log, ItemAction::SKIPPED, $resolution->element?->id, $this->matchValueString($resolution->matchValue), null, $item->raw());

                return $resolution->element?->id;
            }

            // Let listeners swap in a different element; the decision is re-derived (a supplied element turns a no-create skip into an update)
            $resolution = $resolution->withElement($link, $beforeEvent->element);
        }

        $result = $this->itemProcessor->populate($context, $item, $resolution);

        if ($result->decision->isSkip()) {
            $plugin->logs->recordItem($log, ItemAction::SKIPPED, $result->element?->id, $this->matchValueString($result->matchValue), $result->message, $item->raw());

            return $result->element?->id;
        }

        $afterMappingEvent = new SyncItemEvent([
            'link'       => $link,
            'item'       => $item->raw(),
            'element'    => $result->element,
            'siteHandle' => $context->siteHandle,
        ]);
        $this->trigger(self::EVENT_AFTER_ITEM_MAPPING, $afterMappingEvent);

        $result = $this->itemProcessor->commit($context, $result);

        $plugin->logs->recordItem(
            $log,
            $result->action,
            $result->element?->id,
            $this->matchValueString($result->matchValue),
            $result->message,
            $item->raw(),
            $result->mappingErrors(),
            $result->changedFieldHandles(),
        );

        $this->fireAfterItem($link, $item->raw(), $result->element, $context->siteHandle, $result->action);

        return $result->element?->id;
    }

    /**
     * Stringify a match value for the log row, preserving null (an item with
     * no match value logs NULL, not an empty string). The one place the cast
     * happens, so every recordItem() call records it the same way.
     */
    protected function matchValueString(mixed $value): ?string
    {
        return $value !== null ? (string) $value : null;
    }

    /**
     * Record the single SKIPPED row a bailed sweep leaves behind (clean-pass
     * failure, delete-for-site on a siteless run, or a single-site run on a
     * multi-endpoint link). SKIPPED, never ERROR — an error would flip the
     * run's status; SKIPPED tells the user why nothing was swept. The one seam
     * every sweep guard routes through, so the log shape stays uniform.
     */
    protected function logSweepSkip(LogRecord $log, string $message): void
    {
        Influx::getInstance()->logs->recordItem($log, ItemAction::SKIPPED, null, null, $message);
    }

    /**
     * Emit the operator-facing warning a clean-pass bail leaves in the Craft
     * log (distinct from the {@see logSweepSkip()} row it also writes: the
     * SKIPPED row is for the run viewer, this is for the site log). Its own
     * seam so the sweep guards route both messages through one place.
     */
    protected function warnSweepSkipped(string $message): void
    {
        Craft::warning($message, __METHOD__);
    }

    /**
     * The single missing-elements action a link's `processing` flags call for,
     * or null when no missing-elements flag is set. Resolved once per pass and
     * applied in that same pass — there is no run-end second sweep.
     *
     * Precedence when several flags are set — the more destructive wins,
     * and a global delete supersedes the rest (there's no point disabling
     * elements you're about to delete outright):
     *
     *   DELETE > DELETE_FOR_SITE > DISABLE > DISABLE_FOR_SITE
     *
     * {@see Link::migrateProcessingForEndpointShape()} swaps global <-> -for-site
     * on save to match the endpoint shape, so in practice DELETE/DISABLE only
     * resolve on a no-site-endpoints link and the -for-site pair only on a
     * site-endpoints link — but the sweep keeps a defensive guard (D2) against
     * a hand-edited config that pairs global DELETE with site endpoints.
     *
     * Pure: reads only {@see Link::$processing}, so it's unit-tested without a
     * Craft boot.
     */
    protected function perSitePolicy(Link $link): ?ItemAction
    {
        if (in_array(Link::PROCESSING_DELETE, $link->processing, true)) {
            return ItemAction::DELETED;
        }

        if (in_array(Link::PROCESSING_DELETE_FOR_SITE, $link->processing, true)) {
            return ItemAction::DELETED_FOR_SITE;
        }

        if (in_array(Link::PROCESSING_DISABLE, $link->processing, true)) {
            return ItemAction::DISABLED;
        }

        if (in_array(Link::PROCESSING_DISABLE_FOR_SITE, $link->processing, true)) {
            return ItemAction::DISABLED_FOR_SITE;
        }

        return null;
    }

    /**
     * The single missing-elements sweep, run once per pass after that scope's
     * pages are exhausted — step 4 of the run lifecycle. Handles all four
     * policies ({@see perSitePolicy()} resolves exactly one):
     *   - DISABLED: no-site-endpoints pass (siteId null) → {@see ElementTargetInterface::disable()}.
     *     Stays adaptive — an un-migrated `disable` + site-endpoints config
     *     disables only that site's row rather than reaching across sites.
     *   - DISABLED_FOR_SITE: needs a site → {@see ElementTargetInterface::disableForSite()}
     *     (leave the element live in its other sites); a siteless pass logs one SKIPPED row.
     *   - DELETED_FOR_SITE: needs a site — a siteless pass logs one SKIPPED row.
     *   - DELETED: the whole element is destroyed. Only ever resolves on a
     *     no-site-endpoints link (save-time migration swaps DELETE → DELETE_FOR_SITE
     *     when site endpoints exist), so the pass is the single `[null]` scope
     *     and the delete is unscoped ({@see applyMissingAction()} routes DELETED → target->delete()).
     *
     * Safety first: a sweep acts on the COMPLEMENT of the seen-set, so it's
     * only safe when the seen-set is complete. If any item failed WITHOUT a
     * resolvable element ($unattributedErrors > 0) the set is untrustworthy —
     * an element that's actually in the feed but errored before it could be
     * matched would be swept as missing. So the sweep bails, warns, and logs
     * one SKIPPED row (not ERROR — an error would flip the run's status
     * perception; SKIPPED tells the user why nothing was swept).
     *
     * Defensive guard (D2): save-time migration swaps DELETE → DELETE_FOR_SITE
     * when a link has site endpoints, but a hand-edited config could still pair
     * global DELETE with them. Rather than cross-site delete off one site's
     * feed, such a link skips the delete, warns, and logs one SKIPPED row.
     * (Disable needs no equivalent hard guard — it's reversible, so DISABLED
     * downgrades to a per-site disable instead of skipping.)
     *
     * @param list<int> $seenIds Element ids present in this scope's feed.
     * @param int $unattributedErrors Items that failed with no resolvable
     * element — any at all disables the sweep.
     */
    protected function sweepMissing(SyncContext $context, array $seenIds, int $unattributedErrors, LogRecord $log): void
    {
        $link = $context->link;
        $policy = $this->perSitePolicy($link);

        if ($policy === null) {
            return;
        }

        // Clean-pass guard: never sweep off a seen-set we couldn't fully build.
        if ($unattributedErrors > 0) {
            $this->warnSweepSkipped(
                "Influx: missing-elements sweep skipped for link '{$link->handle}'"
                . ($context->siteHandle !== null ? " (site '{$context->siteHandle}')" : '')
                . " — {$unattributedErrors} item(s) failed without a resolvable element, "
                . 'so the missing-set cannot be trusted.',
            );
            $this->logSweepSkip(
                $log,
                "Missing-elements sweep skipped: {$unattributedErrors} item(s) failed without a resolvable element.",
            );

            return;
        }

        // D2 guard: a global delete must never fire on a link with site endpoints (it would nuke content other sites carry).
        // Runtime backstop against a hand-edited config
        if ($policy === ItemAction::DELETED && ! empty($link->getSiteEndpoints())) {
            $this->warnSweepSkipped(
                "Influx: missing-elements delete sweep skipped for link '{$link->handle}'"
                . ' — global delete is not allowed with site-specific endpoints (would delete '
                . 'cross-site off a single site\'s feed).',
            );
            $this->logSweepSkip(
                $log,
                'Missing-elements delete sweep skipped: global delete is not allowed with '
                . 'site-specific endpoints — use “delete for site”.',
            );

            return;
        }

        // The -for-site policies are meaningless without a site to scope to.
        if (in_array($policy, [ItemAction::DELETED_FOR_SITE, ItemAction::DISABLED_FOR_SITE], true) && $context->siteId === null) {
            $this->logSweepSkip(
                $log,
                "Missing-elements sweep skipped: {$policy->value} needs a site-scoped run.",
            );

            return;
        }

        $this->applySweep($context, $policy, $seenIds, $context->siteId, $log);
    }

    /**
     * The shared sweep body: build the target's candidate query, status-filter
     * it per policy, walk it in batches, and apply the policy action to each
     * element — logging a success row, an ERROR row on a failed/false save, or
     * an ERROR row on a thrown failure. {@see sweepMissing()} routes here for
     * all three policies, so the per-element loop, the false-vs-success
     * discipline, and the tail flush live in exactly one place.
     *
     * DISABLED only touches still-enabled elements (skip the churn of
     * re-disabling); the delete policies consider every status. A save that
     * returns false WITHOUT throwing (a validation failure that didn't persist)
     * is an ERROR row, never a success row — the log must not claim a
     * disable/delete that never landed.
     *
     * @param list<int> $seenIds Element ids to exclude from the candidate set.
     * @param int|null $siteId Site to scope the candidate query and the action
     * to, or null for a cross-site (`siteId('*')->unique()`) sweep.
     */
    protected function applySweep(SyncContext $context, ItemAction $policy, array $seenIds, ?int $siteId, LogRecord $log): void
    {
        $plugin = Influx::getInstance();
        $link = $context->link;

        $query = $context->target->missingElementsQuery($link, $seenIds, $siteId);

        if ($query === null) {
            return;
        }

        // Disable policies only touch still-enabled elements; delete policies consider every status
        $disablePolicy = in_array($policy, [ItemAction::DISABLED, ItemAction::DISABLED_FOR_SITE], true);
        $query->status($disablePolicy ? 'enabled' : null);

        $matchAttr = $link->matchAttribute();

        foreach (Db::batch($query, 100) as $elements) {
            foreach ($elements as $element) {
                $matchValue = $matchAttr ? $this->matchValueString($element->{$matchAttr} ?? null) : null;

                try {
                    // A false return means the save didn't persist — record an ERROR row, not a success row
                    if (! $this->applyMissingAction($context, $policy, $element)) {
                        $plugin->logs->recordItem(
                            $log,
                            ItemAction::ERROR,
                            $element->id,
                            $matchValue,
                            "Missing-elements {$policy->value} failed to save.",
                        );

                        continue;
                    }

                    $plugin->logs->recordItem(
                        $log,
                        $policy,
                        $element->id,
                        $matchValue,
                        'Missing from feed.',
                    );
                } catch (Throwable $e) {
                    $plugin->logs->recordItem($log, ItemAction::ERROR, $element->id, null, $e->getMessage());
                }
            }
        }

        // Flush the tail explicitly: the buffer auto-flushes at 100, but on the batchStep path nothing else
        // flushes before the state returns, so this is what persists the sweep's rows
        $plugin->logs->flush($log);
    }

    /**
     * Apply the resolved missing-action to one element. Kept apart from
     * {@see sweepMissing()} so the mode-per-policy dispatch reads as a single
     * expression. The site scope is read off the context: DISABLED on a site
     * run disables only that site; a global DISABLED disables the element.
     *
     * Returns the target call's boolean result: false means the save did NOT
     * persist (e.g. a validation error), which {@see sweepMissing()} turns into
     * an ERROR row instead of a false-positive success row. An unknown policy
     * returns false (nothing was applied — never log it as done).
     */
    protected function applyMissingAction(SyncContext $context, ItemAction $policy, ElementInterface $element): bool
    {
        $target = $context->target;

        return match ($policy) {
            ItemAction::DELETED           => $target->delete($element),
            ItemAction::DELETED_FOR_SITE  => $target->deleteForSite($element, (int) $context->siteId),
            ItemAction::DISABLED_FOR_SITE => $target->disableForSite($element, (int) $context->siteId),
            // DISABLED stays adaptive: an un-migrated `disable` + site-endpoints config disables just that site's
            // row rather than reaching across sites — disable is reversible, so the downgrade beats a skip
            ItemAction::DISABLED => $context->siteId !== null
                ? $target->disableForSite($element, $context->siteId)
                : $target->disable($element),
            default => false,
        };
    }

    protected function fireAfterItem(
        Link $link,
        array $item,
        ?ElementInterface $element,
        ?string $siteHandle,
        ItemAction $action,
    ): void {
        $afterEvent = new SyncItemEvent([
            'link'       => $link,
            'item'       => $item,
            'element'    => $element,
            'siteHandle' => $siteHandle,
            'action'     => $action->value,
        ]);
        $this->trigger(self::EVENT_AFTER_ITEM, $afterEvent);
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
