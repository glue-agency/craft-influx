<?php

namespace GlueAgency\Influx\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;

/**
 * Queue job that runs one scope of a link sync, one feed page per step: each
 * execution processes a single page and, if there's another page, re-queues
 * itself with the carried run state — so one log spans this job's scope while
 * no single step holds the worker long enough to time out on a big feed. A feed
 * with no paginator simply finishes after the first step.
 *
 * ONE JOB PER SCOPE: an all-sites CP trigger fans out to one job per configured
 * site (each with its own `site`, its own log); a single-site or
 * no-site-endpoints trigger pushes one job. A job never advances to another
 * site — it walks its own scope's pages, sweeps once, and finishes.
 *
 * CP-side triggers push the job (with no state) so the request returns
 * immediately; console runs stay synchronous via {@see \GlueAgency\Influx\services\SynchronizationService::syncLink()}.
 *
 * Carried run state (all re-pushed verbatim each step):
 *   - logId/cursorUrl/page — where the walk is.
 *   - seenIds (list<int>) / unattributedErrors (int) — this scope's set, feeding
 *     the missing-elements sweep. Dropping them between steps would make the
 *     sweep over-disable (it would only ever "see" the final page's items), so
 *     they must survive across steps. unattributedErrors counts items that
 *     failed WITHOUT a resolvable element — any at all makes the sweep bail.
 *
 * seenIds rides the serialised job payload, so its size grows with the item
 * count: fine for feeds up to tens of thousands of ids; a feed far larger than
 * that would bloat the job row and should page the sweep differently.
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
    public ?string $cursorUrl = null;
    public int $page = 1;

    /**
     * Element ids this scope's feed has mentioned so far, feeding the
     * missing-elements sweep. Excluded from that sweep — MUST survive across
     * steps or the sweep over-disables. See
     * {@see \GlueAgency\Influx\services\SynchronizationService::batchStep()}.
     *
     * @var list<int>
     */
    public array $seenIds = [];

    /**
     * Items that failed with no resolvable element in this scope — any at all
     * makes the sweep bail. Carried across steps alongside {@see self::$seenIds}.
     */
    public int $unattributedErrors = 0;

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
                'logId'              => $this->logId,
                'cursorUrl'          => $this->cursorUrl,
                'page'               => $this->page,
                'seenIds'            => $this->seenIds,
                'unattributedErrors' => $this->unattributedErrors,
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
            // More pages to go — re-queue the next step on the same log so this
            // scope's whole walk reads as one log entry.
            Craft::$app->getQueue()->push(new self([
                'linkHandle'         => $this->linkHandle,
                'offset'             => $this->offset,
                'site'               => $this->site,
                'trigger'            => $this->trigger,
                'logId'              => $state['logId'],
                'cursorUrl'          => $state['cursorUrl'],
                'page'               => $state['page'],
                'seenIds'            => $state['seenIds'],
                'unattributedErrors' => $state['unattributedErrors'],
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
