<?php

namespace GlueAgency\Influx\sync;

use Craft;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\models\Link;

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
 * The phase boundaries are deliberately the seams where
 * {@see \GlueAgency\Influx\services\SynchronizationService} fires its item events,
 * so events stay on the service while the logic lives here. Dry-run safety
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
        $matchValue = $link->matchValue($item);

        if ($matchValue === null || $matchValue === '') {
            return new ItemResolution($matchValue, null, SyncDecision::SkipNoMatch);
        }

        $element = $context->target->findByMatchValue($link, $matchValue, $context->siteId);

        return new ItemResolution($matchValue, $element, $link->decideAction($matchValue, $element));
    }

    /**
     * Phase 2 — turn a resolution into a fully-mapped element. Skip
     * decisions short-circuit into a Skipped result carrying the reason;
     * create decisions build the element via the target. The element is
     * only ever mutated in memory — persisting is {@see commit()}'s job.
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
                action: ItemAction::Skipped,
                matchValue: $resolution->matchValue,
                element: $resolution->element,
                isNew: false,
                changed: false,
                message: $this->skipMessage($context->link, $resolution->decision),
            );
        }

        $isNew = $resolution->decision === SyncDecision::Create;
        $element = $resolution->element;

        if ($isNew) {
            $element = $context->target->buildNew($context->link, $context->siteId);
            $context->target->assignMatchValue($element, $context->link, $resolution->matchValue);
        }

        if ($context->siteId) {
            $element->siteId = $context->siteId;
        }

        $outcome = $this->applier->apply($context, $element, $item, $isNew);

        return new ItemSyncResult(
            decision: $resolution->decision,
            action: $outcome->changed
                ? ($isNew ? ItemAction::Created : ItemAction::Updated)
                : ItemAction::Unchanged,
            matchValue: $resolution->matchValue,
            element: $element,
            isNew: $isNew,
            changed: $outcome->changed,
            mappingResults: $outcome->results,
        );
    }

    /**
     * Phase 3 — persist the populated element. Pass-through for dry-runs,
     * skips, and unchanged results; on save failure the action becomes
     * {@see ItemAction::Error} with the element's validation errors as the
     * message.
     */
    public function commit(SyncContext $context, ItemSyncResult $draft): ItemSyncResult
    {
        if ($context->dryRun || ! $draft->changed || $draft->element === null) {
            return $draft;
        }

        if (Craft::$app->getElements()->saveElement($draft->element, false)) {
            return $draft;
        }

        return new ItemSyncResult(
            decision: $draft->decision,
            action: ItemAction::Error,
            matchValue: $draft->matchValue,
            element: $draft->element,
            isNew: $draft->isNew,
            changed: $draft->changed,
            mappingResults: $draft->mappingResults,
            message: json_encode($draft->element->getErrors()) ?: null,
        );
    }

    protected function skipMessage(Link $link, SyncDecision $decision): ?string
    {
        if ($decision === SyncDecision::SkipNoMatch) {
            $matchAttr = $link->matchAttribute() ?: '?';
            $node = $link->getMappingCollection()->get($matchAttr)?->node ?? '?';

            return "Remote item has no value at match path '{$node}' (match attribute: {$matchAttr}).";
        }

        return $decision->skipReason();
    }
}
