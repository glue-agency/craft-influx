<?php

namespace GlueAgency\Influx\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;
use Throwable;

/**
 * Orchestrates a CP-triggered link sync: takes ONE pre-run DB backup (when the
 * link wants one), then fans out the actual work — one {@see SyncLinkJob} per
 * configured site, or a single job. Keeping the backup here rather than in each
 * SyncLinkJob means a multi-site fan-out dumps the DB once (not once per site),
 * and the CP request that queued this returns instantly instead of blocking on
 * the dump.
 *
 * On backup failure the run is aborted: a failed log is recorded (so it shows
 * in the Logs overview with the error badge) and NO sync jobs are enqueued — a
 * destructive missing-elements sweep must never run without its backup. The job
 * does not rethrow (that would retry the failing backup on a loop); the failed
 * log is the durable signal.
 */
class PrepareSyncJob extends BaseJob
{
    public string $linkHandle = '';

    public ?string $offset = null;

    public ?string $site = null;

    public string $trigger = 'cp';

    public function execute($queue): void
    {
        $plugin = Influx::getInstance();
        $link = $plugin->links->getLinkByHandle($this->linkHandle);

        if (! $link) {
            // Link removed between queueing and running — nothing to do.
            return;
        }

        $trigger = SyncTrigger::tryFrom($this->trigger) ?? SyncTrigger::CP;

        try {
            $plugin->backup->backupForLink($link);
        } catch (Throwable $e) {
            // Backup failed — record a failed log, enqueue nothing; not rethrown
            // so the queue won't retry the failing backup
            $log = $plugin->logs->start($link, $trigger, $this->site, $this->offset);
            $plugin->logs->fail($log, $e->getMessage());
            Craft::error($e, __METHOD__);

            return;
        }

        // Fan out: one job per site for an all-sites multi-endpoint link, else a
        // single job — each skips its own backup (this job already took it)
        $siteHandles = $link->siteHandles();
        $sites = ($this->site === null && count($siteHandles) > 1) ? $siteHandles : [$this->site];

        foreach ($sites as $site) {
            Craft::$app->getQueue()->push(new SyncLinkJob([
                'linkHandle' => $link->handle,
                'offset'     => $this->offset,
                'site'       => $site,
                'trigger'    => $trigger->value,
            ]));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('influx', 'Preparing sync for influx link “{handle}”', [
            'handle' => $this->linkHandle,
        ]);
    }
}
