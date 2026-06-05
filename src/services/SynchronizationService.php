<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use TDM\Influx\Influx;
use TDM\Influx\events\SyncLinkEvent;
use TDM\Influx\events\SyncItemEvent;
use TDM\Influx\exceptions\InfluxException;
use TDM\Influx\models\OffsetPreset;
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
     * @param string|null $offset Key into $link->offset presets, applied as a query param.
     * @param string $trigger One of: console, cp, queue, element
     */
    public function syncLink(
        Link $link,
        ?string $offset = null,
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

        [$queryParams] = OffsetPreset::forLink($link, $offset)?->resolve() ?? [[], null];

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

            $nextHeaders = [];
            $nextQuery = [];
            $link->applyAuth($nextHeaders, $nextQuery);
            $data = $plugin->data->fetchUrl($nextUrl, $nextHeaders, $nextQuery);
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
            $matchAttr = $link->matchAttribute() ?: '?';
            $node = $link->mappings[$matchAttr]['node'] ?? '?';
            $plugin->logs->recordItem(
                $log,
                'skipped',
                null,
                null,
                "Remote item has no value at match path '{$node}' (match attribute: {$matchAttr}).",
                $item,
            );
            return;
        }

        $siteId = $siteHandle
            ? (Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id)
            : null;

        $element = $target->findByMatchValue($link, $matchValue, $siteId);

        $beforeEvent = new SyncItemEvent([
            'link'       => $link,
            'item'       => $item,
            'element'    => $element,
            'siteHandle' => $siteHandle,
        ]);
        $this->trigger(self::EVENT_BEFORE_ITEM, $beforeEvent);

        if ($beforeEvent->skip) {
            $plugin->logs->recordItem($log, 'skipped', $element?->id, (string)$matchValue, null, $item);
            return;
        }

        // Allow listeners to swap in a different element.
        $element = $beforeEvent->element;

        switch ($link->decideAction($matchValue, $element)) {
            case Link::DECISION_SKIP_NO_CREATE:
                $plugin->logs->recordItem($log, 'skipped', null, (string)$matchValue, "No existing element and 'create' not enabled.", $item);
                return;
            case Link::DECISION_SKIP_NO_UPDATE:
                $plugin->logs->recordItem($log, 'skipped', $element->id, (string)$matchValue, "'update' not enabled for this link.", $item);
                return;
            case Link::DECISION_CREATE:
                $element = $target->buildNew($link, $siteId);
                $target->assignMatchValue($element, $link, $matchValue);
                $isNew = true;
                break;
            case Link::DECISION_UPDATE:
            default:
                $isNew = false;
                break;
        }

        if ($siteId) {
            $element->siteId = $siteId;
        }

        $changed = (new \TDM\Influx\sync\MappingApplier())->apply($target, $element, $link, $item, $isNew);

        $afterMappingEvent = new SyncItemEvent([
            'link'       => $link,
            'item'       => $item,
            'element'    => $element,
            'siteHandle' => $siteHandle,
        ]);
        $this->trigger(self::EVENT_AFTER_ITEM_MAPPING, $afterMappingEvent);

        if (!$changed) {
            $plugin->logs->recordItem($log, 'unchanged', $element->id, (string)$matchValue, null, $item);
            $this->fireAfterItem($link, $item, $element, $siteHandle, 'unchanged');
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
            $item,
        );

        $this->fireAfterItem($link, $item, $element, $siteHandle, $action);
    }

    private function fireAfterItem(
        Link $link,
        array $item,
        ElementInterface $element,
        ?string $siteHandle,
        string $action,
    ): void {
        $afterEvent = new SyncItemEvent([
            'link'       => $link,
            'item'       => $item,
            'element'    => $element,
            'siteHandle' => $siteHandle,
            'action'     => $action,
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

}
