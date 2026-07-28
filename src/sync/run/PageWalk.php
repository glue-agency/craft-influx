<?php

namespace GlueAgency\Influx\sync\run;

use GlueAgency\Influx\data\FeedPage;

/**
 * Everything one scope's page walk accumulates across its pages: the seen-set
 * feeding the missing-elements sweep, the unattributed-error count that can
 * disable it, the progress denominator's multiplier, and the memoized
 * denominator itself.
 *
 * Deliberately MUTABLE and passed by handle: {@see PageWalker::walk()} folds each
 * page into it, so both walk paths accumulate through one implementation instead
 * of each keeping its own running totals. One walk owns one instance —
 * {@see \GlueAgency\Influx\services\SynchronizationService::processSite()} keeps
 * a single instance across every page of a synchronous run, while a queued run
 * rebuilds it from {@see BatchState} each step. That difference is exactly what
 * makes the memoized total behave right on both: a synchronous run fixes the
 * denominator on its first page, and a step re-derives it from the page it just
 * fetched.
 *
 * The seen-set is held as a value-keyed map rather than a list so a re-processed
 * tail (a retried queue step) can't double-count an id.
 */
class PageWalk
{
    /** @var array<int, true> */
    protected array $seen = [];

    /**
     * Items that failed WITHOUT a resolvable element. Any at all makes the
     * missing-elements sweep bail, since the seen-set can no longer be trusted
     * to protect everything the feed actually mentioned.
     */
    public int $unattributedErrors = 0;

    /**
     * Items on the FIRST page of this walk, fixed there so a short final page
     * can't shrink the progress estimate mid-run. Null until the first page
     * lands.
     */
    public ?int $firstPageSize = null;

    /** Memoized progress denominator — see {@see estimateTotal()}. */
    protected ?int $total = null;

    /** @param list<int> $seenIds */
    public function __construct(array $seenIds = [], int $unattributedErrors = 0, ?int $firstPageSize = null)
    {
        $this->seen = array_fill_keys($seenIds, true);
        $this->unattributedErrors = $unattributedErrors;
        $this->firstPageSize = $firstPageSize;
    }

    /**
     * Protect one element id from the missing-elements sweep: the feed mentioned
     * it, whatever the item's own row outcome was.
     */
    public function markSeen(int $elementId): void
    {
        $this->seen[$elementId] = true;
    }

    /** @return list<int> */
    public function seenIds(): array
    {
        return array_keys($this->seen);
    }

    /**
     * The progress denominator: the feed's own reported total when it has one,
     * else pages × the FIRST page's size. Deliberately the first page's size and
     * not the current page's — a short final page would otherwise shrink the
     * estimate mid-run and walk the bar backwards. Null when the feed reports
     * neither a total nor a page count; the caller then eases toward a soft
     * target instead.
     *
     * Memoized, so a feed whose reported counts drift between pages can't move
     * the denominator once a walk has settled on one. A null result is NOT
     * memoized — a feed that only starts reporting counts on a later page still
     * gets a real percentage from there on.
     */
    public function estimateTotal(FeedPage $page): ?int
    {
        return $this->total ??= $page->totalCount
            ?? ($page->pageCount !== null && (int) $this->firstPageSize > 0 ? $page->pageCount * (int) $this->firstPageSize : null);
    }
}
