<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use TDM\Influx\Influx;
use TDM\Influx\events\SyncLinkEvent;
use TDM\Influx\events\SyncItemEvent;
use TDM\Influx\exceptions\InfluxException;
use TDM\Influx\models\Link;
use TDM\Influx\records\Log as LogRecord;
use TDM\Influx\targets\ElementTargetInterface;

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
     * Run a full link sync.
     *
     * @param string|null $ago Key into $link->ago presets, applied as a query param.
     * @param string $trigger One of: console, cp, queue, element
     */
    public function syncLink(
        Link $link,
        ?string $ago = null,
        string $trigger = 'console',
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

        $queryParams = $this->agoQueryParams($link, $ago);

        try {
            $siteHandles = $link->siteHandles() ?: [null];

            foreach ($siteHandles as $siteHandle) {
                $this->processSite($link, $target, $siteHandle, $queryParams, $log);
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

        $log = $plugin->logs->start($link, 'element');

        try {
            $siteHandles = $link->siteHandles() ?: [null];

            foreach ($siteHandles as $siteHandle) {
                $item = $plugin->data->fetchOne($link, ['id' => $matchValue], $siteHandle);
                $this->processItem($link, $target, $item, $siteHandle, $log);
            }

            $plugin->cooldown->mark($link, $element);
            $plugin->logs->finish($log);
        } catch (\Throwable $e) {
            $plugin->logs->fail($log, $e->getMessage());
            throw $e;
        }

        return $log;
    }

    private function processSite(
        Link $link,
        ElementTargetInterface $target,
        ?string $siteHandle,
        array $queryParams,
        LogRecord $log,
    ): void {
        $plugin = Influx::getInstance();
        $data = $plugin->data->fetch($link, $siteHandle, $queryParams);

        while (true) {
            foreach ($plugin->data->rootList($link, $data) as $item) {
                $this->processItem($link, $target, $item, $siteHandle, $log);
            }

            $nextUrl = $link->paginatorNode
                ? \Cake\Utility\Hash::get($data, $link->paginatorNode)
                : null;

            if (!$nextUrl) {
                break;
            }

            $data = $plugin->data->fetchUrl($nextUrl, $link->resolvedHeaders());
        }
    }

    private function processItem(
        Link $link,
        ElementTargetInterface $target,
        array $item,
        ?string $siteHandle,
        LogRecord $log,
    ): void {
        $plugin = Influx::getInstance();
        $matchValue = $link->matchValue($item);

        if ($matchValue === null) {
            $plugin->logs->recordItem(
                $log,
                'skipped',
                null,
                null,
                "Remote item has no value at match path '{$link->match['source']}'.",
                $item,
            );
            return;
        }

        $siteId = $siteHandle
            ? (Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id)
            : null;

        $element = $target->findByMatchValue($link, $matchValue, $siteId);
        $isNew = false;

        $beforeEvent = new SyncItemEvent([
            'link'       => $link,
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
            if (!in_array('create', $link->processing, true)) {
                $plugin->logs->recordItem($log, 'skipped', null, (string)$matchValue, "No existing element and 'create' not enabled.");
                return;
            }
            $element = $target->buildNew($link, $siteId);
            $matchAttr = $link->matchAttribute();
            if ($element->hasAttribute($matchAttr) || property_exists($element, $matchAttr)) {
                $element->$matchAttr = $matchValue;
            } else {
                $element->setFieldValue($matchAttr, $matchValue);
            }
            $isNew = true;
        } elseif (!in_array('update', $link->processing, true)) {
            $plugin->logs->recordItem($log, 'skipped', $element->id, (string)$matchValue, "'update' not enabled for this link.");
            return;
        }

        if ($siteId) {
            $element->siteId = $siteId;
        }

        $changed = $isNew;
        $mappingsService = $plugin->mapping;

        foreach ($link->mappings as $targetField => $config) {
            if (!is_array($config) || !isset($config['type'])) {
                continue;
            }

            $mapping = $mappingsService->get($config['type']);

            if (!$changed && $mapping->hasChanged($element, $targetField, $item, $config, $link)) {
                $changed = true;
            }

            $mapping->apply($element, $targetField, $item, $config, $link);
        }

        $afterMappingEvent = new SyncItemEvent([
            'link'       => $link,
            'item'       => $item,
            'element'    => $element,
            'siteHandle' => $siteHandle,
        ]);
        $this->trigger(self::EVENT_AFTER_ITEM_MAPPING, $afterMappingEvent);

        if (!$changed) {
            $plugin->logs->recordItem($log, 'unchanged', $element->id, (string)$matchValue);
            $afterEvent = new SyncItemEvent([
                'link' => $link, 'item' => $item, 'element' => $element,
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
            'link' => $link, 'item' => $item, 'element' => $element,
            'siteHandle' => $siteHandle, 'action' => $action,
        ]);
        $this->trigger(self::EVENT_AFTER_ITEM, $afterEvent);
    }

    private function resolveTarget(Link $link): ElementTargetInterface
    {
        $target = Influx::getInstance()->targets->forLink($link);
        if (!$target) {
            throw new InfluxException("No element target registered for '{$link->elementType}'.");
        }
        return $target;
    }

    private function agoQueryParams(Link $link, ?string $ago): array
    {
        if (!$ago || !isset($link->ago[$ago])) {
            return [];
        }

        $preset = $link->ago[$ago];
        if (!isset($preset['since'], $preset['queryParam'])) {
            return [];
        }

        $since = (new \DateTime())->modify($preset['since']);
        $format = $preset['format'] ?? \DateTimeInterface::ATOM;

        return [$preset['queryParam'] => $since->format($format)];
    }
}
