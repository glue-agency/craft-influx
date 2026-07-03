<?php

namespace GlueAgency\Influx\events;

use craft\events\ModelEvent;
use GlueAgency\Influx\models\Link;

/**
 * Fired before and after a link run.
 *
 * EVENT_BEFORE_SYNC_LINK fires ONCE per run, before any site is processed;
 * setting `isValid` to false on it cancels the WHOLE run (every site).
 *
 * EVENT_AFTER_SYNC_LINK fires ONCE PER SITE LOG — an all-sites run over N
 * configured sites fires it N times, each carrying that site's `siteHandle`
 * and that site's own counters (a run is one log per site, not one log
 * spanning every site). A run over a link with no site endpoints (or scoped to
 * a single site) fires it once with `siteHandle` null / the requested handle.
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
