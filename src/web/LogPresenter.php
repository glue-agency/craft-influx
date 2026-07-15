<?php

namespace GlueAgency\Influx\web;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\DateTimeHelper;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;

/**
 * Shapes log records into the JSON the Vue log viewer (LogApp) renders.
 * Shared by {@see \GlueAgency\Influx\controllers\LogsController}'s
 * initial page payload and its live SSE frames, so the header, counters, and
 * row shapes can't drift between the first paint and live updates — dates are
 * formatted the same in both, which a previous inline-in-controller version
 * got wrong (formatted on load, raw over the stream).
 *
 * It also backs the server-rendered overviews: {@see resultSegments()},
 * {@see durationLabel()}, and {@see statusColor()} turn a run into the pill
 * vocabulary the Logs index shows as a "Result" column and the Links index
 * folds into its "Last run" cell. Their composition cores
 * ({@see composeResultSegments()}, {@see formatDuration()}) take primitives
 * so they stay unit-testable without a booted Craft.
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
            'duration'       => $this->durationLabel($log),
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
     * as a ready-to-inject HTML string; a removed element degrades to its
     * `#id` reference.
     *
     * When `$elementMap` is supplied (batch path), the element is read from it —
     * an absent id is a since-deleted element and degrades the same way. When
     * null (single-item path), the element is loaded on demand.
     *
     * @param array<int, ElementInterface>|null $elementMap id => element
     */
    public function presentItem(LogItemRecord $item, ?array $elementMap = null): array
    {
        // Plain title only (UI label, else match value, else row id) — no per-row chip
        $title = null;

        if ($item->elementId) {
            $element = $elementMap !== null
                ? ($elementMap[$item->elementId] ?? null)
                : Craft::$app->getElements()->getElementById($item->elementId);
            $title = $element
                ? (string) ($element->getUiLabel() ?: '#' . $element->id)
                : '#' . $item->elementId;
        }

        $matchValue = (string) ($item->matchValue ?? '');
        $title = $title ?? ($matchValue !== '' ? $matchValue : '#' . $item->id);

        // Field-error count — flags an item that committed despite a field failure
        $errorCount = count($this->fieldErrors($item->fieldErrors));

        return [
            'id'         => (int) $item->id,
            'action'     => (string) $item->action,
            'matchValue' => $matchValue,
            'message'    => (string) ($item->message ?? ''),
            'title'      => $title,
            'errorCount' => $errorCount,
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
     * Overlay the run-time "which fields changed" flags onto presented mapping
     * rows, matched on the row's `handle`. The stored flags are AUTHORITATIVE
     * over the drill-down's re-inspection: that re-inspection is a dry run
     * against the element's LIVE state, so a field that changed at sync time
     * reads "no change" once the saved element already carries the new value —
     * every row of a successfully-updated item would otherwise show "no". The
     * flags captured when the item was synced (the record's `changedFields`
     * column) are the honest record.
     *
     * `$changedFieldsJson` is that raw column value, three-state:
     *   - a JSON array (incl. `[]`) — each row's `changed` becomes whether its
     *     handle is in the list; an empty list makes every row false (the item
     *     was compared and nothing changed);
     *   - null / malformed — the run stored nothing (items that never went
     *     through populate: sweep rows, or errors before an element), so every
     *     row's `changed` is set to null and the viewer renders its "?" state
     *     rather than a misleading live recalculation.
     *
     * Takes the raw JSON string rather than the record so it stays unit-testable
     * without a booted Craft, mirroring {@see fieldErrors()}.
     *
     * @param list<array> $mappings presented mapping rows (see {@see ItemRowPresenter::presentMappingResults()})
     * @return list<array>
     */
    public function overlayChangedFlags(array $mappings, ?string $changedFieldsJson): array
    {
        $decoded = ($changedFieldsJson !== null && $changedFieldsJson !== '')
            ? json_decode($changedFieldsJson, true)
            : null;
        $changed = is_array($decoded) ? $decoded : null;

        foreach ($mappings as &$mapping) {
            if ($changed === null) {
                $mapping['changed'] = null;
            } else {
                $handle = $mapping['handle'] ?? null;
                $mapping['changed'] = $handle !== null && in_array($handle, $changed, true);
            }
        }
        unset($mapping);

        return $mappings;
    }

    // Overview presentation
    // =========================================================================

    /**
     * The run's outcome as an ordered list of pill segments — one per action
     * that actually happened — for the Logs overview's "Result" column and the
     * Links overview's "Last run" detail line.
     *
     * @return list<array{count: int, kind: string, color: string}>
     */
    public function resultSegments(LogRecord $log): array
    {
        return self::composeResultSegments([
            'seen'      => (int) $log->itemsSeen,
            'created'   => (int) $log->itemsCreated,
            'updated'   => (int) $log->itemsUpdated,
            'unchanged' => (int) $log->itemsUnchanged,
            'skipped'   => (int) $log->itemsSkipped,
            'disabled'  => (int) $log->itemsDisabled,
            'deleted'   => (int) $log->itemsDeleted,
        ], (string) $log->status);
    }

    /**
     * Composition core for {@see resultSegments()} — kept on primitives so it's
     * unit-testable without a booted Craft or a real record.
     *
     * A still-running (or pending) run leads with an informative "N seen"
     * progress pill; a settled run drops it (the seen total moves to the sub
     * line). Only actions with a non-zero count appear, in a fixed order, each
     * carrying the result palette's colour (green = wrote, gray = neutral,
     * red = destructive).
     *
     * @param array{seen?: int, created?: int, updated?: int, unchanged?: int, skipped?: int, disabled?: int, deleted?: int} $counters
     * @return list<array{count: int, kind: string, color: string}>
     */
    public static function composeResultSegments(array $counters, string $status): array
    {
        $segments = [];

        if (in_array($status, ['running', 'pending'], true)) {
            $segments[] = ['count' => (int) ($counters['seen'] ?? 0), 'kind' => 'seen', 'color' => 'blue'];
        }

        // Fixed display order → result-palette colour ('seen' handled above)
        $palette = [
            'created'   => 'green',
            'updated'   => 'green',
            'unchanged' => 'gray',
            'skipped'   => 'gray',
            'disabled'  => 'gray',
            'deleted'   => 'red',
        ];

        foreach ($palette as $kind => $color) {
            $count = (int) ($counters[$kind] ?? 0);

            if ($count > 0) {
                $segments[] = ['count' => $count, 'kind' => $kind, 'color' => $color];
            }
        }

        return $segments;
    }

    /**
     * How long the run took, e.g. "41s", or null while it's still running (no
     * finish time yet).
     */
    public function durationLabel(LogRecord $log): ?string
    {
        if (! $log->finishedAt) {
            return null;
        }

        $start = DateTimeHelper::toDateTime($log->startedAt);
        $end = DateTimeHelper::toDateTime($log->finishedAt);

        if (! $start || ! $end) {
            return null;
        }

        return self::formatDuration($end->getTimestamp() - $start->getTimestamp());
    }

    /**
     * Format a duration in seconds the way the overviews show it — raw seconds
     * with an `s` suffix (matching the run log's own display). Null for a
     * missing or negative span.
     */
    public static function formatDuration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds < 0) {
            return null;
        }

        return $seconds . 's';
    }

    /**
     * Craft status-dot class for a run status: `live` (ok), `expired` (error),
     * or `pending` (running / anything else). The one place the run
     * status → dot colour mapping lives, shared by both overviews.
     */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            'ok'    => 'live',
            'error' => 'expired',
            default => 'pending',
        };
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
