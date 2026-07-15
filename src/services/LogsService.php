<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use GlueAgency\Influx\db\Table;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use GlueAgency\Influx\sync\LogItemBuffer;
use Throwable;
use yii\db\Expression;

/**
 * Thin wrapper around the log records. SynchronizationService opens a run
 * with start(), writes per-item rows via recordItem(), and closes the run
 * with finish() or fail().
 *
 * When `Settings::$loggingEnabled` is off, start() returns an unsaved record
 * (id === null) and the other methods short-circuit, so callers can keep the
 * same control flow without writing any rows.
 *
 * Per-item rows are BUFFERED, not written one at a time: recordItem() only
 * appends to an in-memory {@see LogItemBuffer} (and bumps the record's live
 * counters), and flush() writes the whole page in one batch insert plus one
 * counter UPDATE. The caller flushes at each page boundary (see
 * SynchronizationService), and finish()/fail() flush before closing the run.
 * Net effect for the live log viewer: rows and counters advance per page (or
 * per {@see FLUSH_THRESHOLD} items on a huge page) instead of per item — a
 * bounded number of DB round-trips regardless of feed size.
 */
class LogsService extends Component
{
    /**
     * Column order the buffered log-item rows are built in — kept in lockstep
     * with the batchInsert() call in {@see flush()}. `id` and the audit
     * columns are added by Craft; these are the ones recordItem() supplies.
     */
    protected const ITEM_COLUMNS = ['logId', 'elementId', 'matchValue', 'action', 'message', 'fieldErrors', 'changedFields', 'payload'];

    /**
     * Force a flush once a buffer reaches this many rows, so a single huge
     * feed page can't balloon memory or overrun the DB's max_allowed_packet
     * on the batch insert.
     */
    protected const FLUSH_THRESHOLD = 100;

    /**
     * Pending log-item buffers keyed by log record id. One buffer per open
     * run — recordItem() fills it, flush() drains it.
     *
     * @var array<int, LogItemBuffer>
     */
    protected array $buffers = [];
    /**
     * @param string|null $siteHandle Site the run is scoped to (null = all).
     * @param string|null $offsetHandle Sliding-window preset the run applied.
     * @param int|null $elementId Resource a single-element run was triggered for.
     */
    public function start(
        Link $link,
        SyncTrigger $trigger,
        ?string $siteHandle = null,
        ?string $offsetHandle = null,
        ?int $elementId = null,
    ): LogRecord {
        $log = new LogRecord();
        $log->linkHandle = $link->handle;
        $log->trigger = $trigger->value;
        $log->siteHandle = $siteHandle;
        $log->offsetHandle = $offsetHandle;
        $log->elementId = $elementId;
        $log->status = 'running';
        $startedAt = new DateTime();
        $log->startedAt = Db::prepareDateForDb($startedAt);

        if ($this->loggingEnabled()) {
            $log->save(false);
        }

        // Stamp the run onto the link itself — a timestamp that outlives the
        // log, plus a pointer to it (null when logging is off). Element-
        // triggered syncs are one-off single-resource resyncs, not feed runs,
        // so they don't count as the link's "last run" on the overview.
        if ($trigger !== SyncTrigger::ELEMENT) {
            Influx::getInstance()->links->recordRun($link, $log->id ?: null, $startedAt);
        }

        return $log;
    }

    /**
     * @param array<string, string> $fieldErrors {handle: message} for fields
     * whose strategy threw — stored so the drill-down can show each on its own
     * field row even when re-inspection can't reproduce it.
     * @param list<string>|null $changedFields The mapping handles that actually
     * changed this run (see {@see \GlueAgency\Influx\sync\ItemSyncResult::changedFieldHandles()}),
     * so the drill-down can report what really happened instead of re-deriving
     * it from a present-tense dry-run. Three states, preserved into storage:
     * null = the item never went through populate (unknown); `[]` = compared,
     * nothing changed; a list = the handles that changed.
     */
    public function recordItem(
        LogRecord $log,
        ItemAction $action,
        ?int $elementId = null,
        ?string $matchValue = null,
        ?string $message = null,
        ?array $payload = null,
        array $fieldErrors = [],
        ?array $changedFields = null,
    ): void {
        if (! $log->id) {
            return;
        }

        $counterAttr = $action->counterAttribute();

        // Row values in ITEM_COLUMNS order — the batch insert in flush() relies
        // on this alignment. json_encode logic matches the pre-buffer path.
        // changedFields, unlike fieldErrors, keeps an empty array as `[]` (not
        // null): "nothing changed" is information, distinct from "unknown".
        $row = [
            $log->id,
            $elementId,
            $matchValue !== null ? (string) $matchValue : null,
            $action->value,
            $message,
            $fieldErrors !== [] ? json_encode($fieldErrors) : null,
            $changedFields !== null ? json_encode($changedFields) : null,
            $payload !== null ? json_encode($payload) : null,
        ];

        $this->bufferFor($log)->add($row, $counterAttr);

        // Keep the record's live counters advancing immediately: progress
        // callbacks and the after-run events read $log->itemsSeen / the per-
        // action columns off the in-memory record, not the DB. The DB catches
        // up on the next flush(); finish()/fail() then reconcile absolutes.
        if ($counterAttr) {
            $log->$counterAttr = (int) $log->$counterAttr + 1;
        }
        $log->itemsSeen = (int) $log->itemsSeen + 1;

        if ($this->bufferFor($log)->count() >= self::FLUSH_THRESHOLD) {
            $this->flush($log);
        }
    }

    /**
     * Drain the record's buffer to the database: one batch insert of the
     * pending rows, then one counter UPDATE per accumulated delta. No-op when
     * the run isn't persisted or the buffer is empty/absent.
     *
     * Counters are written as increments (`[[col]] + delta`), not absolutes,
     * so a record reloaded between steps (see SynchronizationService::batchStep)
     * still lands the right totals. finish()/fail() then reconcile the absolute
     * counters from the in-memory record. Steps for one log run strictly
     * sequentially, so there are never concurrent writers to race with.
     */
    public function flush(LogRecord $log): void
    {
        if (! $log->id) {
            return;
        }

        $buffer = $this->buffers[$log->id] ?? null;

        if ($buffer === null || $buffer->isEmpty()) {
            return;
        }

        $db = Craft::$app->getDb();

        // 3-arg batchInsert (no audit columns) — Craft 4 and 5 both accept
        // this and add dateCreated/dateUpdated/uid themselves.
        $db->createCommand()
            ->batchInsert(Table::LOG_ITEMS, self::ITEM_COLUMNS, $buffer->rows())
            ->execute();

        $updates = [];

        foreach ($buffer->counterDeltas() as $column => $delta) {
            // Deltas are known ints built by us — safe to interpolate.
            $updates[$column] = new Expression("[[{$column}]] + {$delta}");
        }

        if ($updates !== []) {
            $db->createCommand()
                ->update(Table::LOGS, $updates, ['id' => $log->id])
                ->execute();
        }

        $buffer->clear();
    }

    public function finish(LogRecord $log): void
    {
        $this->flush($log);

        $log->status = 'ok';
        $log->finishedAt = Db::prepareDateForDb(new DateTime());

        if ($log->id) {
            $log->save(false);
        }
    }

    public function fail(LogRecord $log, string $error): void
    {
        // Flush first so rows for already-processed items aren't lost when the
        // run fails — but never let a flush failure prevent status='error'
        // from landing.
        try {
            $this->flush($log);
        } catch (Throwable $e) {
            Craft::warning("Influx: flushing log #{$log->id} before fail() threw: {$e->getMessage()}", __METHOD__);
        }

        $log->status = 'error';
        $log->error = $error;
        $log->finishedAt = Db::prepareDateForDb(new DateTime());

        if ($log->id) {
            $log->save(false);
        }
    }

    /**
     * The pending-row buffer for a log record, created on first use. Keyed by
     * the record id so a reloaded record shares the buffer of the id it carries.
     */
    protected function bufferFor(LogRecord $log): LogItemBuffer
    {
        return $this->buffers[$log->id] ??= new LogItemBuffer();
    }

    /**
     * How many logs have errors — the CP nav badge count. A log "has errors"
     * when its run failed (`status = 'error'`) OR it recorded at least one
     * error item (an item that threw while the run itself finished ok). Counts
     * distinct logs, not error occurrences, so the badge reads as "N logs need
     * a look". Zero while everything's clean; clears as error logs are deleted
     * or age out of retention.
     */
    public function errorLogCount(): int
    {
        return (int) LogRecord::find()
            ->where(['status' => 'error'])
            ->orWhere(['id' => (new Query())
                ->select(['logId'])
                ->from(Table::LOG_ITEMS)
                ->where(['action' => ItemAction::ERROR->value]),
            ])
            ->count();
    }

    /**
     * One page of logs, newest first, plus the total for the pager. Optionally
     * restricted to one link (by handle), one run status, and/or one trigger —
     * the filters the Logs overview toolbar exposes. A null filter is ignored,
     * so `paginate($page, $perPage)` still returns everything.
     *
     * @return array{logs: LogRecord[], total: int}
     */
    public function paginate(int $page, int $perPage, ?string $linkHandle = null, ?string $status = null, ?string $trigger = null): array
    {
        $query = LogRecord::find()->orderBy(['startedAt' => SORT_DESC]);

        if ($linkHandle !== null && $linkHandle !== '') {
            $query->andWhere(['linkHandle' => $linkHandle]);
        }

        if ($status !== null && $status !== '') {
            $query->andWhere(['status' => $status]);
        }

        if ($trigger !== null && $trigger !== '') {
            $query->andWhere(['trigger' => $trigger]);
        }

        $total = (int) $query->count();
        $logs = $query->offset(($page - 1) * $perPage)->limit($perPage)->all();

        return ['logs' => $logs, 'total' => $total];
    }

    /**
     * One page of a log's items, newest first, optionally restricted to a set
     * of action values (empty = all). Powers the paginated log-detail view so
     * the page never ships the whole run, and the live poll only ever fetches
     * the page in view.
     *
     * @param string[] $actions
     * @return LogItemRecord[]
     */
    public function itemPage(LogRecord $log, array $actions, int $offset, int $limit, ?string $search = null): array
    {
        return $this->itemQuery($log, $actions, $search)
            ->orderBy(['id' => SORT_DESC])
            ->offset(max(0, $offset))
            ->limit($limit)
            ->all();
    }

    /**
     * Total items of a log matching the action + search filter (empty = all) —
     * the page count the log-detail pager divides by.
     *
     * @param string[] $actions
     */
    public function itemCount(LogRecord $log, array $actions, ?string $search = null): int
    {
        return (int) $this->itemQuery($log, $actions, $search)->count();
    }

    /**
     * Base item query for a log, filtered by action (empty = all) and a free-
     * text search over the match value + message. Shared by the page + count
     * so the two always agree.
     *
     * @param string[] $actions
     */
    protected function itemQuery(LogRecord $log, array $actions, ?string $search): \craft\db\ActiveQuery
    {
        $query = LogItemRecord::find()->where(['logId' => $log->id]);

        if ($actions !== []) {
            $query->andWhere(['action' => $actions]);
        }

        if ($search !== null && $search !== '') {
            $query->andWhere(['or',
                ['like', 'matchValue', $search],
                ['like', 'message', $search],
            ]);
        }

        return $query;
    }

    /**
     * Drop one log row; its item rows go with it via the FK cascade.
     */
    public function delete(LogRecord $log): void
    {
        $log->delete();
        Influx::getInstance()->links->forgetDeletedLogs();
    }

    public function clear(): int
    {
        $deleted = LogRecord::deleteAll();
        Influx::getInstance()->links->forgetDeletedLogs();

        return $deleted;
    }

    /**
     * Drop log rows whose `startedAt` is older than `$days` days. Called by
     * Craft's GC event when retention is set. Returns the number of rows
     * deleted so callers can log/announce the cleanup if they want.
     */
    public function deleteOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = (new DateTime())->modify("-{$days} days");

        $deleted = LogRecord::deleteAll([
            '<', 'startedAt', Db::prepareDateForDb($cutoff),
        ]);

        if ($deleted > 0) {
            Influx::getInstance()->links->forgetDeletedLogs();
        }

        return $deleted;
    }

    protected function loggingEnabled(): bool
    {
        return (bool) Influx::getInstance()->getSettings()->loggingEnabled;
    }
}
