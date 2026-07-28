<?php

namespace GlueAgency\Influx\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\sync\run\BatchState;

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
 *   - firstPageSize (?int) — the progress denominator's multiplier, fixed on the
 *     first page so a short final page can't shrink the estimate mid-run.
 *
 * These stay individual PUBLIC PROPERTIES because they are the serialised queue
 * payload; {@see BatchState} is the value object they convert to and from at the
 * {@see \GlueAgency\Influx\services\SynchronizationService::batchStep()} boundary,
 * and it owns the key names so they aren't spelled out a third time here.
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

    public ?int $logId = null;
    public ?string $cursorUrl = null;
    public int $page = 1;

    /**
     * Element ids this scope's feed has mentioned so far, feeding the
     * missing-elements sweep. Excluded from that sweep — MUST survive across
     * steps or the sweep over-disables. See {@see BatchState}.
     *
     * @var list<int>
     */
    public array $seenIds = [];

    /**
     * Items that failed with no resolvable element in this scope — any at all
     * makes the sweep bail. Carried across steps alongside {@see self::$seenIds}.
     */
    public int $unattributedErrors = 0;

    /**
     * Items on the first page of this scope's walk — the multiplier behind the
     * pages × page-size progress estimate
     * ({@see \GlueAgency\Influx\sync\run\PageWalk::estimateTotal()}).
     * Carried across steps so the denominator stays put when the final page
     * comes back short. Null until the first page lands.
     */
    public ?int $firstPageSize = null;

    /**
     * Runs one step of this scope's walk. The trigger is resolved with
     * `tryFrom()` so an unexpected value degrades to {@see SyncTrigger::QUEUE}
     * instead of throwing. Progress is a real percentage when the feed reports
     * a total (via the link's count nodes) and otherwise eases toward 1 as
     * items arrive, with the live count carried in the label. While pages
     * remain, the next step is re-queued on the same log.
     *
     * The carried state travels as a {@see BatchState}, which owns the key names
     * so this job only has to name its own properties: {@see carriedState()}
     * converts them in, and {@see BatchState::carried()} converts the step's
     * answer back out into the re-pushed payload.
     */
    public function execute($queue): void
    {
        $trigger = SyncTrigger::tryFrom($this->trigger) ?? SyncTrigger::QUEUE;

        $state = Influx::getInstance()->synchronization->batchStep(
            $this->linkHandle,
            $this->offset,
            $trigger,
            $this->site,
            $this->carriedState()->toArray(),
            function(int $seen, ?int $total) use ($queue): void {
                if ($total !== null && $total > 0) {
                    $progress = min(1.0, $seen / $total);
                    $label = Craft::t('influx', '{count} of {total} items synced', [
                        'count' => $seen,
                        'total' => $total,
                    ]);
                } else {
                    $progress = 1 - 1 / (1 + $seen / self::PROGRESS_SOFT_TARGET);
                    $label = Craft::t('influx', '{count} items synced', ['count' => $seen]);
                }

                $this->setProgress($queue, $progress, $label);
            },
        );

        if (empty($state['done'])) {
            Craft::$app->getQueue()->push(new self([
                'linkHandle' => $this->linkHandle,
                'offset'     => $this->offset,
                'site'       => $this->site,
                'trigger'    => $this->trigger,
            ] + BatchState::fromArray($state)->carried()));
        }
    }

    /**
     * This job's carried properties as the state a step resumes from — the one
     * place the payload becomes a {@see BatchState}.
     */
    protected function carriedState(): BatchState
    {
        return new BatchState(
            logId: $this->logId,
            cursorUrl: $this->cursorUrl,
            page: $this->page,
            seenIds: $this->seenIds,
            unattributedErrors: $this->unattributedErrors,
            firstPageSize: $this->firstPageSize,
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
