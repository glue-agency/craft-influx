<?php

namespace GlueAgency\Influx\web;

use Craft;
use craft\base\ElementInterface;
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
     * Renders element chips — the single seam both the debug and log rows draw
     * their element markup from, so a chip looks the same in either viewer.
     */
    protected ItemRowPresenter $itemRows;

    /**
     * @param ItemRowPresenter|null $itemRows chip renderer; defaults to a fresh
     *                                        instance (only overridden in tests)
     */
    public function __construct(?ItemRowPresenter $itemRows = null)
    {
        $this->itemRows = $itemRows ?? new ItemRowPresenter();
    }

    /**
     * The full log header for the initial render: identity + formatted dates +
     * the counter block.
     */
    public function presentLog(LogRecord $log): array
    {
        return [
            'id'           => (int) $log->id,
            'linkHandle'   => (string) $log->linkHandle,
            'trigger'      => (string) $log->trigger,
            'siteHandle'   => $log->siteHandle,
            'offsetHandle' => $log->offsetHandle,
            'startedAt'    => $this->datetime($log->startedAt),
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
     * Present a whole page of log-item rows with their elements pre-loaded in a
     * single query — the per-item {@see getElementById()} the naive path runs
     * is an N+1 across a 25-row page. `$elementType` is the owning link's
     * element class (null when the link has since been deleted, which falls
     * back to deduplicated per-id loads).
     *
     * @param LogItemRecord[] $items
     * @return list<array>
     */
    public function presentItems(array $items, ?string $elementType = null): array
    {
        $map = $this->elementMap($items, $elementType);
        $rows = [];

        foreach ($items as $item) {
            $rows[] = $this->presentItem($item, $map);
        }

        return $rows;
    }

    /**
     * One log-item row. The element chip is rendered server-side (Craft markup)
     * as a ready-to-inject HTML string; a removed element degrades to a
     * "(gone)" note.
     *
     * When `$elementMap` is supplied (batch path), the element is read from it —
     * an absent id is a since-deleted element and degrades the same way. When
     * null (single-item path), the element is loaded on demand.
     *
     * @param array<int, ElementInterface>|null $elementMap id => element
     */
    public function presentItem(LogItemRecord $item, ?array $elementMap = null): array
    {
        $elementHtml = null;

        if ($item->elementId) {
            $element = $elementMap !== null
                ? ($elementMap[$item->elementId] ?? null)
                : Craft::$app->getElements()->getElementById($item->elementId);
            $elementHtml = $element
                ? $this->itemRows->elementChip($element)
                : '<span class="light">#' . $item->elementId . ' (gone)</span>';
        }

        // How many fields errored — lets the viewer flag an item that still
        // committed (created/updated) despite a field failure, which the
        // green action tag alone would otherwise hide.
        $errorCount = count($this->fieldErrors($item->fieldErrors));

        return [
            'id'          => (int) $item->id,
            'action'      => (string) $item->action,
            'matchValue'  => (string) ($item->matchValue ?? ''),
            'message'     => (string) ($item->message ?? ''),
            'elementHtml' => $elementHtml,
            'errorCount'  => $errorCount,
        ];
    }

    /**
     * Decode a log item's stored per-field errors (handle => message) into an
     * array. Empty when the item recorded no field errors or the stored JSON is
     * missing / malformed.
     *
     * Takes the raw JSON string (the record's `fieldErrors` column) rather than
     * the record itself so it stays unit-testable without a booted Craft — the
     * record is a Craft ActiveRecord whose attribute access needs the DB schema.
     *
     * @return array<string, string>
     */
    public function fieldErrors(?string $json): array
    {
        if (! $json) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Overlay run-time field errors onto presented mapping rows, matched on the
     * row's `handle`. The stored error is authoritative — a dry-run
     * re-inspection can't reproduce a non-deterministic failure (e.g. an asset
     * upload), so an error captured at run time is stamped back onto its row.
     *
     * @param list<array> $mappings presented mapping rows (see {@see ItemRowPresenter::presentMappingResults()})
     * @param array<string, string> $fieldErrors handle => message
     * @return list<array>
     */
    public function overlayFieldErrors(array $mappings, array $fieldErrors): array
    {
        if (empty($fieldErrors)) {
            return $mappings;
        }

        foreach ($mappings as &$mapping) {
            $handle = $mapping['handle'] ?? null;

            if ($handle !== null && isset($fieldErrors[$handle])) {
                $mapping['error'] = $fieldErrors[$handle];
            }
        }
        unset($mapping);

        return $mappings;
    }

    /**
     * Collect the elements referenced by a page of log items, keyed by id, in
     * as few queries as possible. When `$elementType` is a concrete element
     * class, one batched query loads every referenced element (any status, any
     * site); otherwise (link deleted) it falls back to deduplicated per-id
     * loads so a repeated id is still only fetched once.
     *
     * @param LogItemRecord[] $items
     * @return array<int, ElementInterface>
     */
    protected function elementMap(array $items, ?string $elementType): array
    {
        $ids = [];

        foreach ($items as $item) {
            if ($item->elementId) {
                $ids[(int) $item->elementId] = true;
            }
        }

        $ids = array_keys($ids);

        if (empty($ids)) {
            return [];
        }

        if ($elementType !== null && is_subclass_of($elementType, ElementInterface::class)) {
            return $elementType::find()
                ->id($ids)
                ->status(null)
                ->siteId('*')
                ->unique()
                ->indexBy('id')
                ->all();
        }

        $elements = Craft::$app->getElements();
        $map = [];

        foreach ($ids as $id) {
            $element = $elements->getElementById($id);

            if ($element) {
                $map[$id] = $element;
            }
        }

        return $map;
    }

    protected function datetime(mixed $value): string
    {
        return $value ? Craft::$app->getFormatter()->asDatetime($value) : '';
    }
}
