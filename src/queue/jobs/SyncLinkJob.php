<?php

namespace GlueAgency\Influx\queue\jobs;

use Craft;
use craft\i18n\Translation;
use craft\queue\QueueInterface;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\sync\run\BatchState;
use yii\queue\Queue;

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
 *   - itemsSeen (int) — the progress numerator, cumulative over this scope's
 *     pages so a re-queued step resumes the percentage instead of restarting.
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
class SyncLinkJob extends AbstractLinkJob
{
    /**
     * Streamed feeds that report no total have no known denominator, so the
     * bar eases toward (without reaching) 100% as items arrive — this is the
     * soft target it curves against. A feed with count nodes shows a real %.
     */
    protected const PROGRESS_SOFT_TARGET = 250;

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
     * Feed items this scope's walk has reached — the progress numerator.
     * Carried across steps so the bar picks up where the previous step left off;
     * a step that started it back at 0 would walk the percentage backwards on
     * every page.
     */
    public int $itemsSeen = 0;

    /**
     * The last whole percent this job wrote to the queue, so the per-item
     * reports only cost a write when the number actually moves.
     *
     * Transient — set during {@see execute()}, never meaningful in a pushed
     * payload (a re-push builds a fresh job, and Craft resets a deserialised
     * job's progress anyway).
     */
    protected ?int $lastProgress = null;

    /**
     * Runs one step of this scope's walk. The trigger is resolved with
     * `tryFrom()` so an unexpected value degrades to {@see SyncTrigger::QUEUE}
     * instead of throwing. While pages remain, the next step is re-queued on the
     * same log.
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
            fn(int $seen, ?int $total) => $this->reportProgress($queue, $seen, $total),
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
     * Push this step's progress to the queue. A real percentage when the feed
     * reports a total (via the link's count nodes), otherwise the soft-target
     * curve easing toward — never reaching — 100%, with the live count in the
     * label either way.
     *
     * Called once per feed item ({@see \GlueAgency\Influx\sync\run\PageWalker}),
     * so it throttles itself to whole-percent movements: Craft's own guard in
     * `setProgress()` can't do it for us, since the count in the label differs on
     * every single item and a changed label is enough to trigger a write. The
     * label is only built once the percentage has actually moved.
     */
    protected function reportProgress(Queue|QueueInterface $queue, int $seen, ?int $total): void
    {
        $hasTotal = $total !== null && $total > 0;

        $progress = $hasTotal
            ? min(1.0, $seen / $total)
            : 1 - 1 / (1 + $seen / self::PROGRESS_SOFT_TARGET);

        $percent = (int) round($progress * 100);

        if ($percent === $this->lastProgress) {
            return;
        }

        $this->lastProgress = $percent;

        $label = $hasTotal
            ? Translation::prep('influx', '{count} of {total} items synced', [
                'count' => $seen,
                'total' => $total,
            ])
            : Translation::prep('influx', '{count} items synced', ['count' => $seen]);

        $this->setProgress($queue, $progress, $label);
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
            itemsSeen: $this->itemsSeen,
        );
    }

    /**
     * Four whole sentences rather than one with an assembled suffix: both the
     * offset preset and the site are legitimately absent, and a translator needs
     * to move the clauses, not just fill the blanks.
     *
     * Prepped rather than translated ({@see Translation::prep()}), per
     * {@see \craft\queue\BaseJob::defaultDescription()}: the description is
     * captured when the job is pushed, so translating it here would freeze it in
     * the language of whoever triggered the run instead of each viewer's own.
     */
    protected function defaultDescription(): ?string
    {
        $link = $this->linkLabel();
        $offset = $this->offsetLabel();
        $site = $this->siteLabel();

        if ($offset !== null && $site !== null) {
            return Translation::prep('influx', 'Importing {link} with {offset} for site {site}', [
                'link'   => $link,
                'offset' => $offset,
                'site'   => $site,
            ]);
        }

        if ($site !== null) {
            return Translation::prep('influx', 'Importing {link} for site {site}', [
                'link' => $link,
                'site' => $site,
            ]);
        }

        if ($offset !== null) {
            return Translation::prep('influx', 'Importing {link} with {offset}', [
                'link'   => $link,
                'offset' => $offset,
            ]);
        }

        return Translation::prep('influx', 'Importing {link}', ['link' => $link]);
    }
}
