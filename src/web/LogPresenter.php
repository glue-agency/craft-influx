<?php

namespace GlueAgency\Influx\web;

use Craft;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;

/**
 * Shapes log records into the JSON the Vue log viewer (LogApp / LogItem)
 * renders. Shared by {@see \GlueAgency\Influx\controllers\LogsController}'s
 * initial page payload and its live SSE frames, so the header, counters, and
 * row shapes can't drift between the first paint and live updates — dates are
 * formatted the same in both, which a previous inline-in-controller version
 * got wrong (formatted on load, raw over the stream).
 */
class LogPresenter
{
    /**
     * The full log header for the initial render: identity + formatted dates +
     * the counter block.
     */
    public function presentLog(LogRecord $log): array
    {
        return [
            'id'         => (int) $log->id,
            'linkHandle' => (string) $log->linkHandle,
            'trigger'    => (string) $log->trigger,
            'startedAt'  => $this->datetime($log->startedAt),
        ] + $this->presentCounters($log);
    }

    /**
     * Status + finished-at + per-action counters — the slice the live stream
     * refreshes as a run progresses.
     */
    public function presentCounters(LogRecord $log): array
    {
        return [
            'status'         => (string) $log->status,
            'finishedAt'     => $this->datetime($log->finishedAt),
            'error'          => $log->error,
            'itemsSeen'      => (int) $log->itemsSeen,
            'itemsCreated'   => (int) $log->itemsCreated,
            'itemsUpdated'   => (int) $log->itemsUpdated,
            'itemsUnchanged' => (int) $log->itemsUnchanged,
            'itemsSkipped'   => (int) $log->itemsSkipped,
            'itemsDeleted'   => (int) $log->itemsDeleted,
            'itemsDisabled'  => (int) $log->itemsDisabled,
        ];
    }

    /**
     * One log-item row. The element chip is rendered server-side (Craft markup)
     * as a ready-to-inject HTML string; a removed element degrades to a
     * "(gone)" note.
     */
    public function presentItem(LogItemRecord $item): array
    {
        $elementHtml = null;

        if ($item->elementId) {
            $element = Craft::$app->getElements()->getElementById($item->elementId);
            $elementHtml = $element
                ? Compat::elementChipHtml($element, ['hyperlink' => true])
                : '<span class="light">#' . $item->elementId . ' (gone)</span>';
        }

        return [
            'id'          => (int) $item->id,
            'action'      => (string) $item->action,
            'matchValue'  => (string) ($item->matchValue ?? ''),
            'message'     => (string) ($item->message ?? ''),
            'elementHtml' => $elementHtml,
        ];
    }

    protected function datetime(mixed $value): string
    {
        return $value ? Craft::$app->getFormatter()->asDatetime($value) : '';
    }
}
