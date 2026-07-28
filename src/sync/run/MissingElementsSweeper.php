<?php

namespace GlueAgency\Influx\sync\run;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\Db;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\sync\MatchValue;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\ElementTargetInterface;
use Throwable;

/**
 * Step 4 of the run lifecycle: disable or delete the elements a scope owns that
 * the feed didn't mention. Runs once per pass, after that scope's pages are
 * exhausted — both the synchronous walk and the queued, page-per-step walk
 * route here.
 *
 * ONE POLICY PER PASS. {@see policyFor()} resolves exactly one action from the
 * link's `processing` flags and the pass applies it there and then; there is no
 * run-end second sweep and the flags no longer compose. Precedence — the more
 * destructive wins, and a global delete supersedes the rest (there's no point
 * disabling elements you're about to delete outright):
 *
 *   DELETE > DELETE_FOR_SITE > DISABLE > DISABLE_FOR_SITE
 *
 * {@see Link::migrateProcessingForEndpointShape()} swaps global <-> -for-site on
 * save to match the endpoint shape, so in practice DELETE/DISABLE only resolve
 * on a no-site-endpoints link (their pass is the single `[null]` scope, swept
 * unscoped) and the -for-site pair only on a site-endpoints link (swept scoped
 * to the running site) — but the guards below stay defensive against a
 * hand-edited config.
 *
 * How each policy lands:
 *   - DISABLED: no-site-endpoints pass (siteId null) → {@see ElementTargetInterface::disable()}.
 *     Stays adaptive — an un-migrated `disable` + site-endpoints config disables
 *     only that site's row rather than reaching across sites.
 *   - DISABLED_FOR_SITE: needs a site → {@see ElementTargetInterface::disableForSite()}
 *     (leave the element live in its other sites); a siteless pass logs one SKIPPED row.
 *   - DELETED_FOR_SITE: needs a site — a siteless pass logs one SKIPPED row.
 *   - DELETED: the whole element is destroyed, unscoped ({@see applyAction()}
 *     routes DELETED → target->delete()).
 *
 * Sliding-window guard: an offset run fetches only a slice of the feed, so its
 * seen-set is intentionally partial — the complement isn't missing, just outside
 * the window, and sweeping it would delete/disable everything beyond the slice.
 * Only a full sync (no offset) may sweep. That skip is expected behaviour, so
 * it's silent: no warning, no SKIPPED row.
 *
 * Clean-pass guard: a sweep acts on the COMPLEMENT of the seen-set, so it's only
 * safe when the seen-set is complete. If any item failed WITHOUT a resolvable
 * element ($unattributedErrors > 0) the set is untrustworthy — an element that's
 * actually in the feed but errored before it could be matched would be swept as
 * missing. So the sweep bails, warns, and logs one SKIPPED row (not ERROR — an
 * error would flip the run's status perception; SKIPPED tells the user why
 * nothing was swept).
 *
 * Defensive guard (D2): save-time migration swaps DELETE → DELETE_FOR_SITE when
 * a link has site endpoints, but a hand-edited config could still pair global
 * DELETE with them. Rather than cross-site delete off one site's feed, such a
 * link skips the delete, warns, and logs one SKIPPED row. (Disable needs no
 * equivalent hard guard — it's reversible, so DISABLED downgrades to a per-site
 * disable instead of skipping.)
 *
 * Deciding and acting are deliberately separate methods: {@see plan()} is pure,
 * so every guard above is assertable without a database, and {@see apply()} is
 * the only part that queries or writes.
 */
class MissingElementsSweeper
{
    /**
     * Resolve this pass's policy, report whatever a bail owes the operator, and
     * apply the sweep. The entry point both walk paths call.
     *
     * @param list<int> $seenIds Element ids present in this scope's feed.
     * @param int $unattributedErrors Items that failed with no resolvable
     * element — any at all disables the sweep.
     */
    public function sweep(SyncContext $context, array $seenIds, int $unattributedErrors, LogRecord $log): void
    {
        $plan = $this->plan($context, $seenIds, $unattributedErrors);

        if ($plan->warning !== null) {
            Craft::warning($plan->warning, __METHOD__);
        }

        if ($plan->skipRow !== null) {
            Influx::getInstance()->logs->recordItem($log, ItemAction::SKIPPED, null, null, $plan->skipRow);
        }

        if ($plan->policy === null) {
            return;
        }

        $this->apply($context, $plan, $log);
    }

    /**
     * Run every guard and hand back what this pass should do — the whole of the
     * sweep's decision-making, and nothing that touches Craft. Guard order is
     * load-bearing: the two silent bails (no flag, offset run) come first so an
     * offset run never warns, then the clean-pass guard, then D2, then the
     * site-scope requirement.
     *
     * @param list<int> $seenIds
     */
    public function plan(SyncContext $context, array $seenIds, int $unattributedErrors): MissingSweepPlan
    {
        $link = $context->link;
        $policy = $this->policyFor($link);

        if ($policy === null) {
            return new MissingSweepPlan();
        }

        if ($context->offsetHandle !== null) {
            return new MissingSweepPlan();
        }

        if ($unattributedErrors > 0) {
            return new MissingSweepPlan(
                warning: "Influx: missing-elements sweep skipped for link '{$link->handle}'"
                    . ($context->siteHandle !== null ? " (site '{$context->siteHandle}')" : '')
                    . " — {$unattributedErrors} item(s) failed without a resolvable element, "
                    . 'so the missing-set cannot be trusted.',
                skipRow: "Missing-elements sweep skipped: {$unattributedErrors} item(s) failed without a resolvable element.",
            );
        }

        if ($policy === ItemAction::DELETED && ! empty($link->getSiteEndpoints())) {
            return new MissingSweepPlan(
                warning: "Influx: missing-elements delete sweep skipped for link '{$link->handle}'"
                    . ' — global delete is not allowed with site-specific endpoints (would delete '
                    . 'cross-site off a single site\'s feed).',
                skipRow: 'Missing-elements delete sweep skipped: global delete is not allowed with '
                    . 'site-specific endpoints — use “delete for site”.',
            );
        }

        if (in_array($policy, [ItemAction::DELETED_FOR_SITE, ItemAction::DISABLED_FOR_SITE], true) && $context->siteId === null) {
            return new MissingSweepPlan(
                skipRow: "Missing-elements sweep skipped: {$policy->value} needs a site-scoped run.",
            );
        }

        return new MissingSweepPlan(policy: $policy, seenIds: $seenIds, siteId: $context->siteId);
    }

    /**
     * The single missing-elements action a link's `processing` flags call for,
     * or null when no missing-elements flag is set.
     *
     * Pure: reads only {@see Link::$processing}, so it's unit-tested without a
     * Craft boot.
     */
    public function policyFor(Link $link): ?ItemAction
    {
        if (in_array(Link::PROCESSING_DELETE, $link->processing, true)) {
            return ItemAction::DELETED;
        }

        if (in_array(Link::PROCESSING_DELETE_FOR_SITE, $link->processing, true)) {
            return ItemAction::DELETED_FOR_SITE;
        }

        if (in_array(Link::PROCESSING_DISABLE, $link->processing, true)) {
            return ItemAction::DISABLED;
        }

        if (in_array(Link::PROCESSING_DISABLE_FOR_SITE, $link->processing, true)) {
            return ItemAction::DISABLED_FOR_SITE;
        }

        return null;
    }

    /**
     * The shared sweep body: build the target's candidate query, status-filter it
     * per policy, walk it in batches, and apply the policy action to each element
     * — logging a success row, an ERROR row on a failed/false save, or an ERROR
     * row on a thrown failure. All policies route here, so the per-element loop,
     * the false-vs-success discipline, and the tail flush live in exactly one
     * place.
     *
     * DISABLED only touches still-enabled elements (skip the churn of
     * re-disabling); the delete policies consider every status. A save that
     * returns false WITHOUT throwing (a validation failure that didn't persist)
     * is an ERROR row, never a success row — the log must not claim a
     * disable/delete that never landed. The tail flush is explicit: the log
     * buffer auto-flushes every 100 rows, but on the queued
     * ({@see \GlueAgency\Influx\services\SynchronizationService::batchStep()})
     * path nothing else flushes before the state returns, so this is what
     * persists the sweep's rows.
     */
    public function apply(SyncContext $context, MissingSweepPlan $plan, LogRecord $log): void
    {
        $plugin = Influx::getInstance();
        $link = $context->link;
        $policy = $plan->policy;

        if ($policy === null) {
            return;
        }

        $query = $context->target->missingElementsQuery($link, $plan->seenIds, $plan->siteId);

        if ($query === null) {
            return;
        }

        $disablePolicy = in_array($policy, [ItemAction::DISABLED, ItemAction::DISABLED_FOR_SITE], true);
        $query->status($disablePolicy ? 'enabled' : null);

        $matchAttr = $link->matchAttribute();

        foreach (Db::batch($query, 100) as $elements) {
            foreach ($elements as $element) {
                $matchValue = $matchAttr ? MatchValue::forLog($element->{$matchAttr} ?? null) : null;

                try {
                    if (! $this->applyAction($context, $policy, $element)) {
                        $plugin->logs->recordItem(
                            $log,
                            ItemAction::ERROR,
                            $element->id,
                            $matchValue,
                            "Missing-elements {$policy->value} failed to save.",
                        );

                        continue;
                    }

                    $plugin->logs->recordItem(
                        $log,
                        $policy,
                        $element->id,
                        $matchValue,
                        'Missing from feed.',
                    );
                } catch (Throwable $e) {
                    $plugin->logs->recordItem($log, ItemAction::ERROR, $element->id, null, $e->getMessage());
                }
            }
        }

        $plugin->logs->flush($log);
    }

    /**
     * Apply the resolved missing-action to one element. Kept apart from
     * {@see apply()} so the mode-per-policy dispatch reads as a single
     * expression. The site scope is read off the context: DISABLED on a site run
     * disables only that site; a global DISABLED disables the element. That
     * downgrade is what an un-migrated `disable` + site-endpoints config lands
     * on, and it is safe because a disable is reversible — downgrading beats
     * skipping the sweep outright.
     *
     * Returns the target call's boolean result: false means the save did NOT
     * persist (e.g. a validation error), which {@see apply()} turns into an
     * ERROR row instead of a false-positive success row. An unknown policy
     * returns false (nothing was applied — never log it as done).
     */
    public function applyAction(SyncContext $context, ItemAction $policy, ElementInterface $element): bool
    {
        $target = $context->target;

        return match ($policy) {
            ItemAction::DELETED           => $target->delete($element),
            ItemAction::DELETED_FOR_SITE  => $target->deleteForSite($element, (int) $context->siteId),
            ItemAction::DISABLED_FOR_SITE => $target->disableForSite($element, (int) $context->siteId),
            ItemAction::DISABLED          => $context->siteId !== null
                ? $target->disableForSite($element, $context->siteId)
                : $target->disable($element),
            default => false,
        };
    }
}
