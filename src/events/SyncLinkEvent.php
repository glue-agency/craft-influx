<?php

namespace GlueAgency\Influx\events;

use craft\events\ModelEvent;
use GlueAgency\Influx\models\Link;

/**
 * Fired before and after a link run. `isValid` on the before-event can be
 * set to false to cancel the run.
 */
class SyncLinkEvent extends ModelEvent
{
    public Link $link;

    /** @var string|null Optional site handle when the event fires per-site. */
    public ?string $siteHandle = null;

    /** Run-level counters, populated for the after-event. */
    public int $itemsSeen = 0;
    public int $itemsCreated = 0;
    public int $itemsUpdated = 0;
    public int $itemsUnchanged = 0;
    public int $itemsSkipped = 0;
    public int $itemsDeleted = 0;
}
