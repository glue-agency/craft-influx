<?php

namespace GlueAgency\Influx\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use GlueAgency\Influx\Influx;

/**
 * Shared base for the queue jobs that act on ONE link scope: the four
 * properties that identify the scope, and the human labels a description is
 * built from.
 *
 * The four properties are the serialised queue payload every subclass carries,
 * spelled out here so the two jobs can't drift on their names or types. Each
 * subclass owns the {@see SyncTrigger} its `execute()` falls back to when
 * `$trigger` doesn't resolve, which is why no default is asserted here.
 *
 * Labels are resolved at DESCRIPTION time, not at push time, and each one falls
 * back to its handle: a link can be deleted (or a site removed) between the push
 * and the run, and a queue row must still read as something.
 */
abstract class AbstractLinkJob extends BaseJob
{
    /** Handle of the link being synced. */
    public string $linkHandle = '';

    /** Sliding-window preset handle from the link's `offset` map, or null for a full run. */
    public ?string $offset = null;

    /**
     * Site handle this job's scope covers. Null is meaningful: it's the single
     * unscoped scope of a link with no per-site endpoints, NOT "every site".
     */
    public ?string $site = null;

    /** {@see \GlueAgency\Influx\enums\SyncTrigger} value that kicked the run off. */
    public string $trigger = '';

    /**
     * The link's display name, falling back to its handle.
     *
     * Reads the cached, handle-keyed set rather than querying by handle: a
     * description is built on every push, and a paginated run re-pushes once per
     * feed page.
     */
    protected function linkLabel(): string
    {
        $link = Influx::getInstance()->links->getAllLinks()[$this->linkHandle] ?? null;

        return $link?->name ?: $this->linkHandle;
    }

    /**
     * This scope's site name, or null when the job carries no site — there's no
     * site to name on the unscoped scope, so the clause is left out of the
     * description entirely rather than filled with a placeholder.
     */
    protected function siteLabel(): ?string
    {
        if (($this->site ?? '') === '') {
            return null;
        }

        return Craft::$app->getSites()->getSiteByHandle($this->site)?->name ?: $this->site;
    }

    /**
     * The sliding-window preset this run applies, or null for a full run. A
     * preset has no name of its own — its handle IS its label, here and
     * everywhere else in the UI.
     */
    protected function offsetLabel(): ?string
    {
        return ($this->offset ?? '') !== '' ? $this->offset : null;
    }
}
