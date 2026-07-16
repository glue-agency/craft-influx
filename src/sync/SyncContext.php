<?php

namespace GlueAgency\Influx\sync;

use Craft;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\ElementTargetInterface;

/**
 * Everything that's constant for the duration of one (link, site) run:
 * which link, which target, which site, what kicked it off, and whether
 * writes are allowed. Built once per site by the callers of
 * {@see ItemProcessor} and threaded through all three phases; treat as
 * read-only.
 */
class SyncContext
{
    public Link $link;

    public ElementTargetInterface $target;

    public ?int $siteId = null;

    public ?string $siteHandle = null;

    /**
     * Null for runs that aren't syncs at all — the debug inspector builds
     * contexts without a trigger.
     */
    public ?SyncTrigger $trigger = null;

    /**
     * When true {@see ItemProcessor::commit()} never persists, and the flag
     * flows into every {@see FieldContext} so field strategies skip their
     * side effects too.
     */
    public bool $dryRun = false;

    /**
     * The sliding-window offset preset this run used (null = the full feed).
     * A partial (offset) run must NEVER run the missing-elements sweep: its
     * seen-set covers only the window, so the complement isn't missing — it's
     * just outside the slice. Deleting/disabling it would wipe everything
     * beyond the window. {@see \GlueAgency\Influx\services\SynchronizationService::sweepMissing()}
     * gates the sweep on this: only a full sync may delete or disable.
     */
    public ?string $offsetHandle = null;

    /**
     * Per-run memo of element lookups (relations, authors). Isolation is
     * automatic: every runner builds a fresh context, so one run never reads
     * another's cache. A queued, page-per-step run builds a context per step,
     * so its cache spans a single page rather than the whole run — fewer hits,
     * still correct. Constructed here, never injected.
     */
    public ElementLookupCache $lookups;

    public function __construct(
        Link $link,
        ElementTargetInterface $target,
        ?int $siteId = null,
        ?string $siteHandle = null,
        ?SyncTrigger $trigger = null,
        ?string $offsetHandle = null,
        bool $dryRun = false,
    ) {
        $this->link = $link;
        $this->target = $target;
        $this->siteId = $siteId;
        $this->siteHandle = $siteHandle;
        $this->trigger = $trigger;
        $this->offsetHandle = $offsetHandle;
        $this->dryRun = $dryRun;
        $this->lookups = new ElementLookupCache();
    }

    /**
     * Build a context for a run against a given site handle, resolving the
     * handle to its site id. THE one place that handle → id lookup lives — the
     * sync run, the per-element sync, and the debug inspector all build their
     * contexts through here instead of repeating the lookup. A null handle
     * means the primary site (id stays null, which Craft reads as "default").
     */
    public static function forSite(
        Link $link,
        ElementTargetInterface $target,
        ?string $siteHandle,
        ?SyncTrigger $trigger = null,
        ?string $offsetHandle = null,
        bool $dryRun = false,
    ): self {
        $siteId = null;

        if ($siteHandle !== null) {
            $siteId = Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id;

            // A missing configured site must NOT fall back to siteId=null —
            // downstream that reads as a cross-site sweep
            if ($siteId === null) {
                throw new InfluxException("Link '{$link->handle}' is configured for site '{$siteHandle}', which no longer exists — refusing to run a per-site sync that would degrade into a cross-site sweep.");
            }
        }

        return new self(
            link: $link,
            target: $target,
            siteId: $siteId,
            siteHandle: $siteHandle,
            trigger: $trigger,
            offsetHandle: $offsetHandle,
            dryRun: $dryRun,
        );
    }
}
