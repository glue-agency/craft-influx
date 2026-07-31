<?php

namespace GlueAgency\Influx\sync\item;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\events\SyncItemEvent;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\services\SynchronizationService;
use GlueAgency\Influx\sync\MatchValue;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\web\ItemRowPresenter;
use Throwable;

/**
 * Runs ONE remote item: the {@see ItemProcessor} pipeline, the item events at
 * its phase seams, and the log row that records the outcome. The pipeline logic
 * itself lives in the processor — this class only owns events + logs, which is
 * why the dry-run inspector can reuse the processor without inheriting either.
 *
 * EVENT SENDER. The three item events are part of the plugin's documented API and
 * third-party listeners bind them with `Event::on(SynchronizationService::class,
 * …)`, so they must keep firing with the SERVICE as sender. This class therefore
 * holds the service and triggers through it rather than becoming a sender of its
 * own; the constants stay on the service for the same reason.
 */
class ItemRunner
{
    /**
     * The service the item events are fired through — never to call back into
     * for sync work. See the class docblock: it exists only to keep the events'
     * sender identity intact.
     */
    protected SynchronizationService $service;

    /**
     * The shared per-item pipeline. Also used (dry-run) by
     * {@see \GlueAgency\Influx\services\InspectorService} — the logic exists
     * exactly once.
     */
    protected ItemProcessor $processor;

    /**
     * Presents the item's mapping results into the row tree the log row stores
     * ({@see mappingSnapshot()}). Injectable like {@see $processor}, and for the
     * same reason: a caller may hand in its own presenter rather than inherit
     * whichever one this class would have built.
     */
    protected ItemRowPresenter $rows;

    public function __construct(SynchronizationService $service, ?ItemProcessor $processor = null, ?ItemRowPresenter $rows = null)
    {
        $this->service = $service;
        $this->processor = $processor ?? new ItemProcessor();
        $this->rows = $rows ?? new ItemRowPresenter();
    }

    /**
     * Run one remote item through the shared pipeline, firing the item events at
     * the phase seams and logging the outcome. A no-match item never reaches the
     * listeners; there's nothing to act on. A listener may swap in a different
     * element, and the decision is then re-derived — a supplied element turns a
     * no-create skip into an update.
     *
     * Returns the id of the element this item resolved to (or null when it
     * matched none), regardless of the row outcome — SKIPPED and ERROR rows
     * included. The callers collect these ids as the run's "seen set": an item
     * PRESENT in the feed must never be swept as missing, whatever its per-item
     * result. A no-match item contributes null (nothing to protect).
     */
    public function run(SyncContext $context, RemoteItem $item, LogRecord $log): ?int
    {
        $plugin = Influx::getInstance();
        $link = $context->link;

        $resolution = $this->processor->resolve($context, $item);

        if ($resolution->decision !== SyncDecision::SKIP_NO_MATCH) {
            $beforeEvent = new SyncItemEvent([
                'link'       => $link,
                'item'       => $item->raw(),
                'element'    => $resolution->element,
                'siteHandle' => $context->siteHandle,
            ]);
            $this->service->trigger(SynchronizationService::EVENT_BEFORE_ITEM, $beforeEvent);

            if ($beforeEvent->skip) {
                $plugin->logs->recordItem($log, ItemAction::SKIPPED, $resolution->element?->id, MatchValue::forLog($resolution->matchValue), null, $item->raw());

                return $resolution->element?->id;
            }

            $resolution = $resolution->withElement($link, $beforeEvent->element);
        }

        $result = $this->processor->populate($context, $item, $resolution);

        if ($result->decision->isSkip()) {
            $plugin->logs->recordItem($log, ItemAction::SKIPPED, $result->element?->id, MatchValue::forLog($result->matchValue), $result->message, $item->raw());

            return $result->element?->id;
        }

        $afterMappingEvent = new SyncItemEvent([
            'link'       => $link,
            'item'       => $item->raw(),
            'element'    => $result->element,
            'siteHandle' => $context->siteHandle,
        ]);
        $this->service->trigger(SynchronizationService::EVENT_AFTER_ITEM_MAPPING, $afterMappingEvent);

        $result = $this->processor->commit($context, $result);

        $plugin->logs->recordItem(
            $log,
            $result->action,
            $result->element?->id,
            MatchValue::forLog($result->matchValue),
            $result->message,
            $item->raw(),
            $result->mappingErrors(),
            $result->changedFieldHandles(),
            $this->mappingSnapshot($context, $result),
        );

        $this->fireAfterItem($link, $item->raw(), $result->element, $context->siteHandle, $result->action);

        return $result->element?->id;
    }

    /**
     * The item's mapping results, presented, for the log row to keep — the log
     * drill-down's display source, so it shows what this run actually did
     * instead of re-deriving it from a later dry run against present state.
     * `withParsedHtml` is on because this snapshot IS what the viewer renders:
     * the rich cells (relation chips, boolean lightswitches) have to be in it.
     *
     * Null — no presentation at all — when nothing would consume it: logging is
     * off, so no row is written, or the outcome carries no element, which the
     * presenter needs to normalize values for display parity.
     *
     * Runs {@see attachSavedChildren()} first, so the nested rows present the
     * identities this run's commit actually created rather than the nulls the
     * pre-save derivation could only leave there.
     *
     * Guarded, because a snapshot is a nicety and the item's outcome is not:
     * unlike the CP drill-down this rendering used to happen in, a real run
     * presents from a console or queue request, where the rich cells build CP
     * markup outside a CP request. A failure logs and drops the snapshot; the
     * row, the element and the run are untouched.
     *
     * @return list<array>|null
     */
    protected function mappingSnapshot(SyncContext $context, ItemSyncResult $result): ?array
    {
        if ($result->element === null || ! $this->loggingEnabled()) {
            return null;
        }

        try {
            $this->attachSavedChildren($result);

            return $this->rows->presentMappingResults(
                $result->mappingResults,
                $result->element,
                $this->rows->fieldLabels($context->link, $context->target),
                withParsedHtml: true,
            );
        } catch (Throwable $e) {
            Craft::warning('Influx: presenting the log snapshot for item ' . MatchValue::forLog($result->matchValue) . " threw: {$e->getMessage()}", __METHOD__);

            return null;
        }
    }

    /**
     * Post-commit identity pass: hand each mapping row's children to the strategy
     * that produced them, so the ones that could only become elements at save
     * time get their saved element zipped back on
     * ({@see \GlueAgency\Influx\fields\Field::attachSavedChildren()}). Without it
     * a Matrix block this run ADDED shows in the log drill-down with an ordinal
     * and no chip, even though the block exists by the time the snapshot is
     * captured — the children were derived during populate, before the owner had
     * an id to hang them off.
     *
     * Called from inside {@see mappingSnapshot()}'s guard, right before
     * presentation: the last moment the results are still raw, which is what makes
     * mutating them here the sanctioned exception to
     * {@see ChildResult}'s read-only rule. A strategy that throws takes the
     * snapshot down with it, no further — same guarantee the presentation has.
     *
     * Only for an outcome that WROTE: CREATED and UPDATED are the only actions
     * {@see ItemProcessor::commit()} leaves a save behind. UNCHANGED skipped the
     * save and its children all carry the blocks they stand for already; SKIPPED
     * and ERROR persisted nothing at all. The pass would be harmless on those, but
     * reading a field value back for children that cannot have gained anything is
     * work for nothing.
     *
     * Top-level rows only, per the hook's documented boundary. A native row has no
     * craft field on the layout, so there is no strategy to ask, and a row whose
     * children are empty has nothing to fill.
     */
    protected function attachSavedChildren(ItemSyncResult $result): void
    {
        if ($result->element === null || ! $this->wroteElement($result->action)) {
            return;
        }

        $layout = $result->element->getFieldLayout();

        foreach ($result->mappingResults as $row) {
            if ($row->children === null || $row->children === []) {
                continue;
            }

            $craftField = $layout?->getFieldByHandle($row->handle);

            if ($craftField === null) {
                continue;
            }

            $this->strategyFor($craftField)->attachSavedChildren($result->element, $row->handle, $row->children);
        }
    }

    /**
     * Whether this outcome persisted the element — {@see attachSavedChildren()}
     * says why only these two qualify. The sweep-only actions (disabled/deleted)
     * never come out of an item run at all, and write no mapped values when they
     * do run.
     */
    protected function wroteElement(ItemAction $action): bool
    {
        return match ($action) {
            ItemAction::CREATED, ItemAction::UPDATED => true,
            default => false,
        };
    }

    /**
     * The mapping strategy behind one of the element's craft fields. Extracted so
     * the snapshot's back-fill can be exercised without a booted plugin.
     */
    protected function strategyFor(CraftFieldInterface $craftField): Field
    {
        return Influx::getInstance()->fields->forCraftField($craftField);
    }

    /**
     * Whether run logging is on — the gate {@see mappingSnapshot()} opens with.
     * Extracted for the same reason as {@see strategyFor()}.
     */
    protected function loggingEnabled(): bool
    {
        return Influx::getInstance()->logs->loggingEnabled();
    }

    protected function fireAfterItem(
        Link $link,
        array $item,
        ?ElementInterface $element,
        ?string $siteHandle,
        ItemAction $action,
    ): void {
        $afterEvent = new SyncItemEvent([
            'link'       => $link,
            'item'       => $item,
            'element'    => $element,
            'siteHandle' => $siteHandle,
            'action'     => $action->value,
        ]);
        $this->service->trigger(SynchronizationService::EVENT_AFTER_ITEM, $afterEvent);
    }
}
