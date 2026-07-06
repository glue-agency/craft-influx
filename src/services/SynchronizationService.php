<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
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
 *   1. Pre-run hooks (event, optional backup).
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
     * {@see DebugService} — the logic exists exactly once.
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

        // The before-event fires ONCE for the whole run; cancelling it cancels
        // every site.
        $beforeEvent = new SyncLinkEvent(['link' => $link]);
        $this->trigger(self::EVENT_BEFORE_SYNC_LINK, $beforeEvent);

        if (! $beforeEvent->isValid) {
            throw new InfluxException("Link '{$link->handle}' run cancelled by a beforeSyncLink listener.");
        }

        $plugin->backup->backupForLink($link);

        $target = $this->resolveTarget($link);
        $siteHandles = $this->runSites($link, $siteHandle);

        $preset = OffsetPreset::forLink($link, $offset);
        [$queryParams] = $preset?->resolve() ?? [[], null];

        // Each site runs under its OWN log with its OWN per-pass sweep — a
        // fetch failure fails THIS site's log and the run CONTINUES with the
        // next site (per-site isolation). A non-fetch throw still propagates.
        $logs = [];

        foreach ($siteHandles as $handle) {
            $context = SyncContext::forSite($link, $target, $handle, $trigger);
            $log = $plugin->logs->start($link, $trigger, $handle, $preset?->handle);

            try {
                $this->processSite($context, $queryParams, $log, $onProgress);
                $plugin->logs->finish($log);
            } catch (FeedFetchException $e) {
                $plugin->logs->fail($log, $e->getMessage());
            }

            $logs[] = $log;
            $this->fireAfterSyncLink($link, $log);
        }

        return $logs;
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

        // Validate the requested scope (null = the single no-site-endpoints
        // scope; a handle must be one the link is configured for).
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

        $context = SyncContext::forSite($link, $target, $requestedSite, $trigger);

        try {
            $page = $plugin->data->page($link, $requestedSite, $state['cursorUrl'], $queryParams, $state['page']);
        } catch (Throwable $e) {
            // A page fetch failed — fail the run and stop (no retry).
            $plugin->logs->fail($log, $e->getMessage());
            $state['done'] = true;

            return $state;
        }

        // Dedupe the running seen-set as a value-keyed map (list re-derived on
        // write-back), so a re-processed tail after a retried step can't
        // double-count.
        $seen = array_fill_keys($state['seenIds'], true);

        // Flush at the page boundary — and in a finally so a throw escaping the
        // loop still persists rows for items already saved this step. A retried
        // queue step then re-processes only the un-flushed tail (and re-folds
        // its ids into the seen-set, hence the dedupe above).
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

        // Scope finished. Run the single missing-elements sweep over the set
        // accumulated across this scope's steps, then close the log — the job
        // walks no further sites.
        $this->sweepMissing($context, $state['seenIds'], $state['unattributedErrors'], $log);
        $this->finishSync($link, $log);
        $state['done'] = true;

        return $state;
    }

    /**
     * Begin a run: fire the cancellable before-event, take the backup, open the
     * log. Shared with {@see batchStep()}; {@see syncLink()} does this inline.
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

        Influx::getInstance()->backup->backupForLink($link);

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
                "Element #{$element->id} doesn't exist in any of the sites link '{$link->handle}' is configured for.",
            );
        }

        return $this->runWithLog($link, SyncTrigger::ELEMENT, $element->id, function(LogRecord $log) use ($plugin, $link, $target, $element, $siteHandles) {
            foreach ($siteHandles as $siteHandle) {
                $context = SyncContext::forSite($link, $target, $siteHandle, SyncTrigger::ELEMENT);
                $tokens = $plugin->endpointTokens->tokensForElement($link, $element, $siteHandle);

                // Single-resource responses carry the same envelope as the
                // list feed — unwrap via the link's rootNode or every match
                // path misses and the item logs as an inexplicable skip.
                $item = RemoteItem::fromItemResponse($plugin->data->fetchOne($link, $tokens), $link->rootNode);

                try {
                    $this->processItem($context, $item, $log);
                } catch (Throwable $e) {
                    $plugin->logs->recordItem($log, ItemAction::ERROR, $element->id, null, $e->getMessage(), $item->raw());
                }
            }

            $plugin->cooldown->mark($link, $element);
        });
    }

    /**
     * The subset of the link's sync sites a SINGLE-element sync should run:
     * only the sites the element actually has a site row in. A per-site-
     * endpoints link owns each site's elements through that site's feed, so
     * running the other sites' passes would either skip (update-only) or —
     * worse, with create enabled — clone the resource into a site whose feed
     * never mentioned it. The `[null]` scope of a no-site-endpoints link
     * always passes through.
     *
     * @return list<string|null>
     */
    protected function elementSyncSites(Link $link, ElementInterface $element): array
    {
        $siteIds = array_map(
            static fn($id): int => (int) $id,
            (new Query())
                ->select(['siteId'])
                ->from(CraftTable::ELEMENTS_SITES)
                ->where(['elementId' => $element->id])
                ->column(),
        );

        $handles = [];

        foreach ($link->syncSiteHandles() as $siteHandle) {
            if ($siteHandle === null) {
                $handles[] = null;

                continue;
            }

            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);

            if ($site && in_array((int) $site->id, $siteIds, true)) {
                $handles[] = $siteHandle;
            }
        }

        return $handles;
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
     */
    protected function runWithLog(Link $link, SyncTrigger $trigger, ?int $elementId, callable $body): LogRecord
    {
        $logs = Influx::getInstance()->logs;
        $log = $logs->start($link, $trigger, null, null, $elementId);

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

        // The feed's reported total (via totalCountNode, or pageCount × the
        // first page's size) — constant across pages, captured once so the
        // job can show a real % instead of the eased heuristic.
        $total = null;
        $firstPageSize = null;

        // The sweep's inputs, accumulated across every page of this site.
        // seenIds protects present items from being swept; unattributedErrors
        // is the count of items that failed WITHOUT a resolvable element — any
        // such failure means the seen-set is incomplete, so the sweep bails.
        $seenIds = [];
        $unattributedErrors = 0;

        foreach ($plugin->data->pages($context->link, $context->siteHandle, $queryParams) as $page) {
            if ($firstPageSize === null) {
                $firstPageSize = count($page->items);
            }

            foreach ($page->items as $item) {
                // One bad item must not kill the run — transport/config
                // errors abort (they throw from the pages() iterator), but
                // per-item failures become an error row and the run goes on.
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

            // Flush per page so the DB rows/counters match what progress
            // reports (progress reads the live in-memory itemsSeen).
            $plugin->logs->flush($log);

            // Report progress once per page (not per item) to keep the queue
            // writes bounded; itemsSeen is the cumulative count across sites.
            if ($onProgress !== null) {
                if ($total === null) {
                    $total = $page->totalCount
                        ?? ($page->pageCount !== null && $firstPageSize > 0 ? $page->pageCount * $firstPageSize : null);
                }

                $onProgress((int) $log->itemsSeen, $total);
            }
        }

        // Scope fully walked — run the single missing-elements sweep over the
        // elements this scope owns that its feed never mentioned. One pass, one
        // resolved policy. flush() inside sweepMissing() persists its rows.
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

            // Allow listeners to swap in a different element. The decision is
            // re-derived: handing us an element turns a no-create skip into
            // an update.
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

        // D2 guard: a global delete must never fire on a link with site
        // endpoints — deleting off one site's feed would nuke content the other
        // sites still carry. Validation forbids the combo; this is the runtime
        // backstop against a hand-edited config.
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

        // The disable policies only touch still-enabled elements (skip the
        // churn of re-disabling); the delete policies consider every status.
        $disablePolicy = in_array($policy, [ItemAction::DISABLED, ItemAction::DISABLED_FOR_SITE], true);
        $query->status($disablePolicy ? 'enabled' : null);

        $matchAttr = $link->matchAttribute();

        foreach ($query->batch(100) as $elements) {
            foreach ($elements as $element) {
                $matchValue = $matchAttr ? $this->matchValueString($element->{$matchAttr} ?? null) : null;

                try {
                    // A false return means the save didn't persist (validation,
                    // etc.) — record an ERROR row, NOT a success row, so the log
                    // never claims a disable/delete that never happened.
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

        // The sweep can add hundreds of rows; the buffer auto-flushes at 100,
        // but finish the tail explicitly. On the synchronous path finish()
        // flushes again (harmless — the buffer is then empty); on the
        // batchStep path nothing else flushes before the state returns, so
        // this is the flush that persists the sweep's rows.
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
            // DISABLED stays adaptive: post-migration it only runs on a
            // no-site pass (global disable), but an un-migrated `disable` +
            // site-endpoints config still disables just that site's row here
            // rather than reaching across sites — disable is reversible, so
            // (unlike the DELETED D2 guard) the safe downgrade beats a skip.
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
