<?php

namespace GlueAgency\Influx\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\Influx;

/**
 * Queue job that runs a full link sync. CP-side triggers push this job so the
 * web request returns immediately and the actual work happens in the worker.
 * Console runs stay synchronous — operators usually want to wait for output.
 */
class SyncLinkJob extends BaseJob
{
    /**
     * Streamed feeds have no known total, so the progress bar eases toward
     * (without reaching) 100% as items arrive — this is the soft target it
     * curves against. The job completing is what marks the run done.
     */
    protected const PROGRESS_SOFT_TARGET = 250;

    public string $linkHandle = '';
    public ?string $offset = null;
    public ?string $site = null;
    public string $trigger = 'queue';

    /**
     * @throws InfluxException when the link no longer exists — the job must
     * fail visibly in the queue instead of silently succeeding.
     */
    public function execute($queue): void
    {
        $plugin = Influx::getInstance();
        $link = $plugin->links->getLinkByHandle($this->linkHandle);

        if (! $link) {
            throw new InfluxException("Cannot sync link '{$this->linkHandle}' — no link with that handle exists.");
        }

        // tryFrom (not from) so a job serialised with an unexpected trigger
        // value degrades to QUEUE instead of throwing a raw ValueError.
        $trigger = SyncTrigger::tryFrom($this->trigger) ?? SyncTrigger::QUEUE;

        $plugin->synchronization->syncLink(
            $link,
            $this->offset,
            $trigger,
            $this->site,
            function(int $seen) use ($queue): void {
                // No reliable total for a streamed feed: ease the bar toward 1
                // as items arrive (never reaching it), and carry the live count
                // in the label so the HUD shows real movement.
                $progress = 1 - 1 / (1 + $seen / self::PROGRESS_SOFT_TARGET);

                $this->setProgress($queue, $progress, Craft::t('influx', '{count} items synced', [
                    'count' => $seen,
                ]));
            },
        );
    }

    protected function defaultDescription(): ?string
    {
        $parts = array_filter([
            $this->site ? "site: {$this->site}" : null,
            $this->offset ? "preset: {$this->offset}" : null,
        ]);
        $suffix = $parts ? ' (' . implode(', ', $parts) . ')' : '';

        return Craft::t('influx', 'Syncing influx link “{handle}”{suffix}', [
            'handle' => $this->linkHandle,
            'suffix' => $suffix,
        ]);
    }
}
