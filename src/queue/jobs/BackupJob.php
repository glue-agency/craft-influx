<?php

namespace GlueAgency\Influx\queue\jobs;

use Craft;
use craft\queue\BaseJob;
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
class BackupJob extends BaseJob
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

        // Backup done — fan out the per-site sync jobs (each skips its own backup).
        $plugin->synchronization->queueSyncJobs($link, $this->offset, $this->site, $trigger);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('influx', 'Taking backup for influx link “{handle}”', [
            'handle' => $this->linkHandle,
        ]);
    }
}
