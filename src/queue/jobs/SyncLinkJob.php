<?php

namespace TDM\Influx\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use TDM\Influx\enums\SyncTrigger;
use TDM\Influx\exceptions\InfluxException;
use TDM\Influx\Influx;

/**
 * Queue job that runs a full link sync. CP-side triggers push this job so the
 * web request returns immediately and the actual work happens in the worker.
 * Console runs stay synchronous — operators usually want to wait for output.
 */
class SyncLinkJob extends BaseJob
{
    public string $linkHandle = '';
    public ?string $offset = null;
    public string $trigger = 'queue';

    /**
     * @throws InfluxException when the link no longer exists — the job must
     * fail visibly in the queue instead of silently succeeding.
     */
    public function execute($queue): void
    {
        $plugin = Influx::getInstance();
        $link = $plugin->links->getLinkByHandle($this->linkHandle);
        if (!$link) {
            throw new InfluxException("Cannot sync link '{$this->linkHandle}' — no link with that handle exists.");
        }

        $plugin->synchronization->syncLink($link, $this->offset, SyncTrigger::from($this->trigger));
    }

    protected function defaultDescription(): ?string
    {
        $suffix = $this->offset ? " (preset: {$this->offset})" : '';
        return Craft::t('influx', 'Syncing influx link “{handle}”{suffix}', [
            'handle' => $this->linkHandle,
            'suffix' => $suffix,
        ]);
    }
}
