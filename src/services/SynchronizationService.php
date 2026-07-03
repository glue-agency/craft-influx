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
 *   4. Per site, once its pages are exhausted: the missing-elements sweep —
 *      disable/delete the elements this link owns that the feed didn't
 *      mention (see {@see sweepMissing()}). Governed by the link's
 *      `processing` flags and gated by a clean-pass guard: a run with any
 *      item that failed without a resolvable element does NOT sweep, since the
 *      seen-set can't be trusted.
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

        $log = $this->runWithLog($link, $trigger, function(LogRecord $log) use ($link, $target, $trigger, $queryParams, $siteHandles, $onProgress) {
            foreach ($siteHandles as $handle) {
                $context = SyncContext::forSite($link, $target, $handle, $trigger);
                $this->processSite($context, $queryParams, $log, $onProgress);
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
     * The missing-elements sweep needs the whole site's seen-set, but the set
     * is built one page per step — so it rides the state array (`seenIds`,
     * `unattributedErrors`), accumulated across steps and consumed once the
     * site's last page is done, then reset for the next site. seenIds therefore
     * rides the queue payload: fine for feeds up to tens of thousands of ids;
     * flag as a known bound (a feed far larger than that would bloat the job
     * row and should page the sweep differently).
     *
     * @param array{logId: ?int, siteIndex: int, cursorUrl: ?string, page: int, seenIds?: list<int>, unattributedErrors?: int} $state
     * @param callable|null $onProgress fn(int $seen, ?int $total)
     * @return array{logId: ?int, siteIndex: int, cursorUrl: ?string, page: int, seenIds: list<int>, unattributedErrors: int, done: bool}
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

        // Dedupe the running seen-set as a value-keyed map (list re-derived on
        // write-back), so a re-processed tail after a retried step can't
        // double-count.
        $seen = array_fill_keys($state['seenIds'], true);

        // Flush at the page boundary — and in a finally so a throw escaping the
        // loop still persists rows for items already saved this step. A retried
        // queue step then re-processes only the un-flushed tail (and re-folds
        // its ids into $seen, hence the dedupe above).
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

        // Site finished — sweep the elements it owns that never appeared in
        // the feed, using the set accumulated across this site's steps, then
        // reset both keys so the next site starts with a clean seen-set.
        $this->sweepMissing($context, $state['seenIds'], $state['unattributedErrors'], $log);
        $state['seenIds'] = [];
        $state['unattributedErrors'] = 0;

        // Advance to the next site, or finish the whole run.
        $state['siteIndex']++;
        $state['cursorUrl'] = null;
        $state['page'] = 1;

        if ($state['siteIndex'] >= count($sites)) {
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

        // Site fully walked — sweep the elements it owns that the feed never
        // mentioned. flush() inside sweepMissing() persists its rows.
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
     * Which missing-elements action a link's `processing` flags call for, or
     * null when none is enabled. Precedence when more than one is set — the
     * most destructive wins, because a delete subsumes a disable:
     *
     *   DELETE  >  DELETE_FOR_SITE  >  DISABLE
     *
     * Pure: reads only {@see Link::$processing}, so it's unit-tested without a
     * Craft boot.
     */
    protected function missingPolicy(Link $link): ?ItemAction
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

        return null;
    }

    /**
     * Disable/delete the elements this link owns that the feed never
     * mentioned. Runs once per site after its pages are exhausted — the
     * clean-pass step 4 of the run lifecycle.
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
     *   - DELETED_FOR_SITE: needs a site — a global run logs one SKIPPED row
     *     and returns.
     *   - DELETED: full {@see ElementTargetInterface::delete()} regardless of
     *     site scope. Configured semantic: an element missing from THIS site's
     *     feed is deleted entirely. Multi-site users who want per-site removal
     *     choose delete-for-site instead.
     *
     * The candidate query (from the target) is status-filtered per policy —
     * DISABLED skips already-disabled elements (no churn); the delete policies
     * consider every status — and walked in batches so memory stays bounded.
     * Each element's action is wrapped in its own try/catch: a thrown failure
     * logs an error row and the sweep carries on. A save that returns false
     * WITHOUT throwing (a validation failure that didn't persist) is likewise
     * an ERROR row, never a success row — the log must not claim a
     * disable/delete that never landed.
     *
     * @param list<int> $seenIds Element ids present in this site's feed.
     * @param int $unattributedErrors Items that failed with no resolvable
     * element — any at all disables the sweep.
     */
    protected function sweepMissing(SyncContext $context, array $seenIds, int $unattributedErrors, LogRecord $log): void
    {
        $plugin = Influx::getInstance();
        $link = $context->link;
        $policy = $this->missingPolicy($link);

        if ($policy === null) {
            return;
        }

        // Clean-pass guard: never sweep off a seen-set we couldn't fully build.
        if ($unattributedErrors > 0) {
            Craft::warning(
                "Influx: missing-elements sweep skipped for link '{$link->handle}'"
                . ($context->siteHandle !== null ? " (site '{$context->siteHandle}')" : '')
                . " — {$unattributedErrors} item(s) failed without a resolvable element, "
                . 'so the missing-set cannot be trusted.',
                __METHOD__,
            );
            $plugin->logs->recordItem(
                $log,
                ItemAction::SKIPPED,
                null,
                null,
                "Missing-elements sweep skipped: {$unattributedErrors} item(s) failed without a resolvable element.",
            );

            return;
        }

        // delete-for-site is meaningless without a site to scope to.
        if ($policy === ItemAction::DELETED_FOR_SITE && $context->siteId === null) {
            $plugin->logs->recordItem(
                $log,
                ItemAction::SKIPPED,
                null,
                null,
                'Missing-elements sweep skipped: delete-for-site needs a site-scoped run.',
            );

            return;
        }

        $query = $context->target->missingElementsQuery($link, $seenIds, $context->siteId);

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
