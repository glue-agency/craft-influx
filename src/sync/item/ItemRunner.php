<?php

namespace GlueAgency\Influx\sync\item;

use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\events\SyncItemEvent;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\services\SynchronizationService;
use GlueAgency\Influx\sync\MatchValue;
use GlueAgency\Influx\sync\SyncContext;

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

    public function __construct(SynchronizationService $service, ?ItemProcessor $processor = null)
    {
        $this->service = $service;
        $this->processor = $processor ?? new ItemProcessor();
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
        );

        $this->fireAfterItem($link, $item->raw(), $result->element, $context->siteHandle, $result->action);

        return $result->element?->id;
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
