<?php

namespace GlueAgency\Influx\sync\run;

/**
 * The resumable state of a queued, page-per-step run: where the walk is, what it
 * has accumulated, and whether it's finished. MUTABLE by design — a step advances
 * it in place and hands the result back for the next step to resume from.
 *
 * It exists so the seven carried keys are spelled out ONCE. They used to be
 * echoed in three places (the job's properties, the state array
 * {@see \GlueAgency\Influx\services\SynchronizationService::batchStep()} threads
 * through, and the re-push), where any of the three could quietly drop one — and
 * dropping `seenIds` in particular makes the missing-elements sweep over-disable,
 * since it would then only ever "see" the final page's items.
 *
 * The array round-trip is the boundary: `batchStep()` takes and returns a plain
 * array because that array is what rides the serialised queue payload, and
 * {@see \GlueAgency\Influx\queue\jobs\SyncLinkJob}'s public properties have to
 * stay individually serialisable. {@see fromArray()} and {@see toArray()} are the
 * only places the key names appear.
 *
 * `seenIds` rides that payload, so its size grows with the item count: fine for
 * feeds up to tens of thousands of ids; a feed far larger would bloat the job row
 * and should page the sweep differently.
 */
class BatchState
{
    /** The log this run's whole scope reports into, opened on the first step. */
    public ?int $logId = null;

    /** Paginator URL the next step fetches, or null to (re)fetch page one. */
    public ?string $cursorUrl = null;

    /** 1-based page number this step is on. */
    public int $page = 1;

    /**
     * What the walk has accumulated so far — the seen-set, the unattributed-error
     * count and the first page's size, all of which MUST survive across steps
     * because the sweep and the progress denominator are derived from them.
     */
    public PageWalk $walk;

    /** True once the scope's pages are exhausted (or the run was failed). */
    public bool $done = false;

    /** @param list<int> $seenIds */
    public function __construct(
        ?int $logId = null,
        ?string $cursorUrl = null,
        int $page = 1,
        array $seenIds = [],
        int $unattributedErrors = 0,
        ?int $firstPageSize = null,
    ) {
        $this->logId = $logId;
        $this->cursorUrl = $cursorUrl;
        $this->page = $page;
        $this->walk = new PageWalk($seenIds, $unattributedErrors, $firstPageSize);
    }

    /**
     * Read a step's incoming state. `done` is deliberately not read back: a state
     * being handed to a step is by definition not finished yet.
     *
     * @param array{logId?: ?int, cursorUrl?: ?string, page?: int, seenIds?: list<int>, unattributedErrors?: int, firstPageSize?: ?int} $state
     */
    public static function fromArray(array $state): self
    {
        return new self(
            logId: $state['logId'] ?? null,
            cursorUrl: $state['cursorUrl'] ?? null,
            page: (int) ($state['page'] ?? 1),
            seenIds: $state['seenIds'] ?? [],
            unattributedErrors: (int) ($state['unattributedErrors'] ?? 0),
            firstPageSize: $state['firstPageSize'] ?? null,
        );
    }

    /**
     * @return array{logId: ?int, cursorUrl: ?string, page: int, seenIds: list<int>, unattributedErrors: int, firstPageSize: ?int, done: bool}
     */
    public function toArray(): array
    {
        return $this->carried() + ['done' => $this->done];
    }

    /**
     * The carried keys alone, named to match {@see \GlueAgency\Influx\queue\jobs\SyncLinkJob}'s
     * public properties so a re-push can spread them instead of hand-copying six
     * names. `done` is omitted: it's the step's answer, not part of the payload.
     *
     * @return array{logId: ?int, cursorUrl: ?string, page: int, seenIds: list<int>, unattributedErrors: int, firstPageSize: ?int}
     */
    public function carried(): array
    {
        return [
            'logId'              => $this->logId,
            'cursorUrl'          => $this->cursorUrl,
            'page'               => $this->page,
            'seenIds'            => $this->walk->seenIds(),
            'unattributedErrors' => $this->walk->unattributedErrors,
            'firstPageSize'      => $this->walk->firstPageSize,
        ];
    }
}
