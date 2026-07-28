<?php

namespace GlueAgency\Influx\sync\run;

use GlueAgency\Influx\enums\ItemAction;

/**
 * What {@see MissingElementsSweeper::plan()} decided about one pass's
 * missing-elements sweep: the policy to apply and the scope to apply it in, or
 * the messages a bailed sweep must leave behind. Treat as read-only.
 *
 * Splitting the decision out of the action is what keeps every guard
 * (offset, clean-pass, D2, policy precedence) assertable without a Craft boot:
 * the guards produce a plan, and only {@see MissingElementsSweeper::apply()}
 * touches the database.
 *
 * Three shapes:
 *   - a sweep: `$policy` set, `$warning`/`$skipRow` null.
 *   - a reported bail: `$policy` null, `$skipRow` set (plus `$warning` when the
 *     operator needs telling too).
 *   - a silent no-op: everything null — no flag set, or an offset run, where
 *     sweeping nothing is expected behaviour rather than a problem.
 */
class MissingSweepPlan
{
    /** The resolved action, or null when this pass sweeps nothing. */
    public ?ItemAction $policy = null;

    /**
     * Element ids the feed did mention, excluded from the candidate set.
     *
     * @var list<int>
     */
    public array $seenIds = [];

    /** Site to scope the candidate query and the action to; null = cross-site. */
    public ?int $siteId = null;

    /** Operator-facing message for the Craft log, or null when none is due. */
    public ?string $warning = null;

    /** Message for the single SKIPPED row a bail leaves in the run, or null. */
    public ?string $skipRow = null;

    /** @param list<int> $seenIds */
    public function __construct(
        ?ItemAction $policy = null,
        array $seenIds = [],
        ?int $siteId = null,
        ?string $warning = null,
        ?string $skipRow = null,
    ) {
        $this->policy = $policy;
        $this->seenIds = $seenIds;
        $this->siteId = $siteId;
        $this->warning = $warning;
        $this->skipRow = $skipRow;
    }
}
