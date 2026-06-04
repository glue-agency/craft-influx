<?php

namespace TDM\Influx\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use TDM\Influx\Influx;

/**
 * Queue job that runs a full link sync. CP-side triggers push this job so the
 * web request returns immediately and the actual work happens in the worker.
 * Console runs stay synchronous — operators usually want to wait for output.
 */
class SyncLinkJob extends BaseJob
{
    public string $linkHandle = '';
    public ?string $ago = null;
    public string $trigger = 'queue';

    public function execute($queue): void
    {
        $plugin = Influx::getInstance();
        $link = $plugin->links->getLinkByHandle($this->linkHandle);
        if (!$link) {
            return;
        }

        $plugin->synchronization->syncLink($link, $this->ago, $this->trigger);
    }

    protected function defaultDescription(): ?string
    {
        $suffix = $this->ago ? " (preset: {$this->ago})" : '';
        return Craft::t('influx', 'Syncing influx link “{handle}”{suffix}', [
            'handle' => $this->linkHandle,
            'suffix' => $suffix,
        ]);
    }
}
