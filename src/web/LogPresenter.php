<?php

namespace GlueAgency\Influx\web;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\helpers\Template;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\RunStatus;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use Twig\Markup;

/**
 * Shapes log records into the JSON the Vue log viewer (LogApp) renders.
 * Shared by {@see \GlueAgency\Influx\controllers\LogsController}'s initial page
 * payload and the JSON its poll endpoints return — a running log is followed by
 * re-requesting the page in view on an interval, not over a persistent
 * connection — so the header, counters, and row shapes can't drift between the
 * first paint and a refresh; dates are formatted the same in both, which a
 * previous inline-in-controller version got wrong (formatted on load, raw on
 * refresh).
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
     *
     * The trigger and the partial-import preset ship as chips as well as as
     * values: the facts strip already chips the site and the user, and a run's
     * facts reading as one vocabulary is worth more than the distinction between
     * a fact with a component behind it and one without
     * ({@see \GlueAgency\Influx\helpers\Compat::valueChipHtml()} draws both).
     * The raw values stay — the viewer's own copy and the tests read them.
     */
    public function presentLog(LogRecord $log): array
    {
        $triggerLabel = SyncTrigger::tryFrom((string) $log->trigger)?->label() ?? (string) $log->trigger;

        return [
            'id'              => (int) $log->id,
            'linkHandle'      => (string) $log->linkHandle,
            'trigger'         => (string) $log->trigger,
            'triggerLabel'    => $triggerLabel,
            'triggerChipHtml' => Compat::valueChipHtml($triggerLabel),
            'userChipHtml'    => $this->userChipHtml($log),
            'siteHandle'      => $log->siteHandle,
            'offsetHandle'    => $log->offsetHandle,
            'offsetChipHtml'  => $log->offsetHandle ? Compat::valueChipHtml((string) $log->offsetHandle) : null,
            'startedAt'       => $this->datetime($log->startedAt),
        ] + $this->presentCounters($log);
    }

    /**
     * The users a page of runs was triggered by as Craft user chips (photo, name,
     * edit link), keyed by user id — what the Logs overview shows in its "User"
     * column, so a person reads the way people read everywhere else in the CP.
     *
     * Rendered markup rather than a string, so the overview can print a cell
     * without reaching for `|raw` — and can't forget to.
     *
     * @param LogRecord[] $logs
     * @return array<int, Markup>
     */
    public function userChips(array $logs): array
    {
        $chips = [];

        foreach ($this->usersForLogs($logs) as $id => $user) {
            $chips[$id] = Template::raw($this->userChip($user, $id));
        }

        return $chips;
    }

    /**
     * The chip for the user ONE run was triggered by, or null when nobody
     * triggered it (a console or cron run) — the log viewer's "User" fact, which
     * ships in its JSON payload, so this stays a raw string.
     */
    public function userChipHtml(LogRecord $log): ?string
    {
        if (! $log->userId) {
            return null;
        }

        $id = (int) $log->userId;

        return $this->userChip($this->usersForLogs([$log])[$id] ?? null, $id);
    }

    /**
     * One user chip. A HARD-purged user has no element left to chip, so it gets a
     * muted `#id` instead: baked in here rather than left to each caller, so no
     * screen has to spell the fallback (and none can spell it differently).
     */
    protected function userChip(?User $user, int $id): string
    {
        return $user
            ? Compat::elementChipHtml($user, ['hyperlink' => true])
            : Html::tag('span', '#' . $id, ['class' => 'light']);
    }

    /**
     * The users a page of runs was triggered by, keyed by user id, in ONE query —
     * the overview renders 50 rows, and a per-row lookup is the same N+1
     * {@see elementMap()} exists to avoid on the item list. Null for an id that
     * no longer resolves.
     *
     * `status(null)` is required: a user query defaults to enabled-only, so a
     * suspended or pending account would drop out and its runs would go
     * unattributed. `trashed(null)` for the same reason — a just-deleted account
     * still names the runs it triggered inside the log retention window, which is
     * the window where "who ran this" is actually asked.
     *
     * @param LogRecord[] $logs
     * @return array<int, ?User>
     */
    protected function usersForLogs(array $logs): array
    {
        $ids = [];

        foreach ($logs as $log) {
            if ($log->userId) {
                $ids[(int) $log->userId] = true;
            }
        }

        $ids = array_keys($ids);

        if ($ids === []) {
            return [];
        }

        $users = User::find()
            ->id($ids)
            ->status(null)
            ->trashed(null)
            ->indexBy('id')
            ->all();

        $resolved = [];

        foreach ($ids as $id) {
            $resolved[$id] = $users[$id] ?? null;
        }

        return $resolved;
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
            'itemsErrored'   => (int) $log->itemsErrored,
        ];
    }

    /**
     * Present a whole page of log-item rows with their elements pre-loaded in a
     * single query — the per-item {@see \craft\services\Elements::getElementById()}
     * the naive path runs is an N+1 across a 25-row page. `$elementType` is the
     * owning link's element class (null when the link has since been deleted,
     * which falls back to deduplicated per-id loads).
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
     * One log-item row. The row carries a plain-text title and no per-row
     * element chip, falling back in a fixed order: the element's UI label, else
     * the item's match value, else the row's own `#id`.
     *
     * A trashed element still resolves ({@see elementMap()} loads them) and is
     * flagged with `trashed` so the list can say so — only a HARD-deleted one is
     * unresolvable, and then the match value is the better label than an `#id`
     * pointing at nothing.
     *
     * When `$elementMap` is supplied (batch path), the element is read from it —
     * an absent id is a since-purged element and degrades the same way. When
     * null (single-item path), the element is loaded on demand.
     *
     * `errorCount` is the number of stored per-field errors
     * ({@see fieldErrors()}) — a non-zero count flags an item that committed
     * despite a field failure.
     *
     * @param array<int, ElementInterface>|null $elementMap id => element
     */
    public function presentItem(LogItemRecord $item, ?array $elementMap = null): array
    {
        $element = null;
        $title = null;

        if ($item->elementId) {
            $element = $elementMap !== null
                ? ($elementMap[$item->elementId] ?? null)
                : Craft::$app->getElements()->getElementById($item->elementId, null, null, ['trashed' => null]);

            if ($element) {
                $title = (string) ($element->getUiLabel() ?: '#' . $element->id);
            }
        }

        $matchValue = (string) ($item->matchValue ?? '');
        $title ??= $matchValue !== '' ? $matchValue : '#' . ($item->elementId ?: $item->id);

        $errorCount = count($this->fieldErrors($item->fieldErrors));

        return [
            'id'         => (int) $item->id,
            'action'     => (string) $item->action,
            'matchValue' => $matchValue,
            'message'    => (string) ($item->message ?? ''),
            'title'      => $title,
            'trashed'    => (bool) $element?->trashed,
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
            'error'     => (int) $log->itemsErrored,
        ], (string) $log->status);
    }

    /**
     * Composition core for {@see resultSegments()} — kept on primitives so it's
     * unit-testable without a booted Craft or a real record.
     *
     * A live run leads with an informative "N seen" progress pill; a settled one
     * drops it (the seen total moves to the sub line). Only actions with a
     * non-zero count appear, each carrying its {@see ItemAction::pillColor()}.
     * {@see ItemAction::countedCases()} supplies both which kinds exist and the
     * order they render in; `seen` isn't one of them, being the leading progress
     * pill handled separately.
     *
     * @param array{seen?: int, created?: int, updated?: int, unchanged?: int, skipped?: int, disabled?: int, deleted?: int, error?: int} $counters
     * @return list<array{count: int, kind: string, color: string}>
     */
    public static function composeResultSegments(array $counters, string $status): array
    {
        $segments = [];

        if (self::isLive($status)) {
            $segments[] = ['count' => (int) ($counters['seen'] ?? 0), 'kind' => 'seen', 'color' => 'blue'];
        }

        foreach (ItemAction::countedCases() as $case) {
            $count = (int) ($counters[$case->value] ?? 0);

            if ($count > 0) {
                $segments[] = ['count' => $count, 'kind' => $case->value, 'color' => $case->pillColor()];
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
     * Craft status-dot class for a stored run status, per
     * {@see RunStatus::color()}; an unrecognised value falls back to `pending`.
     */
    public static function statusColor(string $status): string
    {
        return RunStatus::tryFrom($status)?->color() ?? 'pending';
    }

    /**
     * Whether a stored run status is still in flight ({@see RunStatus::isLive()})
     * — the overviews' progress pill, the log viewer's poll switch and its
     * `done` flag. The string-typed accessor both the templates and the callers
     * reading the raw column need; an unrecognised value reads as settled.
     */
    public static function isLive(?string $status): bool
    {
        return RunStatus::tryFrom((string) $status)?->isLive() ?? false;
    }

    /**
     * Whether a stored run status is a failure — the Links overview swaps its
     * result summary for the error message. Companion to {@see isLive()} for
     * callers holding the raw column value.
     */
    public static function isFailed(?string $status): bool
    {
        return RunStatus::tryFrom((string) $status) === RunStatus::ERROR;
    }

    /**
     * Collect the elements referenced by a page of log items, keyed by id, in
     * as few queries as possible. When `$elementType` is a concrete element
     * class, one batched query loads every referenced element (any status, any
     * site); otherwise (link deleted) it falls back to deduplicated per-id
     * loads so a repeated id is still only fetched once.
     *
     * Trashed elements are loaded too (`trashed(null)`): the plugin's own
     * deletes are soft, so excluding them — which is what an element query does
     * by default — would degrade every row a sync deleted to a bare `#id`.
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
                ->trashed(null)
                ->siteId('*')
                ->unique()
                ->indexBy('id')
                ->all();
        }

        $elements = Craft::$app->getElements();
        $map = [];

        foreach ($ids as $id) {
            $element = $elements->getElementById($id, null, null, ['trashed' => null]);

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
