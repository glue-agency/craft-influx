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
 *
 * Carried run state (all re-pushed verbatim each step):
 *   - logId/siteIndex/cursorUrl/page — where the walk is.
 *   - seenIds (list<int>) / unattributedErrors (int) — THIS site's set, feeding
 *     the PER-SITE sweep (disable / delete-for-site). Reset when a site's last
 *     page is done. Dropping them between steps would make the sweep
 *     over-disable (it would only ever "see" the final page's items), so they
 *     must survive across steps. unattributedErrors counts items that failed
 *     WITHOUT a resolvable element — any at all makes the site's sweep bail.
 *   - runSeenIds (list<int>) / runUnattributedErrors (int) — the UNION across
 *     every site, feeding the RUN-END global-delete sweep (an element is
 *     deleted only when NO site's feed mentioned it). NEVER reset mid-run; only
 *     accumulated when the link carries the DELETE flag, so disable-only runs
 *     don't carry a set nothing reads. See
 *     {@see \GlueAgency\Influx\services\SynchronizationService::batchStep()} —
 *     the per-site and run-end sweeps COMPOSE, so both pairs ride the payload.
 *
 * runSeenIds rides the serialised job payload, so its size grows with the item
 * count: fine for feeds up to tens of thousands of ids; a feed far larger than
 * that would bloat the job row and should page the sweep differently — and the
 * global-delete union spans every site, so it's the larger case.
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

    /**
     * Element ids THIS site's feed has mentioned so far, feeding the per-site
     * sweep (disable / delete-for-site). Excluded from that sweep — MUST
     * survive across steps or the sweep over-disables. Reset when a site's last
     * page is done, by
     * {@see \GlueAgency\Influx\services\SynchronizationService::batchStep()}.
     *
     * @var list<int>
     */
    public array $seenIds = [];

    /**
     * Items that failed with no resolvable element on THIS site — any at all
     * makes the site's sweep bail. Carried across steps alongside
     * {@see self::$seenIds}; reset per site.
     */
    public int $unattributedErrors = 0;

    /**
     * The UNION of every site's seen element ids, feeding the run-end
     * global-delete sweep (an element is deleted only when NO site's feed
     * mentioned it). NEVER reset mid-run; only accumulated when the link
     * carries the DELETE flag. Composes with {@see self::$seenIds} — both pairs
     * ride every step's payload.
     *
     * @var list<int>
     */
    public array $runSeenIds = [];

    /**
     * Run-wide count of items that failed with no resolvable element — any at
     * all makes the run-end global-delete sweep bail. Carried alongside
     * {@see self::$runSeenIds}; never reset mid-run.
     */
    public int $runUnattributedErrors = 0;

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
                'logId'                 => $this->logId,
                'siteIndex'             => $this->siteIndex,
                'cursorUrl'             => $this->cursorUrl,
                'page'                  => $this->page,
                'seenIds'               => $this->seenIds,
                'unattributedErrors'    => $this->unattributedErrors,
                'runSeenIds'            => $this->runSeenIds,
                'runUnattributedErrors' => $this->runUnattributedErrors,
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
                'linkHandle'            => $this->linkHandle,
                'offset'                => $this->offset,
                'site'                  => $this->site,
                'trigger'               => $this->trigger,
                'logId'                 => $state['logId'],
                'siteIndex'             => $state['siteIndex'],
                'cursorUrl'             => $state['cursorUrl'],
                'page'                  => $state['page'],
                'seenIds'               => $state['seenIds'],
                'unattributedErrors'    => $state['unattributedErrors'],
                'runSeenIds'            => $state['runSeenIds'],
                'runUnattributedErrors' => $state['runUnattributedErrors'],
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
