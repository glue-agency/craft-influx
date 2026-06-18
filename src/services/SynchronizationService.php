<?php

namespace GlueAgency\Influx\services;

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
 *   4. Post-run hooks (event, log finalisation).
 *
 * Deletion of items that disappeared remotely is intentionally NOT handled in
 * this first cut — see README "Roadmap".
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
        });

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
     * @param array{logId: ?int, siteIndex: int, cursorUrl: ?string, page: int} $state
     * @param callable|null $onProgress fn(int $seen, ?int $total)
     * @return array{logId: ?int, siteIndex: int, cursorUrl: ?string, page: int, done: bool}
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
        $target = $this->resolveTarget($link);
        $sites = $this->runSites($link, $requestedSite);
        [$queryParams] = OffsetPreset::forLink($link, $offset)?->resolve() ?? [[], null];

        // The first step opens the log; later steps reload the run in progress.
        if (($state['logId'] ?? null) === null) {
            $log = $this->startSync($link, $trigger);
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

        foreach ($page->items as $item) {
            try {
                $this->processItem($context, $item, $log);
            } catch (Throwable $e) {
                $plugin->logs->recordItem($log, ItemAction::ERROR, null, null, $e->getMessage(), $item->raw());
            }
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

        // Site finished — advance to the next, or finish the whole run.
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
     */
    protected function startSync(Link $link, SyncTrigger $trigger): LogRecord
    {
        $beforeEvent = new SyncLinkEvent(['link' => $link]);
        $this->trigger(self::EVENT_BEFORE_SYNC_LINK, $beforeEvent);

        if (! $beforeEvent->isValid) {
            throw new InfluxException("Link '{$link->handle}' run cancelled by a beforeSyncLink listener.");
        }

        Influx::getInstance()->backup->backupForLink($link);

        return Influx::getInstance()->logs->start($link, $trigger);
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
     * Run a body within the log lifecycle: start a log, run the body (which
     * does the actual per-site work and receives the {@see LogRecord}), then
     * finish — or, if anything throws, fail the log and re-throw. The one place
     * the start/finish/fail/re-throw scaffold lives, shared by {@see syncLink()}
     * and {@see syncElement()}.
     */
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

    protected function runWithLog(Link $link, SyncTrigger $trigger, callable $body): LogRecord
    {
        $logs = Influx::getInstance()->logs;
        $log = $logs->start($link, $trigger);

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

        foreach ($plugin->data->pages($context->link, $context->siteHandle, $queryParams) as $page) {
            if ($firstPageSize === null) {
                $firstPageSize = count($page->items);
            }

            foreach ($page->items as $item) {
                // One bad item must not kill the run — transport/config
                // errors abort (they throw from the pages() iterator), but
                // per-item failures become an error row and the run goes on.
                try {
                    $this->processItem($context, $item, $log);
                } catch (Throwable $e) {
                    $plugin->logs->recordItem($log, ItemAction::ERROR, null, null, $e->getMessage(), $item->raw());
                }
            }

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
    }

    /**
     * Run one remote item through the shared pipeline, firing the item
     * events at the phase seams and logging the outcome. The logic itself
     * lives in {@see ItemProcessor} — this method only owns events + logs.
     */
    protected function processItem(SyncContext $context, RemoteItem $item, LogRecord $log): void
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

                return;
            }

            // Allow listeners to swap in a different element. The decision is
            // re-derived: handing us an element turns a no-create skip into
            // an update.
            $resolution = $resolution->withElement($link, $beforeEvent->element);
        }

        $result = $this->itemProcessor->populate($context, $item, $resolution);

        if ($result->decision->isSkip()) {
            $plugin->logs->recordItem($log, ItemAction::SKIPPED, $result->element?->id, $this->matchValueString($result->matchValue), $result->message, $item->raw());

            return;
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
