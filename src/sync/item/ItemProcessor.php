<?php

namespace GlueAgency\Influx\sync\item;

use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\ElementTargetInterface;

/**
 * The per-item pipeline, in three phases that exist exactly once for both
 * the real sync run and the debug dry-run:
 *
 *   1. {@see resolve()}  — extract match value, find the element, decide.
 *                          No writes.
 *   2. {@see populate()} — build the element when creating, stamp the site,
 *                          apply mappings. Mutates the element in memory,
 *                          saves nothing.
 *   3. {@see commit()}   — persist, unless the run is a dry-run or nothing
 *                          changed.
 *
 * The phase boundaries are deliberately the seams where {@see ItemRunner}
 * fires its item events (through the service, which stays their sender), so
 * events stay out of this class while the logic lives here. Dry-run safety
 * is structural: the debug inspector simply never calls commit(), and the
 * dryRun flag rides the context into every field strategy.
 */
class ItemProcessor
{
    protected MappingApplier $applier;

    public function __construct(?MappingApplier $applier = null)
    {
        $this->applier = $applier ?? new MappingApplier();
    }

    /**
     * Phase 1 — extract the match value, find the existing element, decide
     * what to do. Performs no writes.
     */
    public function resolve(SyncContext $context, RemoteItem $item): ItemResolution
    {
        $link = $context->link;

        if (! $link->requiresMatch()) {
            return $this->resolveSingle($context);
        }

        $matchValue = $link->matchValue($item);

        if ($matchValue === null || $matchValue === '') {
            return new ItemResolution($matchValue, null, SyncDecision::SKIP_NO_MATCH);
        }

        $element = $context->target->findByMatchValue($link, $matchValue, $context->siteId);

        return new ItemResolution($matchValue, $element, SyncDecision::decide($link, $matchValue, $element));
    }

    /**
     * Resolve for a link whose target names one element from its criteria — no
     * match value is read, and the item's own content is all it contributes.
     *
     * FIRST ITEM WINS. Every item in such a feed would resolve to the same
     * element, so the pass claims it once ({@see \GlueAgency\Influx\sync\RunMemo::claim()})
     * and every later item is skipped with a reason instead of overwriting what the
     * one before it wrote. The claim is per {@see SyncContext}, so a site-endpoint
     * link still fills the element once per site.
     *
     * The claim is taken BEFORE resolution, so an unresolvable criterion still
     * consumes it: one item then reports the real problem (no element, no create)
     * and the rest report the shape mismatch, rather than every item repeating the
     * same failure.
     */
    protected function resolveSingle(SyncContext $context): ItemResolution
    {
        $link = $context->link;

        if (! $context->memo->claim('itemProcessor.singleElement.' . $link->handle)) {
            return new ItemResolution(null, null, SyncDecision::SKIP_SINGLE_ELEMENT_TAKEN);
        }

        $element = $context->target->findWithoutMatch($link, $context->siteId);

        return new ItemResolution(null, $element, SyncDecision::decide($link, null, $element));
    }

    /**
     * Phase 2 — turn a resolution into a fully-mapped element. Skip
     * decisions short-circuit into a Skipped result carrying the reason;
     * create decisions build the element via the target. The element is
     * only ever mutated in memory — persisting is {@see commit()}'s job.
     *
     * The item's `changed` flag is seeded from `$isNew` (a new element always
     * saves) and then folded from the per-mapping rows; a mapping that threw
     * never counts as a change. An item whose only mapping failed would
     * otherwise log as "unchanged" and hide the failure, so it is reported as
     * {@see ItemAction::ERROR} instead.
     *
     * @throws \Throwable target buildNew() failures propagate (missing
     * section, unknown entry type); per-mapping failures do NOT — the
     * applier captures those as {@see MappingResult::$error} rows.
     */
    public function populate(SyncContext $context, RemoteItem $item, ItemResolution $resolution): ItemSyncResult
    {
        if ($resolution->decision->isSkip()) {
            return new ItemSyncResult(
                decision: $resolution->decision,
                action: ItemAction::SKIPPED,
                matchValue: $resolution->matchValue,
                element: $resolution->element,
                isNew: false,
                changed: false,
                message: $this->skipMessage($context->link, $resolution->decision),
            );
        }

        $isNew = $resolution->decision === SyncDecision::CREATE;
        $element = $resolution->element;

        if ($isNew) {
            $element = $context->target->buildNew($context->link, $context->siteId);
            $context->target->assignMatchValue($element, $context->link, $resolution->matchValue);
        }

        if ($context->siteId) {
            $element->siteId = $context->siteId;
        }

        $results = $this->applier->apply($context, $element, $item);

        $changed = $isNew;
        $hasFieldErrors = false;

        foreach ($results as $result) {
            $changed = $changed || $result->changed === true;
            $hasFieldErrors = $hasFieldErrors || $result->error !== null;
        }

        $action = $changed
            ? ($isNew ? ItemAction::CREATED : ItemAction::UPDATED)
            : ItemAction::UNCHANGED;

        if ($action === ItemAction::UNCHANGED && $hasFieldErrors) {
            $action = ItemAction::ERROR;
        }

        return new ItemSyncResult(
            decision: $resolution->decision,
            action: $action,
            matchValue: $resolution->matchValue,
            element: $element,
            isNew: $isNew,
            changed: $changed,
            mappingResults: $results,
        );
    }

    /**
     * Phase 3 — persist the populated element through the target
     * ({@see ElementTargetInterface::save()}, which saves WITH validation —
     * deliberate, and documented there). Pass-through for dry-runs and skips; a
     * failed save becomes {@see ItemAction::ERROR} carrying
     * {@see commitFailureMessage()}.
     *
     * The element is saved only when a field actually changed — unchanged
     * existing elements skip the save. Either way, a committed create/update
     * item then runs the target's {@see ElementTargetInterface::afterCommit()}
     * hook, so a target can reconcile state that lives outside the element save
     * (e.g. user-group membership) even for an otherwise-unchanged element. The
     * item rides along for the hook's sake: a per-item side effect (a user's photo
     * URL) can only be read from the feed, and only after the element has an id.
     */
    public function commit(SyncContext $context, RemoteItem $item, ItemSyncResult $draft): ItemSyncResult
    {
        if ($context->dryRun || $draft->element === null || $draft->decision->isSkip()) {
            return $draft;
        }

        if ($draft->changed && ! $context->target->save($draft->element)) {
            // Each validation error goes onto the row whose value Craft refused
            // — including nested ones, onto the child and its own leaf row — so
            // the item's message no longer has to carry a blob naming fields the
            // rows beneath it said nothing about. What comes back is only what no
            // row claimed, which the message still has to say itself.
            $unclaimed = (new ValidationErrorRouter())->route(
                $draft->element->getErrors(),
                $draft->mappingResults,
            );

            return new ItemSyncResult(
                decision: $draft->decision,
                action: ItemAction::ERROR,
                matchValue: $draft->matchValue,
                element: $draft->element,
                isNew: $draft->isNew,
                changed: $draft->changed,
                mappingResults: $draft->mappingResults,
                message: $this->commitFailureMessage($draft->element, $unclaimed),
            );
        }

        $context->target->afterCommit($context, $draft->element, $item, $draft->isNew);

        return $draft;
    }

    /**
     * Why a save that returned false failed, for the item's log row.
     *
     * Elements are saved WITH validation (the policy alpha.7 reversed), so a
     * false return is usually a set of validation errors — and those are now on
     * the rows themselves ({@see ValidationErrorRouter}), where the operator is
     * already reading which value came from which node. This says how many there
     * were rather than repeating them, and spells out only the ones no row
     * claimed: a required field this link doesn't map has nowhere else to appear.
     *
     * A false return with no errors at all is Craft itself refusing — a
     * `beforeSave()`/`afterSave()` handler on the element or a listening plugin —
     * which attaches nothing to report.
     *
     * @param array<string, list<string>> $unclaimed
     */
    protected function commitFailureMessage(ElementInterface $element, array $unclaimed = []): string
    {
        $errors = $element->getErrors();

        if ($errors === []) {
            return 'Craft rejected the save without reporting validation errors — a beforeSave/afterSave handler refused it.';
        }

        $count = count($errors);
        $message = "Craft refused the save: {$count} field(s) reported validation errors.";

        if ($unclaimed === []) {
            return $message;
        }

        $spelled = [];

        foreach ($unclaimed as $key => $messages) {
            $spelled[] = $key . ': ' . implode(' ', $messages);
        }

        return $message . ' Not mapped by this link: ' . implode(' — ', $spelled);
    }

    protected function skipMessage(Link $link, SyncDecision $decision): string
    {
        if ($decision === SyncDecision::SKIP_NO_MATCH) {
            $matchAttr = $link->matchAttribute() ?: '?';
            $node = $link->getMappingCollection()->get($matchAttr)?->node ?? '?';

            return "Remote item has no value at match path '{$node}' (match attribute: {$matchAttr}).";
        }

        // On a match-less link "no element" can't mean "the feed named one that
        // doesn't exist yet" — nothing was looked up. It means the criteria resolve
        // to nothing, which the generic label would have blamed on the `create`
        // policy.
        if ($decision === SyncDecision::SKIP_NO_CREATE && ! $link->requiresMatch()) {
            return "Link '{$link->handle}' has no element to write to — check its element criteria.";
        }

        return $decision->label();
    }
}
