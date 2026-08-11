<?php

namespace GlueAgency\Influx\sync;

use Craft;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\item\ElementLookupCache;
use GlueAgency\Influx\sync\item\ItemProcessor;
use GlueAgency\Influx\sync\run\MissingElementsSweeper;
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
     * The partial-import preset this run used (null = the full feed).
     * A partial (offset) run must NEVER run the missing-elements sweep: its
     * seen-set covers only the window, so the complement isn't missing — it's
     * just outside the slice. Deleting/disabling it would wipe everything
     * beyond the window. {@see MissingElementsSweeper::plan()}
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

    /**
     * Per-run scratch memo for the run-scoped lookups that don't warrant a cache
     * class of their own — a target's user-group map, say. Constructed and
     * isolated exactly like {@see $lookups}; it exists because targets are shared
     * prototypes that must not memoize on themselves ({@see RunMemo}).
     */
    public RunMemo $memo;

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
        $this->memo = new RunMemo();
    }

    /**
     * Build a context for a run against a given site handle, resolving the
     * handle to its site id. THE one place that handle → id lookup lives — the
     * sync run, the per-element sync, and the debug inspector all build their
     * contexts through here instead of repeating the lookup. A null handle
     * means the primary site (id stays null, which Craft reads as "default").
     *
     * A configured handle that no longer resolves throws instead: it must NOT
     * fall back to `siteId = null`, which downstream reads as a cross-site
     * sweep.
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
