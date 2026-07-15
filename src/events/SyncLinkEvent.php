<?php

namespace GlueAgency\Influx\events;

use craft\events\ModelEvent;
use GlueAgency\Influx\models\Link;

/**
 * Fired before and after a link run. EVENT_BEFORE_SYNC_LINK fires once per run
 * (set `isValid` to false to cancel the whole run); EVENT_AFTER_SYNC_LINK fires
 * once per site log, each carrying that site's `siteHandle` and counters.
 *
 *   Event::on(
 *       SynchronizationService::class,
 *       SynchronizationService::EVENT_AFTER_SYNC_LINK,
 *       function (SyncLinkEvent $event) {}
 *   );
 */
class SyncLinkEvent extends ModelEvent
{
    public Link $link;

    /**
     * The site this after-event's log covers, or null for a siteless run's log.
     * Never set on the before-event (which fires once for the whole run).
     */
    public ?string $siteHandle = null;

    /** Run-level counters, populated for the after-event. */
    public int $itemsSeen = 0;
    public int $itemsCreated = 0;
    public int $itemsUpdated = 0;
    public int $itemsUnchanged = 0;
    public int $itemsSkipped = 0;
    public int $itemsDeleted = 0;
    public int $itemsDisabled = 0;
}
