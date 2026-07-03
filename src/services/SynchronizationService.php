<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
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
 *   3. Per item: match-or-build the target element, apply mappings, check for
 *      changes, save (or skip), and log.
 *   4. The missing-elements sweep — disable/delete the elements this link owns
 *      that the feed didn't mention. Governed by the link's `processing` flags
 *      and gated by a clean-pass guard (a run with any item that failed without
 *      a resolvable element does NOT sweep, since the seen-set can't be
 *      trusted). Its timing depends on the policy:
 *        - PER SITE (disable / delete-for-site): once each site's pages are
 *          exhausted, scoped to that site — an element missing from one site's
 *          feed is disabled/removed FOR that site only (see {@see sweepMissing()}).
 *        - ONCE PER RUN (global delete): after the LAST site, over the union of
 *          every site's seen-set, unscoped — an element is deleted entirely
 *          only when NO site's feed mentioned it (see {@see sweepMissingForRun()}).
 *   5. Post-run hooks (event, log finalisation).
 */
class SynchronizationService extends Component
{
    public const EVENT_BEFORE_SYNC_LINK = 'beforeSyncLink';
    public const EVENT_AFTER_SYNC_LINK = 'afterSyncLink';
    public const EVENT_BEFORE_ITEM = 'beforeItem';
    public const EVENT_AFTER_ITEM_MAPPING = 'afterItemMapping';
    public const EVENT_AFTER_ITEM = 'afterItem';

    /**
     * @deprecated The endpoint-token machinery moved to
     * {@see EndpointTokensService} — attach listeners there. These triggers
     * no longer fire from this class.
     */
    public const EVENT_REGISTER_ENDPOINT_TOKENS = 'registerEndpointTokens';
    /**
     * @deprecated See {@see EndpointTokensService::EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS}.
     */
    public const EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS = 'registerEndpointTokenSuggestions';

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
     * @param string|null $offset Key into $link->offset presets, applied as a query param.
     * @param string|null $siteHandle Restrict the run to a single configured
     * site; null runs every site the link is configured for.
     * @param callable|null $onProgress fn(int $seen, ?int $total): called once
     * per fetched page with the running items-seen count and the feed's
     * reported total (null when it doesn't report one). Null for synchronous
     * runs that don't need it.
     */
    public function syncLink(
        Link $link,
        ?string $offset = null,
        SyncTrigger $trigger = SyncTrigger::CONSOLE,
        ?string $siteHandle = null,
        ?callable $onProgress = null,
    ): LogRecord {
        $plugin = Influx::getInstance();

        $beforeEvent = new SyncLinkEvent(['link' => $link]);
        $this->trigger(self::EVENT_BEFORE_SYNC_LINK, $beforeEvent);

        if (! $beforeEvent->isValid) {
            throw new InfluxException("Link '{$link->handle}' run cancelled by a beforeSyncLink listener.");
        }

        $plugin->backup->backupForLink($link);

        $target = $this->resolveTarget($link);
        $siteHandles = $this->runSites($link, $siteHandle);

        [$queryParams] = OffsetPreset::forLink($link, $offset)?->resolve() ?? [[], null];

        $log = $this->runWithLog($link, $trigger, function(LogRecord $log) use ($link, $target, $trigger, $queryParams, $siteHandles, $siteHandle, $onProgress) {
            // The union of every site's seen-set and the run's total count of
            // unattributed errors — the inputs to the run-end global-delete
            // sweep, which can only fire once every site's feed has been read
            // (an element is deleted only when NO site mentioned it).
            $runSeen = [];
            $runUnattributedErrors = 0;
            $lastContext = null;

            foreach ($siteHandles as $handle) {
                $context = SyncContext::forSite($link, $target, $handle, $trigger);
                $lastContext = $context;

                $siteResult = $this->processSite($context, $queryParams, $log, $onProgress);

                foreach ($siteResult['seenIds'] as $id) {
                    $runSeen[$id] = true;
                }
                $runUnattributedErrors += $siteResult['unattributedErrors'];
            }

            // Global delete sweeps once, after the last site, over the union.
            if ($lastContext !== null) {
                $this->sweepMissingForRun($lastContext, array_keys($runSeen), $runUnattributedErrors, $log, $siteHandle);
            }
        }, $siteHandle);

        $afterEvent = new SyncLinkEvent([
            'link'           => $link,
            'itemsSeen'      => (int) $log->itemsSeen,
            'itemsCreated'   => (int) $log->itemsCreated,
            'itemsUpdated'   => (int) $log->itemsUpdated,
            'itemsUnchanged' => (int) $log->itemsUnchanged,
            'itemsSkipped'   => (int) $log->itemsSkipped,
            'itemsDeleted'   => (int) $log->itemsDeleted,
            'itemsDisabled'  => (int) $log->itemsDisabled,
        ]);
        $this->trigger(self::EVENT_AFTER_SYNC_LINK, $afterEvent);

        return $log;
    }

    /**
     * Advance a queued, resumable run by one feed page. {@see SyncLinkJob}
     * calls this each step and re-queues itself with the returned state until
     * `done` — so one log spans the whole run while each page is its own queue
     * step (it survives worker timeouts; the synchronous {@see syncLink()} path
     * is left untouched). A fetch failure fails the run and stops; per-item
     * failures still become error rows and the run carries on.
     *
     * The missing-elements sweep needs the seen-set, but the set is built one
     * page per step — so it rides the state array, accumulated across steps.
     * The per-site and run-end sweeps compose and need DIFFERENT sets, so two
     * pairs ride the state:
     *   - `seenIds` / `unattributedErrors` — this site's set, feeding the
     *     PER-SITE sweep (disable / delete-for-site). Consumed once the site's
     *     last page is done, then reset so the next site starts clean.
     *   - `runSeenIds` / `runUnattributedErrors` — the UNION across every site,
     *     feeding the RUN-END global-delete sweep (an element is deleted only
     *     when NO site's feed mentioned it). NEVER reset mid-run; only
     *     accumulated when the link carries the DELETE flag ({@see runPolicy()})
     *     — disable-only links skip it so the payload doesn't bloat.
     * The run set rides the queue payload: fine for feeds up to tens of
     * thousands of ids; flag as a known bound (a feed far larger than that
     * would bloat the job row and should page the sweep differently — and the
     * global-delete union spans every site, so it's the larger case).
     *
     * @param array{logId: ?int, siteIndex: int, cursorUrl: ?string, page: int, seenIds?: list<int>, unattributedErrors?: int, runSeenIds?: list<int>, runUnattributedErrors?: int} $state
     * @param callable|null $onProgress fn(int $seen, ?int $total)
     * @return array{logId: ?int, siteIndex: int, cursorUrl: ?string, page: int, seenIds: list<int>, unattributedErrors: int, runSeenIds: list<int>, runUnattributedErrors: int, done: bool}
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
        $state['runSeenIds'] ??= [];
        $state['runUnattributedErrors'] ??= 0;
        $target = $this->resolveTarget($link);
        $sites = $this->runSites($link, $requestedSite);
        [$queryParams] = OffsetPreset::forLink($link, $offset)?->resolve() ?? [[], null];

        // The first step opens the log; later steps reload the run in progress.
        if (($state['logId'] ?? null) === null) {
            $log = $this->startSync($link, $trigger, $requestedSite);
            $state['logId'] = $log->id;
        } else {
            $log = LogRecord::findOne($state['logId']);

            if (! $log) {
                throw new InfluxException("Influx log #{$state['logId']} vanished mid-run.");
            }
        }

        $siteHandle = $sites[$state['siteIndex']] ?? null;
        $context = SyncContext::forSite($link, $target, $siteHandle, $trigger);

        try {
            $page = $plugin->data->page($link, $siteHandle, $state['cursorUrl'], $queryParams, $state['page']);
        } catch (Throwable $e) {
            // A page fetch failed — fail the run and stop (no retry).
            $plugin->logs->fail($log, $e->getMessage());
            $state['done'] = true;

            return $state;
        }

        // The run-end global-delete sweep needs the UNION across every site;
        // only accumulate it when the link carries the DELETE flag, so a
        // disable-only run doesn't bloat the payload with a set nothing reads.
        $accumulateRun = $this->runPolicy($link) !== null;

        // Dedupe the running seen-sets as value-keyed maps (lists re-derived on
        // write-back), so a re-processed tail after a retried step can't
        // double-count. The per-site set resets each site; the run set is the
        // never-reset union.
        $seen = array_fill_keys($state['seenIds'], true);
        $runSeen = array_fill_keys($state['runSeenIds'], true);

        // Flush at the page boundary — and in a finally so a throw escaping the
        // loop still persists rows for items already saved this step. A retried
        // queue step then re-processes only the un-flushed tail (and re-folds
        // its ids into the seen-sets, hence the dedupe above).
        try {
            foreach ($page->items as $item) {
                try {
                    $elementId = $this->processItem($context, $item, $log);

                    if ($elementId !== null) {
                        $seen[$elementId] = true;

                        if ($accumulateRun) {
                            $runSeen[$elementId] = true;
                        }
                    }
                } catch (Throwable $e) {
                    $plugin->logs->recordItem($log, ItemAction::ERROR, null, null, $e->getMessage(), $item->raw());
                    $state['unattributedErrors']++;

                    if ($accumulateRun) {
                        $state['runUnattributedErrors']++;
                    }
                }
            }
        } finally {
            $plugin->logs->flush($log);
            $state['seenIds'] = array_keys($seen);
            $state['runSeenIds'] = array_keys($runSeen);
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

        // Site finished. The PER-SITE sweep (disable / delete-for-site) runs
        // now, scoped to this site, off the set accumulated across its steps —
        // then the per-site set ALWAYS resets so the next site starts clean.
        // The run set (runSeenIds/runUnattributedErrors) is the never-reset
        // UNION across every site: it keeps accumulating and feeds the run-end
        // global-delete sweep after the last site. The two compose — a link
        // flagged for both disable and delete sweeps here per site AND at run
        // end.
        $this->sweepMissing($context, $state['seenIds'], $state['unattributedErrors'], $log);

        $state['seenIds'] = [];
        $state['unattributedErrors'] = 0;

        // Advance to the next site, or finish the whole run.
        $state['siteIndex']++;
        $state['cursorUrl'] = null;
        $state['page'] = 1;

        if ($state['siteIndex'] >= count($sites)) {
            // Last site done — fire the run-end global-delete sweep over the
            // accumulated union (a no-op when the link lacks the DELETE flag).
            $this->sweepMissingForRun($context, $state['runSeenIds'], $state['runUnattributedErrors'], $log, $requestedSite);

            $this->finishSync($link, $log);
            $state['done'] = true;
        }

        return $state;
    }

    /**
     * Begin a run: fire the cancellable before-event, take the backup, open the
     * log. Shared with {@see batchStep()}; {@see syncLink()} does this inline.
     *
     * @param string|null $siteHandle The site this run was scoped to (null =
     * an all-sites run), recorded on the log — see {@see runWithLog()}.
     */
    protected function startSync(Link $link, SyncTrigger $trigger, ?string $siteHandle = null): LogRecord
    {
        $beforeEvent = new SyncLinkEvent(['link' => $link]);
        $this->trigger(self::EVENT_BEFORE_SYNC_LINK, $beforeEvent);

        if (! $beforeEvent->isValid) {
            throw new InfluxException("Link '{$link->handle}' run cancelled by a beforeSyncLink listener.");
        }

        Influx::getInstance()->backup->backupForLink($link);

        return Influx::getInstance()->logs->start($link, $trigger, $siteHandle);
    }

    /**
     * Finalise a run: close the log and fire the after-event with the final
     * counters.
     */
    protected function finishSync(Link $link, LogRecord $log): void
    {
        Influx::getInstance()->logs->finish($log);

        $this->trigger(self::EVENT_AFTER_SYNC_LINK, new SyncLinkEvent([
            'link'           => $link,
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

        return $this->runWithLog($link, SyncTrigger::ELEMENT, function(LogRecord $log) use ($plugin, $link, $target, $element) {
            foreach ($link->syncSiteHandles() as $siteHandle) {
                $context = SyncContext::forSite($link, $target, $siteHandle, SyncTrigger::ELEMENT);
                $tokens = $plugin->endpointTokens->tokensForElement($link, $element, $siteHandle);
                $item = new RemoteItem($plugin->data->fetchOne($link, $tokens));

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
     * the start/finish/fail/re-throw scaffold lives, shared by {@see syncLink()}
     * and {@see syncElement()}.
     *
     * @param string|null $siteHandle The site this run was SCOPED to (null = an
     * all-sites run), recorded on the log so the viewer can show which site's
     * endpoint was fetched.
     */
    protected function runWithLog(Link $link, SyncTrigger $trigger, callable $body, ?string $siteHandle = null): LogRecord
    {
        $logs = Influx::getInstance()->logs;
        $log = $logs->start($link, $trigger, $siteHandle);

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
     * Walk every page of the feed for one site and process every item.
     * Pagination mechanics (fetching, cycle guards, URL normalization) live
     * in {@see \GlueAgency\Influx\data\PagedFeed}.
     *
     * The per-site missing-elements sweep (disable / delete-for-site) fires
     * here, once this site's pages are exhausted — an element missing from
     * THIS site's feed is disabled/removed FOR this site only. The GLOBAL
     * delete policy does NOT sweep here: it needs the union of every site's
     * seen-set (an element is deleted only when NO site mentioned it), so this
     * method returns its seen-set and unattributed-error count and lets
     * {@see syncLink()} fire the run-end sweep after the last site.
     *
     * @return array{seenIds: list<int>, unattributedErrors: int} This site's
     * seen-set and unattributed-error count, for the run-end global-delete
     * sweep to accumulate across sites.
     * @throws FeedFetchException on fetch failures, paginator URL cycles, or
     * runaway pagination.
     */
    protected function processSite(SyncContext $context, array $queryParams, LogRecord $log, ?callable $onProgress = null): array
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

        // Site fully walked — run the PER-SITE sweep (disable / delete-for-site)
        // over the elements it owns that this site's feed never mentioned. For
        // the global-delete policy this is a no-op (sweepMissing() bails on it);
        // the run-end sweep handles that from the returned union.
        // flush() inside sweepMissing() persists its rows.
        $this->sweepMissing($context, array_keys($seenIds), $unattributedErrors, $log);

        return [
            'seenIds'            => array_keys($seenIds),
            'unattributedErrors' => $unattributedErrors,
        ];
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
     * The PER-SITE missing-elements action a link's `processing` flags call
     * for, or null when neither per-site flag is set. Fires once after each
     * site's pages, scoped to that site — an element missing from one site's
     * feed is disabled/removed FOR that site only.
     *
     * Precedence when both per-site flags are set — the more destructive wins:
     *
     *   DELETE_FOR_SITE  >  DISABLE
     *
     * The global DELETE flag does NOT participate: per-site and run-end sweeps
     * COMPOSE (both may fire in one run), so a link flagged
     * `['disable', 'delete']` disables cross-site leaks per site AND deletes
     * run-wide-missing elements at run end. {@see runPolicy()} owns DELETE.
     *
     * Pure: reads only {@see Link::$processing}, so it's unit-tested without a
     * Craft boot.
     */
    protected function perSitePolicy(Link $link): ?ItemAction
    {
        if (in_array(Link::PROCESSING_DELETE_FOR_SITE, $link->processing, true)) {
            return ItemAction::DELETED_FOR_SITE;
        }

        if (in_array(Link::PROCESSING_DISABLE, $link->processing, true)) {
            return ItemAction::DISABLED;
        }

        return null;
    }

    /**
     * The RUN-END missing-elements action a link's `processing` flags call for,
     * or null when the global DELETE flag isn't set. The global delete fires
     * once after the last site, over the union of every site's seen-set: the
     * element row is destroyed entirely, so it must be missing from EVERY
     * site's feed.
     *
     * Independent of {@see perSitePolicy()} — the two compose. When both
     * resolve non-null an element the per-site sweep already disabled can then
     * be deleted by the run-end sweep, leaving two log rows for it; that's
     * accepted (the disable corrected the site-visibility leak the moment the
     * site finished; the delete removes an element no feed carries).
     *
     * Pure: reads only {@see Link::$processing}, so it's unit-tested without a
     * Craft boot.
     */
    protected function runPolicy(Link $link): ?ItemAction
    {
        return in_array(Link::PROCESSING_DELETE, $link->processing, true)
            ? ItemAction::DELETED
            : null;
    }

    /**
     * The PER-SITE missing-elements sweep (disable / delete-for-site), run once
     * after each site's pages are exhausted — step 4 of the run lifecycle,
     * scoped to the just-finished site. The GLOBAL delete policy is NOT swept
     * here ({@see perSitePolicy()} ignores it); {@see sweepMissingForRun()}
     * handles that once, after the last site. The two sweeps COMPOSE: a link
     * flagged for both disable and delete fires this sweep per site AND the
     * run-end delete — an element may be disabled here then deleted at run end
     * (two log rows), which is accepted.
     *
     * Safety first: a sweep acts on the COMPLEMENT of the seen-set, so it's
     * only safe when the seen-set is complete. If any item failed WITHOUT a
     * resolvable element ($unattributedErrors > 0) the set is untrustworthy —
     * an element that's actually in the feed but errored before it could be
     * matched would be swept as missing. So the sweep bails, warns, and logs
     * one SKIPPED row (not ERROR — an error would flip the run's status
     * perception; SKIPPED tells the user why nothing was swept).
     *
     * Mode is derived from the run's site scope and the policy:
     *   - DISABLED: site run → {@see ElementTargetInterface::disableForSite()}
     *     (leave the element live in its other sites); global run →
     *     {@see ElementTargetInterface::disable()}.
     *   - DELETED_FOR_SITE: needs a site — a global (siteless) run logs one
     *     SKIPPED row and returns.
     *
     * @param list<int> $seenIds Element ids present in this site's feed.
     * @param int $unattributedErrors Items that failed with no resolvable
     * element — any at all disables the sweep.
     */
    protected function sweepMissing(SyncContext $context, array $seenIds, int $unattributedErrors, LogRecord $log): void
    {
        $link = $context->link;
        $policy = $this->perSitePolicy($link);

        // Only the per-site policies sweep here; global delete rides the run.
        // The two compose: a link flagged for both disable AND delete sweeps
        // here per site (disable/delete-for-site) and again at run end (delete).
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

        // delete-for-site is meaningless without a site to scope to.
        if ($policy === ItemAction::DELETED_FOR_SITE && $context->siteId === null) {
            $this->logSweepSkip(
                $log,
                'Missing-elements sweep skipped: delete-for-site needs a site-scoped run.',
            );

            return;
        }

        $this->applySweep($context, $policy, $seenIds, $context->siteId, $log);
    }

    /**
     * The RUN-END global-delete sweep, fired once after the last site — the
     * only sweep for the {@see ItemAction::DELETED} policy. An element is
     * deleted entirely only when NO site's feed mentioned it, so it sweeps the
     * UNION of every site's seen-set against an UNSCOPED candidate query
     * (`siteId('*')->unique()`, every status). No-op when the link doesn't
     * carry the DELETE flag (see {@see runPolicy()}). Composes with the
     * per-site sweep: both fire when a link is flagged for disable AND delete.
     *
     * Two guards gate it:
     *   - Clean-pass, but across the WHOLE run: unattributed errors from ANY
     *     site make the union untrustworthy, so the run-end sweep bails and
     *     logs one SKIPPED row (a feed item that errored before it could be
     *     matched, on any site, might belong to an element we'd wrongly delete).
     *   - Site-scoped run on a multi-endpoint link: a run restricted to one
     *     site ($requestedSite) can't read the OTHER sites' feeds, yet those
     *     feeds co-own the delete decision (an element the other sites still
     *     carry must not be deleted). So on a multi-endpoint link a site-scoped
     *     run does NOT sweep — it records one SKIPPED row explaining an
     *     all-sites run is required. Single-endpoint and single-site links are
     *     exempt: "every site" is trivially the one site the run covers.
     *
     * The context passed in is the last site's — but the global delete routes
     * through {@see ElementTargetInterface::delete()} regardless of site, so a
     * siteless clone is used for the candidate query and the apply, making the
     * cross-site scope explicit rather than leaning on the last site's id.
     *
     * @param list<int> $seenIds Union of every site's seen element ids.
     * @param int $unattributedErrors Run-wide count of items that failed with
     * no resolvable element — any at all disables the sweep.
     * @param string|null $requestedSite The single site this run was scoped to
     * (null = an all-sites run), used for the multi-endpoint guard.
     */
    protected function sweepMissingForRun(
        SyncContext $context,
        array $seenIds,
        int $unattributedErrors,
        LogRecord $log,
        ?string $requestedSite = null,
    ): void {
        $link = $context->link;
        $policy = $this->runPolicy($link);

        // Only the global-delete policy sweeps at run end.
        if ($policy === null) {
            return;
        }

        // A site-scoped run on a link with multiple site endpoints can't see
        // the other sites' feeds, which co-own the delete decision — refuse to
        // sweep rather than delete elements those unseen feeds still carry.
        if ($requestedSite !== null && count($link->siteHandles()) > 1) {
            $this->logSweepSkip(
                $log,
                'Missing-elements delete sweep skipped: this run was scoped to a single site, '
                . 'but the link has per-site endpoints whose feeds also define which elements '
                . 'are still owned. Run an all-sites sync so the delete decision sees every feed.',
            );

            return;
        }

        // Clean-pass guard across the whole run: any site's unattributed errors
        // make the union untrustworthy.
        if ($unattributedErrors > 0) {
            $this->warnSweepSkipped(
                "Influx: global missing-elements delete sweep skipped for link '{$link->handle}'"
                . " — {$unattributedErrors} item(s) across the run failed without a resolvable "
                . 'element, so the seen-set union cannot be trusted.',
            );
            $this->logSweepSkip(
                $log,
                "Missing-elements delete sweep skipped: {$unattributedErrors} item(s) failed without a resolvable element.",
            );

            return;
        }

        // The global delete is cross-site: run the candidate query and apply
        // against a siteless context (siteId null) so the target's
        // missingElementsQuery() uses siteId('*')->unique() and delete() acts
        // on the whole element, whatever the last site processed happened to be.
        $runContext = new SyncContext(
            link: $link,
            target: $context->target,
            siteId: null,
            siteHandle: null,
            trigger: $context->trigger,
        );

        $this->applySweep($runContext, $policy, $seenIds, null, $log);
    }

    /**
     * The shared sweep body: build the target's candidate query, status-filter
     * it per policy, walk it in batches, and apply the policy action to each
     * element — logging a success row, an ERROR row on a failed/false save, or
     * an ERROR row on a thrown failure. Both {@see sweepMissing()} (per site)
     * and {@see sweepMissingForRun()} (run-end global delete) route here, so
     * the per-element loop, the false-vs-success discipline, and the tail
     * flush live in exactly one place.
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

        // DISABLED only touches still-enabled elements (skip the churn of
        // re-disabling); the delete policies consider every status.
        $query->status($policy === ItemAction::DISABLED ? 'enabled' : null);

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
            ItemAction::DELETED          => $target->delete($element),
            ItemAction::DELETED_FOR_SITE => $target->deleteForSite($element, (int) $context->siteId),
            ItemAction::DISABLED         => $context->siteId !== null
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
