<?php

namespace GlueAgency\Influx\sync;

/**
 * Per-run scratch memo for the collaborators that can't hold state of their own.
 * Targets and field strategies are registry PROTOTYPES — one shared instance per
 * process, whose per-call state travels in arguments, never on the instance — so
 * a lookup one of them wants to compute once per run parks it here instead of on
 * itself. The memo lives on the run's {@see SyncContext}, so it dies with the run
 * and the next run sees whatever changed in between (a user group created
 * mid-process, say), while a property memo on the prototype would not.
 *
 * The narrow, typed caches keep their own classes — element lookups are
 * {@see \GlueAgency\Influx\sync\item\ElementLookupCache}, which owns its
 * composite key and eviction rules. This is the generic seam for everything that
 * doesn't warrant one: one string key, one value, no eviction (a run memoizes a
 * handful of things, not a per-item stream).
 *
 * Keys are caller-chosen and namespaced by their owner — `'userTarget.groupIdMap'`
 * — so two owners in one run can't collide.
 */
class RunMemo
{
    /** @var array<string, mixed> */
    protected array $entries = [];

    /**
     * The value for $key, resolving it exactly once per run and caching whatever
     * comes back — including null, which is a resolved answer like any other.
     *
     * @param callable(): mixed $resolve
     */
    public function remember(string $key, callable $resolve): mixed
    {
        if (! array_key_exists($key, $this->entries)) {
            $this->entries[$key] = $resolve();
        }

        return $this->entries[$key];
    }

    /**
     * True for the FIRST caller of $key in this run and false for every one after
     * — the "only one of these may proceed" primitive, for work a run must do at
     * most once even though the loop reaches it repeatedly
     * ({@see \GlueAgency\Influx\sync\item\ItemProcessor::resolve()} claiming the one
     * element a match-less link writes).
     *
     * Distinct from {@see remember()}: that one answers "what is it", memoizing a
     * value every caller then shares. This one answers "is it mine", where the
     * point is precisely that callers get DIFFERENT answers. Same key space, so an
     * owner must not use one key for both.
     */
    public function claim(string $key): bool
    {
        if (array_key_exists($key, $this->entries)) {
            return false;
        }

        $this->entries[$key] = true;

        return true;
    }
}
