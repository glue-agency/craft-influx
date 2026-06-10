<?php

namespace TDM\Influx\sync;

use TDM\Influx\enums\SyncTrigger;
use TDM\Influx\models\Link;
use TDM\Influx\targets\ElementTargetInterface;

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

    public function __construct(
        Link $link,
        ElementTargetInterface $target,
        ?int $siteId = null,
        ?string $siteHandle = null,
        ?SyncTrigger $trigger = null,
        bool $dryRun = false,
    ) {
        $this->link = $link;
        $this->target = $target;
        $this->siteId = $siteId;
        $this->siteHandle = $siteHandle;
        $this->trigger = $trigger;
        $this->dryRun = $dryRun;
    }
}
