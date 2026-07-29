<?php

namespace GlueAgency\Influx\queue\jobs;

use Craft;
use craft\i18n\Translation;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;
use Throwable;

/**
 * Takes the pre-run DB backup for a queued link sync, then fans out the actual
 * work via {@see \GlueAgency\Influx\services\SynchronizationService::queueSyncJobs()}.
 *
 * Only enqueued when the link actually wants a backup — {@see
 * \GlueAgency\Influx\services\SynchronizationService::queueSync()} skips straight
 * to the fan-out otherwise, so a no-backup sync never pays for this extra hop.
 * Splitting the backup into its own job means the triggering request returns
 * instantly instead of blocking on the dump, and a multi-site fan-out dumps the
 * DB once rather than once per site.
 *
 * On backup failure the run is aborted: a failed log is recorded (so it shows in
 * the Logs overview with the error badge) and NO sync jobs are enqueued — a
 * destructive missing-elements sweep must never run without its backup. The job
 * does not rethrow (that would retry the failing backup on a loop); the failed
 * log is the durable signal.
 */
class BackupJob extends AbstractLinkJob
{
    /**
     * The link can be deleted between queueing and running, hence the
     * missing-link guard.
     */
    public function execute($queue): void
    {
        $plugin = Influx::getInstance();
        $link = $plugin->links->getLinkByHandle($this->linkHandle);

        if (! $link) {
            return;
        }

        $trigger = SyncTrigger::tryFrom($this->trigger) ?? SyncTrigger::CP;

        try {
            $plugin->backup->backupForLink($link);
        } catch (Throwable $e) {
            $log = $plugin->logs->start($link, $trigger, $this->site, $this->offset);
            $plugin->logs->fail($log, $e->getMessage());
            Craft::error($e, __METHOD__);

            return;
        }

        $plugin->synchronization->queueSyncJobs($link, $this->offset, $this->site, $trigger);
    }

    /**
     * Names the link and nothing else: the dump is DB-wide and taken once for
     * the whole fan-out, so this job's site and offset say nothing about it.
     * Prepped rather than translated — see {@see SyncLinkJob::defaultDescription()}.
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('influx', 'Backing up the database before importing {link}', [
            'link' => $this->linkLabel(),
        ]);
    }
}
