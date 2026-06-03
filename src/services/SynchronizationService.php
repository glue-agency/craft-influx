<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use TDM\Influx\Influx;
use TDM\Influx\events\SyncFeedEvent;
use TDM\Influx\events\SyncItemEvent;
use TDM\Influx\exceptions\InfluxException;
use TDM\Influx\models\Feed;
use TDM\Influx\records\Log as LogRecord;
use TDM\Influx\targets\ElementTargetInterface;

/**
 * Owns the full sync lifecycle for a feed:
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
    public const EVENT_BEFORE_SYNC_FEED = 'beforeSyncFeed';
    public const EVENT_AFTER_SYNC_FEED = 'afterSyncFeed';
    public const EVENT_BEFORE_ITEM = 'beforeItem';
    public const EVENT_AFTER_ITEM_MAPPING = 'afterItemMapping';
    public const EVENT_AFTER_ITEM = 'afterItem';

    /**
     * Run a full feed sync.
     *
     * @param string|null $ago Key into $feed->ago presets, applied as a query param.
     * @param string $trigger One of: console, cp, queue, element
     */
    public function syncFeed(
        Feed $feed,
        ?string $ago = null,
        string $trigger = 'console',
    ): LogRecord {
        $plugin = Influx::getInstance();

        $beforeEvent = new SyncFeedEvent(['feed' => $feed]);
        $this->trigger(self::EVENT_BEFORE_SYNC_FEED, $beforeEvent);
        if (!$beforeEvent->isValid) {
            throw new InfluxException("Feed '{$feed->handle}' run cancelled by a beforeSyncFeed listener.");
        }

        $plugin->backup->backupForFeed($feed);

        $log = $plugin->logs->start($feed, $trigger);

        $target = $this->resolveTarget($feed);

        $queryParams = $this->agoQueryParams($feed, $ago);

        try {
            $siteHandles = $feed->siteHandles() ?: [null];

            foreach ($siteHandles as $siteHandle) {
                $this->processSite($feed, $target, $siteHandle, $queryParams, $log);
            }

            $plugin->logs->finish($log);
        } catch (\Throwable $e) {
            $plugin->logs->fail($log, $e->getMessage());
            throw $e;
        }

        $afterEvent = new SyncFeedEvent([
            'feed'           => $feed,
            'itemsSeen'      => (int)$log->itemsSeen,
            'itemsCreated'   => (int)$log->itemsCreated,
            'itemsUpdated'   => (int)$log->itemsUpdated,
            'itemsUnchanged' => (int)$log->itemsUnchanged,
            'itemsSkipped'   => (int)$log->itemsSkipped,
            'itemsDeleted'   => (int)$log->itemsDeleted,
        ]);
        $this->trigger(self::EVENT_AFTER_SYNC_FEED, $afterEvent);

        return $log;
    }

    /**
     * Sync a single existing element from its feed's itemEndpoint (the
     * per-entry "Sync from remote" button).
     */
    public function syncElement(Feed $feed, ElementInterface $element): LogRecord
    {
        $plugin = Influx::getInstance();
        $target = $this->resolveTarget($feed);
        $matchAttr = $feed->matchAttribute()
            ?? throw new InfluxException("Feed '{$feed->handle}' has no match attribute.");

        $matchValue = $element->$matchAttr;
        if (!$matchValue) {
            throw new InfluxException("Element #{$element->id} has no value on '{$matchAttr}'.");
        }

        $log = $plugin->logs->start($feed, 'element');

        try {
            $siteHandles = $feed->siteHandles() ?: [null];

            foreach ($siteHandles as $siteHandle) {
                $item = $plugin->data->fetchOne($feed, ['id' => $matchValue], $siteHandle);
                $this->processItem($feed, $target, $item, $siteHandle, $log);
            }

            $plugin->cooldown->mark($feed, $element);
            $plugin->logs->finish($log);
        } catch (\Throwable $e) {
            $plugin->logs->fail($log, $e->getMessage());
            throw $e;
        }

        return $log;
    }

    /**
     * Walk every page of a feed for a single site.
     */
    private function processSite(
        Feed $feed,
        ElementTargetInterface $target,
        ?string $siteHandle,
        array $queryParams,
        LogRecord $log,
    ): void {
        $plugin = Influx::getInstance();
        $data = $plugin->data->fetch($feed, $siteHandle, $queryParams);

        while (true) {
            foreach ($plugin->data->rootList($feed, $data) as $item) {
                $this->processItem($feed, $target, $item, $siteHandle, $log);
            }

            $nextUrl = $feed->paginatorNode
                ? \Cake\Utility\Hash::get($data, $feed->paginatorNode)
                : null;

            if (!$nextUrl) {
                break;
            }

            $data = $plugin->data->fetchUrl($nextUrl, $feed->resolvedHeaders());
        }
    }

    /**
     * Process a single remote item: match-or-build, map, change-detect, save.
     */
    private function processItem(
        Feed $feed,
        ElementTargetInterface $target,
        array $item,
        ?string $siteHandle,
        LogRecord $log,
    ): void {
        $plugin = Influx::getInstance();
        $matchValue = $feed->matchValue($item);

        if ($matchValue === null) {
            $plugin->logs->recordItem(
                $log,
                'skipped',
                null,
                null,
                "Remote item has no value at match path '{$feed->match['source']}'.",
                $item,
            );
            return;
        }

        $siteId = $siteHandle
            ? (Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id)
            : null;

        $element = $target->findByMatchValue($feed, $matchValue, $siteId);
        $isNew = false;

        $beforeEvent = new SyncItemEvent([
            'feed'       => $feed,
            'item'       => $item,
            'element'    => $element,
            'siteHandle' => $siteHandle,
        ]);
        $this->trigger(self::EVENT_BEFORE_ITEM, $beforeEvent);

        if ($beforeEvent->skip) {
            $plugin->logs->recordItem($log, 'skipped', $element?->id, (string)$matchValue);
            return;
        }

        // Allow listeners to swap in a different element.
        $element = $beforeEvent->element;

        if (!$element) {
            if (!in_array('create', $feed->processing, true)) {
                $plugin->logs->recordItem($log, 'skipped', null, (string)$matchValue, "No existing element and 'create' not enabled.");
                return;
            }
            $element = $target->buildNew($feed, $siteId);
            // Stamp the match attribute on the new element so subsequent
            // lookups find it. Custom fields go through setFieldValue;
            // native attributes (rare for match) fall through.
            $matchAttr = $feed->matchAttribute();
            if ($element->hasAttribute($matchAttr) || property_exists($element, $matchAttr)) {
                $element->$matchAttr = $matchValue;
            } else {
                $element->setFieldValue($matchAttr, $matchValue);
            }
            $isNew = true;
        } elseif (!in_array('update', $feed->processing, true)) {
            $plugin->logs->recordItem($log, 'skipped', $element->id, (string)$matchValue, "'update' not enabled for this feed.");
            return;
        }

        if ($siteId) {
            $element->siteId = $siteId;
        }

        // Apply mappings, tracking whether any of them want to change the
        // element. The save is gated on this so unchanged items skip the
        // expensive content-version churn that Craft does on every save.
        $changed = $isNew;
        $mappingsService = $plugin->mapping;

        foreach ($feed->mappings as $targetField => $config) {
            if (!is_array($config) || !isset($config['type'])) {
                continue;
            }

            $mapping = $mappingsService->get($config['type']);

            if (!$changed && $mapping->hasChanged($element, $targetField, $item, $config, $feed)) {
                $changed = true;
            }

            $mapping->apply($element, $targetField, $item, $config, $feed);
        }

        $afterMappingEvent = new SyncItemEvent([
            'feed'       => $feed,
            'item'       => $item,
            'element'    => $element,
            'siteHandle' => $siteHandle,
        ]);
        $this->trigger(self::EVENT_AFTER_ITEM_MAPPING, $afterMappingEvent);

        if (!$changed) {
            $plugin->logs->recordItem($log, 'unchanged', $element->id, (string)$matchValue);
            $afterEvent = new SyncItemEvent([
                'feed' => $feed, 'item' => $item, 'element' => $element,
                'siteHandle' => $siteHandle, 'action' => 'unchanged',
            ]);
            $this->trigger(self::EVENT_AFTER_ITEM, $afterEvent);
            return;
        }

        $saved = Craft::$app->getElements()->saveElement($element, false);

        $action = !$saved ? 'error' : ($isNew ? 'created' : 'updated');
        $plugin->logs->recordItem(
            $log,
            $action,
            $element->id,
            (string)$matchValue,
            $saved ? null : json_encode($element->getErrors()),
        );

        $afterEvent = new SyncItemEvent([
            'feed' => $feed, 'item' => $item, 'element' => $element,
            'siteHandle' => $siteHandle, 'action' => $action,
        ]);
        $this->trigger(self::EVENT_AFTER_ITEM, $afterEvent);
    }

    private function resolveTarget(Feed $feed): ElementTargetInterface
    {
        $target = Influx::getInstance()->targets->forFeed($feed);
        if (!$target) {
            throw new InfluxException("No element target registered for '{$feed->elementType}'.");
        }
        return $target;
    }

    private function agoQueryParams(Feed $feed, ?string $ago): array
    {
        if (!$ago || !isset($feed->ago[$ago])) {
            return [];
        }

        $preset = $feed->ago[$ago];
        if (!isset($preset['since'], $preset['queryParam'])) {
            return [];
        }

        $since = (new \DateTime())->modify($preset['since']);
        $format = $preset['format'] ?? \DateTimeInterface::ATOM;

        return [$preset['queryParam'] => $since->format($format)];
    }
}
