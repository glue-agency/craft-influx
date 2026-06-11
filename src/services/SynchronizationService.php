<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\events\SyncLinkEvent;
use GlueAgency\Influx\events\SyncItemEvent;
use GlueAgency\Influx\exceptions\FeedFetchException;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\OffsetPreset;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\sync\ItemProcessor;
use GlueAgency\Influx\sync\ItemSyncResult;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\ElementTargetInterface;

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
     * Run a full link sync.
     *
     * @param string|null $offset Key into $link->offset presets, applied as a query param.
     */
    public function syncLink(
        Link $link,
        ?string $offset = null,
        SyncTrigger $trigger = SyncTrigger::Console,
    ): LogRecord {
        $plugin = Influx::getInstance();

        $beforeEvent = new SyncLinkEvent(['link' => $link]);
        $this->trigger(self::EVENT_BEFORE_SYNC_LINK, $beforeEvent);
        if (!$beforeEvent->isValid) {
            throw new InfluxException("Link '{$link->handle}' run cancelled by a beforeSyncLink listener.");
        }

        $plugin->backup->backupForLink($link);

        $log = $plugin->logs->start($link, $trigger);

        $target = $this->resolveTarget($link);

        [$queryParams] = OffsetPreset::forLink($link, $offset)?->resolve() ?? [[], null];

        try {
            $siteHandles = $link->siteHandles() ?: [null];

            foreach ($siteHandles as $siteHandle) {
                $context = $this->siteContext($link, $target, $siteHandle, $trigger);
                $this->processSite($context, $queryParams, $log);
            }

            $plugin->logs->finish($log);
        } catch (\Throwable $e) {
            $plugin->logs->fail($log, $e->getMessage());
            throw $e;
        }

        $afterEvent = new SyncLinkEvent([
            'link'           => $link,
            'itemsSeen'      => (int)$log->itemsSeen,
            'itemsCreated'   => (int)$log->itemsCreated,
            'itemsUpdated'   => (int)$log->itemsUpdated,
            'itemsUnchanged' => (int)$log->itemsUnchanged,
            'itemsSkipped'   => (int)$log->itemsSkipped,
            'itemsDeleted'   => (int)$log->itemsDeleted,
        ]);
        $this->trigger(self::EVENT_AFTER_SYNC_LINK, $afterEvent);

        return $log;
    }

    /**
     * Sync a single existing element from its link's itemEndpoint (the
     * per-entry "Sync from remote" button).
     */
    public function syncElement(Link $link, ElementInterface $element): LogRecord
    {
        $plugin = Influx::getInstance();
        $target = $this->resolveTarget($link);
        $matchAttr = $link->matchAttribute()
            ?? throw new InfluxException("Link '{$link->handle}' has no match attribute.");

        $matchValue = $element->$matchAttr;
        if (!$matchValue) {
            throw new InfluxException("Element #{$element->id} has no value on '{$matchAttr}'.");
        }

        $log = $plugin->logs->start($link, SyncTrigger::Element);

        try {
            $siteHandles = $link->siteHandles() ?: [null];

            foreach ($siteHandles as $siteHandle) {
                $context = $this->siteContext($link, $target, $siteHandle, SyncTrigger::Element);
                $tokens = $plugin->endpointTokens->tokensForElement($link, $element, $siteHandle);
                $item = new RemoteItem($plugin->data->fetchOne($link, $tokens));

                try {
                    $this->processItem($context, $item, $log);
                } catch (\Throwable $e) {
                    $plugin->logs->recordItem($log, ItemAction::Error, $element->id, null, $e->getMessage(), $item->raw());
                }
            }

            $plugin->cooldown->mark($link, $element);
            $plugin->logs->finish($log);
        } catch (\Throwable $e) {
            $plugin->logs->fail($log, $e->getMessage());
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
    protected function processSite(SyncContext $context, array $queryParams, LogRecord $log): void
    {
        $plugin = Influx::getInstance();

        foreach ($plugin->data->pages($context->link, $context->siteHandle, $queryParams) as $page) {
            foreach ($page->items as $item) {
                // One bad item must not kill the run — transport/config
                // errors abort (they throw from the pages() iterator), but
                // per-item failures become an error row and the run goes on.
                try {
                    $this->processItem($context, $item, $log);
                } catch (\Throwable $e) {
                    $plugin->logs->recordItem($log, ItemAction::Error, null, null, $e->getMessage(), $item->raw());
                }
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
        if ($resolution->decision !== SyncDecision::SkipNoMatch) {
            $beforeEvent = new SyncItemEvent([
                'link'       => $link,
                'item'       => $item->raw(),
                'element'    => $resolution->element,
                'siteHandle' => $context->siteHandle,
            ]);
            $this->trigger(self::EVENT_BEFORE_ITEM, $beforeEvent);

            if ($beforeEvent->skip) {
                $plugin->logs->recordItem($log, ItemAction::Skipped, $resolution->element?->id, (string)$resolution->matchValue, null, $item->raw());
                return;
            }

            // Allow listeners to swap in a different element. The decision is
            // re-derived: handing us an element turns a no-create skip into
            // an update.
            $resolution = $resolution->withElement($link, $beforeEvent->element);
        }

        $result = $this->itemProcessor->populate($context, $item, $resolution);

        if ($result->decision->isSkip()) {
            $matchValue = $result->matchValue !== null ? (string)$result->matchValue : null;
            $plugin->logs->recordItem($log, ItemAction::Skipped, $result->element?->id, $matchValue, $result->message, $item->raw());
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
            (string)$result->matchValue,
            $this->resultMessage($result),
            $item->raw(),
        );

        $this->fireAfterItem($link, $item->raw(), $result->element, $context->siteHandle, $result->action);
    }

    /**
     * Log message for a committed result: the save error (if any) plus any
     * per-mapping errors the applier captured — those no longer abort the
     * item, so they must surface here to stay visible.
     */
    protected function resultMessage(ItemSyncResult $result): ?string
    {
        $message = $result->message;
        $errors = $result->mappingErrors();
        if (!$errors) {
            return $message;
        }

        $parts = [];
        foreach ($errors as $handle => $error) {
            $parts[] = "{$handle}: {$error}";
        }
        $note = 'Mapping errors — ' . implode('; ', $parts);

        return $message === null ? $note : $message . ' | ' . $note;
    }

    protected function siteContext(
        Link $link,
        ElementTargetInterface $target,
        ?string $siteHandle,
        ?SyncTrigger $trigger,
    ): SyncContext {
        $siteId = $siteHandle
            ? (Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id)
            : null;

        return new SyncContext(
            link: $link,
            target: $target,
            siteId: $siteId,
            siteHandle: $siteHandle,
            trigger: $trigger,
        );
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
        if (!$target) {
            throw new InfluxException("No element target registered for '{$link->elementType}'.");
        }
        return $target;
    }

}
