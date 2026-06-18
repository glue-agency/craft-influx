<?php

namespace GlueAgency\Influx\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;

/**
 * Queue job that runs a link sync one feed page per step: each execution
 * processes a single page and, if there's another page (or another site),
 * re-queues itself with the carried run state — so one log spans the whole run
 * while no single step holds the worker long enough to time out on a big feed.
 * A feed with no paginator simply finishes after the first step.
 *
 * CP-side triggers push the job (with no state) so the request returns
 * immediately; console runs stay synchronous via {@see \GlueAgency\Influx\services\SynchronizationService::syncLink()}.
 */
class SyncLinkJob extends BaseJob
{
    /**
     * Streamed feeds that report no total have no known denominator, so the
     * bar eases toward (without reaching) 100% as items arrive — this is the
     * soft target it curves against. A feed with count nodes shows a real %.
     */
    protected const PROGRESS_SOFT_TARGET = 250;

    public string $linkHandle = '';
    public ?string $offset = null;
    public ?string $site = null;
    public string $trigger = 'queue';

    // Run state carried across steps; defaults are the first step's values, so
    // an initial push (handle/offset/site/trigger only) starts a fresh run.
    public ?int $logId = null;
    public int $siteIndex = 0;
    public ?string $cursorUrl = null;
    public int $page = 1;

    public function execute($queue): void
    {
        // tryFrom (not from) so a job serialised with an unexpected trigger
        // value degrades to QUEUE instead of throwing a raw ValueError.
        $trigger = SyncTrigger::tryFrom($this->trigger) ?? SyncTrigger::QUEUE;

        $state = Influx::getInstance()->synchronization->batchStep(
            $this->linkHandle,
            $this->offset,
            $trigger,
            $this->site,
            [
                'logId'     => $this->logId,
                'siteIndex' => $this->siteIndex,
                'cursorUrl' => $this->cursorUrl,
                'page'      => $this->page,
            ],
            function(int $seen, ?int $total) use ($queue): void {
                if ($total !== null && $total > 0) {
                    // The feed reported a total (via the count nodes) — a real %.
                    $progress = min(1.0, $seen / $total);
                    $label = Craft::t('influx', '{count} of {total} items synced', [
                        'count' => $seen,
                        'total' => $total,
                    ]);
                } else {
                    // No total: ease the bar toward 1 as items arrive (never
                    // reaching it); the label carries the live count.
                    $progress = 1 - 1 / (1 + $seen / self::PROGRESS_SOFT_TARGET);
                    $label = Craft::t('influx', '{count} items synced', ['count' => $seen]);
                }

                $this->setProgress($queue, $progress, $label);
            },
        );

        if (empty($state['done'])) {
            // More pages (or sites) to go — re-queue the next step on the same
            // log so the whole run reads as one log entry.
            Craft::$app->getQueue()->push(new self([
                'linkHandle' => $this->linkHandle,
                'offset'     => $this->offset,
                'site'       => $this->site,
                'trigger'    => $this->trigger,
                'logId'      => $state['logId'],
                'siteIndex'  => $state['siteIndex'],
                'cursorUrl'  => $state['cursorUrl'],
                'page'       => $state['page'],
            ]));
        }
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
