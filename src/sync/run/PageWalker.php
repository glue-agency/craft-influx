<?php

namespace GlueAgency\Influx\sync\run;

use GlueAgency\Influx\data\FeedPage;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\sync\item\ItemRunner;
use GlueAgency\Influx\sync\SyncContext;
use Throwable;

/**
 * The one per-page primitive: run every item on ONE fetched page, fold the
 * results into the walk's accumulator, persist the page's rows, and report
 * progress. Both walk paths consume this — the synchronous
 * {@see \GlueAgency\Influx\services\SynchronizationService::processSite()} loop
 * and the queued, one-page-per-step
 * {@see \GlueAgency\Influx\services\SynchronizationService::batchStep()} — so the
 * two can never drift onto different per-item, flush or progress semantics again.
 *
 * One bad item must not kill the walk: a per-item failure becomes an ERROR row
 * and the page carries on. An item that failed WITHOUT a resolvable element also
 * bumps the walk's unattributed-error count, because it leaves the seen-set
 * incomplete and must disable the missing-elements sweep.
 *
 * Rows flush at the page boundary in a `finally`, so a throw escaping the loop
 * still persists what this page saved — leaving a retried queue step only the
 * un-flushed tail to redo. Progress is reported once per page rather than per
 * item to keep queue writes bounded, and only on the normal path: a page that
 * threw has nothing meaningful to report.
 *
 * The mutex deliberately stays OUTSIDE: the synchronous path holds the link's
 * sync lock for its whole run, the queued path takes it per step, and neither
 * boundary belongs to a single page.
 */
class PageWalker
{
    protected ItemRunner $itemRunner;

    public function __construct(ItemRunner $itemRunner)
    {
        $this->itemRunner = $itemRunner;
    }

    /**
     * @param callable|null $onProgress fn(int $seen, ?int $total): called once
     * per page with the log's running items-seen count and the estimated total
     * ({@see PageWalk::estimateTotal()}). Null for runs that don't report.
     */
    public function walk(SyncContext $context, FeedPage $page, LogRecord $log, PageWalk $walk, ?callable $onProgress = null): void
    {
        $logs = Influx::getInstance()->logs;
        $walk->firstPageSize ??= count($page->items);

        try {
            foreach ($page->items as $item) {
                try {
                    $elementId = $this->itemRunner->run($context, $item, $log);

                    if ($elementId !== null) {
                        $walk->markSeen($elementId);
                    }
                } catch (Throwable $e) {
                    $logs->recordItem($log, ItemAction::ERROR, null, null, $e->getMessage(), $item->raw());
                    $walk->unattributedErrors++;
                }
            }
        } finally {
            $logs->flush($log);
        }

        if ($onProgress !== null) {
            $onProgress((int) $log->itemsSeen, $walk->estimateTotal($page));
        }
    }
}
