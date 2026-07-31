<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use GlueAgency\Influx\db\Table;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\RunStatus;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use GlueAgency\Influx\sync\run\LogItemBuffer;
use GlueAgency\Influx\sync\run\RunOrigin;
use Throwable;
use yii\db\Expression;

/**
 * Thin wrapper around the log records. {@see \GlueAgency\Influx\sync\run\RunLifecycle}
 * opens a run with start() and closes it with finish() or fail();
 * {@see \GlueAgency\Influx\sync\item\ItemRunner} and
 * {@see \GlueAgency\Influx\sync\run\MissingElementsSweeper} write the per-item rows
 * via recordItem() in between.
 *
 * When `Settings::$loggingEnabled` is off, start() returns an unsaved record
 * (id === null) and the other methods short-circuit, so callers can keep the
 * same control flow without writing any rows.
 *
 * Per-item rows are BUFFERED, not written one at a time: recordItem() only
 * appends to an in-memory {@see LogItemBuffer} (and bumps the record's live
 * counters), recordSeen() adds to the run's feed-item count, and flush() writes
 * the whole page in one batch insert plus one counter UPDATE.
 * Net effect for the live log viewer: rows and counters advance per page (or
 * per {@see FLUSH_THRESHOLD} items on a huge page) instead of per item — a
 * bounded number of DB round-trips regardless of feed size.
 *
 * The flush protocol, in full — every caller that drains a buffer:
 *   - {@see \GlueAgency\Influx\sync\run\PageWalker} at each page boundary;
 *   - {@see \GlueAgency\Influx\sync\run\MissingElementsSweeper} once the sweep is
 *     done (the tail flush the queued path depends on);
 *   - {@see finish()} / {@see fail()} before closing the run;
 *   - {@see recordItem()} itself, whenever a single page overruns
 *     {@see FLUSH_THRESHOLD}.
 *
 * A buffer's ENTRY in the map outlives its rows, so the map is released where a
 * log's lifecycle ends ({@see forgetBuffer()}) — otherwise a long-lived queue
 * worker keeps one drained buffer per log id it ever touched.
 */
class LogsService extends Component
{
    /**
     * Rows per page on the Logs overview.
     */
    public const LOGS_PER_PAGE = 50;

    /**
     * Item rows per page in the log detail view — the bootstrap payload's first
     * page and every {@see itemPage()} the viewer's pager / live poll requests.
     */
    public const ITEMS_PER_PAGE = 25;

    /**
     * Cache key behind {@see errorLogCount()}.
     */
    protected const ERROR_COUNT_CACHE_KEY = 'influx:error-log-count';

    /**
     * Backstop TTL on that cache entry. Every in-plugin write that can change
     * the count invalidates explicitly ({@see invalidateErrorLogCount()}), so
     * this only bounds staleness from an out-of-band writer (a hand-run DELETE,
     * another plugin) — it is not the refresh mechanism.
     */
    protected const ERROR_COUNT_CACHE_DURATION = 3600;

    /**
     * Column order the buffered log-item rows are built in — kept in lockstep
     * with the batchInsert() call in {@see flush()}. `id` and the audit
     * columns are added by Craft; these are the ones recordItem() supplies.
     */
    protected const ITEM_COLUMNS = ['logId', 'elementId', 'matchValue', 'action', 'message', 'fieldErrors', 'changedFields', 'payload', 'mappings'];

    /**
     * Force a flush once a buffer reaches this many rows, so a single huge
     * feed page can't balloon memory or overrun the DB's max_allowed_packet
     * on the batch insert.
     */
    protected const FLUSH_THRESHOLD = 100;

    /**
     * Size ceiling on one item's stored mapping snapshot. It exists for the
     * batched inserts {@see flush()} runs — {@see FLUSH_THRESHOLD} rows in one
     * statement — which a single item nesting thousands of Matrix children could
     * otherwise push past the DB's `max_allowed_packet`, throwing away the whole
     * page's rows over one outlier. Past the ceiling the snapshot is simply not
     * stored: that item's drill-down renders flat, every other row is unharmed.
     */
    protected const MAPPINGS_MAX_BYTES = 200000;

    /**
     * Pending log-item buffers keyed by log record id. One buffer per open
     * run — recordItem() fills it, flush() drains it.
     *
     * @var array<int, LogItemBuffer>
     */
    protected array $buffers = [];

    /**
     * Per-request memo for {@see errorLogCount()}; null = not resolved yet.
     */
    protected ?int $memoizedErrorLogCount = null;

    /**
     * Whether a buffered item is an error row that hasn't reached the DB yet —
     * so {@see flush()} knows the insert it's about to run changes the error-log
     * count. Reset when that flush lands.
     */
    protected bool $bufferedErrorItem = false;

    /**
     * Opening a run also stamps it onto the link: a timestamp that outlives the
     * log, plus a pointer to the log (null when logging is off). An
     * element-triggered resync doesn't count as the link's "last run", so it
     * stamps nothing.
     *
     * @param RunOrigin $origin What started the run and, when a person asked for
     * it, who — both stored on the row, so a CP run can name the editor behind
     * it while a console run stays attributed to nobody.
     * @param string|null $siteHandle Site the run is scoped to (null = all).
     * @param string|null $offsetHandle Sliding-window preset the run applied.
     * @param int|null $elementId Resource a single-element run was triggered for.
     */
    public function start(
        Link $link,
        RunOrigin $origin,
        ?string $siteHandle = null,
        ?string $offsetHandle = null,
        ?int $elementId = null,
    ): LogRecord {
        $log = new LogRecord();
        $log->linkHandle = $link->handle;
        $log->trigger = $origin->trigger->value;
        $log->userId = $origin->userId;
        $log->siteHandle = $siteHandle;
        $log->offsetHandle = $offsetHandle;
        $log->elementId = $elementId;
        $log->status = RunStatus::RUNNING->value;
        $startedAt = new DateTime();
        $log->startedAt = Db::prepareDateForDb($startedAt);

        if ($this->loggingEnabled()) {
            $log->save(false);
        }

        if ($origin->trigger !== SyncTrigger::ELEMENT) {
            Influx::getInstance()->links->recordRun($link, $log->id ?: null, $startedAt);
        }

        return $log;
    }

    /**
     * The row is built in {@see ITEM_COLUMNS} order — the batch insert in
     * {@see flush()} relies on that alignment. The record's live counters are
     * advanced immediately, because progress callbacks and after-run events
     * read them off the in-memory record rather than the DB; the DB catches up
     * on the next flush.
     *
     * Bumps the row's own action counter only. `itemsSeen` is NOT a row count —
     * see {@see recordSeen()}.
     *
     * @param array<string, string> $fieldErrors {handle: message} for fields
     * whose strategy threw — the count the item list flags an item by
     * ({@see \GlueAgency\Influx\web\LogPresenter::presentItem()}), queryable on
     * its own without unpacking `$mappings`, which carries the same errors per
     * row for the drill-down.
     * @param list<string>|null $changedFields The mapping handles that actually
     * changed this run (see {@see \GlueAgency\Influx\sync\item\ItemSyncResult::changedFieldHandles()})
     * — the compact per-item record of what moved, beside the full `$mappings`
     * snapshot the drill-down renders. Three states, preserved into storage:
     * null = the item never went through populate (unknown); `[]` = compared,
     * nothing changed; a list = the handles that changed.
     * @param list<array>|null $mappings The item's PRESENTED mapping rows
     * ({@see \GlueAgency\Influx\web\ItemRowPresenter::presentMappingResults()}),
     * stored as the drill-down's display source so it reports the run's own
     * results instead of re-inspecting live state
     * ({@see \GlueAgency\Influx\services\InspectorService::inspectStoredLogItem()}).
     * Presenting a row tree isn't free, so callers should skip BUILDING it when
     * logging is off ({@see loggingEnabled()}) — this method would only drop it
     * on the floor with the rest of the row.
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
        ?array $mappings = null,
    ): void {
        if (! $log->id) {
            return;
        }

        $counterAttr = $action->counterAttribute();

        $row = [
            $log->id,
            $elementId,
            $matchValue !== null ? (string) $matchValue : null,
            $action->value,
            $message,
            $fieldErrors !== [] ? json_encode($fieldErrors) : null,
            $changedFields !== null ? json_encode($changedFields) : null,
            $payload !== null ? json_encode($payload) : null,
            $this->encodeMappings($mappings, $log->id, $matchValue),
        ];

        $this->bufferFor($log)->add($row, $counterAttr);

        if ($action === ItemAction::ERROR) {
            $this->bufferedErrorItem = true;
        }

        if ($counterAttr) {
            $log->$counterAttr = (int) $log->$counterAttr + 1;
        }

        if ($this->bufferFor($log)->count() >= self::FLUSH_THRESHOLD) {
            $this->flush($log);
        }
    }

    /**
     * A mapping snapshot as the column stores it: JSON, or null when there is
     * nothing to store or the JSON can't be stored safely — it blew
     * {@see MAPPINGS_MAX_BYTES}, or json_encode() still refused it. Invalid
     * UTF-8 does NOT drop the snapshot: an element's stored content can carry
     * bad bytes the feed never sent (seen in the wild on a CKEditor field), and
     * losing all 45 rows over one bad value is the wrong trade — the offending
     * bytes become U+FFFD instead (JSON_INVALID_UTF8_SUBSTITUTE). A genuine
     * drop logs a warning naming the item, because a null column reads as "no
     * snapshot" and the drill-down renders flat — silent, the flat view looks
     * like a pre-snapshot row and the loss is undiagnosable.
     *
     * An EMPTY row list is not the same thing and is kept as `[]`: the item was
     * presented and simply maps no fields, so the drill-down must not claim its
     * data is missing.
     */
    protected function encodeMappings(?array $mappings, ?int $logId = null, ?string $matchValue = null): ?string
    {
        if ($mappings === null) {
            return null;
        }

        $json = json_encode($mappings, JSON_INVALID_UTF8_SUBSTITUTE);
        $reason = null;

        if ($json === false) {
            $reason = 'json_encode() failed: ' . json_last_error_msg();
        } elseif (strlen($json) > self::MAPPINGS_MAX_BYTES) {
            $reason = 'the snapshot is ' . strlen($json) . ' bytes, over the ' . self::MAPPINGS_MAX_BYTES . '-byte cap';
        }

        if ($reason !== null) {
            Craft::warning(
                "Dropped the mapping snapshot for item '{$matchValue}' on log #{$logId} — {$reason}. The item renders without a drill-down.",
                __METHOD__,
            );

            return null;
        }

        return $json;
    }

    /**
     * Count feed items against the run's `itemsSeen`.
     *
     * Separate from {@see recordItem()} because "seen" means WHAT THE FEED
     * CONTAINED, not how many rows the run wrote. The two diverge badly
     * otherwise: the missing-elements sweep writes a row per element the feed
     * never mentioned, sweep bails write a notice row, and an item can log twice
     * (its own outcome, then an ERROR row when an afterItem listener throws).
     * Counted once per item by {@see \GlueAgency\Influx\sync\run\PageWalker},
     * which is the one place a feed's items are iterated.
     *
     * Advances the in-memory record immediately, for the same reason
     * {@see recordItem()} does; the DB catches up on the next flush.
     */
    public function recordSeen(LogRecord $log, int $count = 1): void
    {
        if (! $log->id || $count < 1) {
            return;
        }

        $this->bufferFor($log)->addSeen($count);
        $log->itemsSeen = (int) $log->itemsSeen + $count;
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
     * sequentially, so there are never concurrent writers to race with. The
     * deltas are ints this class built itself, so interpolating them into the
     * `Expression` is safe.
     *
     * The batch insert is deliberately 3-arg, without Craft 4's fourth
     * `$includeAuditColumns`: both majors add `dateCreated`/`dateUpdated`/`uid`
     * themselves, and Craft 5 dropped the parameter, so passing it would be
     * dead weight.
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

        if ($buffer->rows() !== []) {
            $db->createCommand()
                ->batchInsert(Table::LOG_ITEMS, self::ITEM_COLUMNS, $buffer->rows())
                ->execute();
        }

        $updates = [];

        foreach ($buffer->counterDeltas() as $column => $delta) {
            $updates[$column] = new Expression("[[{$column}]] + {$delta}");
        }

        if ($updates !== []) {
            $db->createCommand()
                ->update(Table::LOGS, $updates, ['id' => $log->id])
                ->execute();
        }

        $buffer->clear();

        if ($this->bufferedErrorItem) {
            $this->bufferedErrorItem = false;
            $this->invalidateErrorLogCount();
        }
    }

    public function finish(LogRecord $log): void
    {
        $this->flush($log);
        $this->forgetBuffer($log);

        $log->status = RunStatus::OK->value;
        $log->finishedAt = Db::prepareDateForDb(new DateTime());

        if ($log->id) {
            $log->save(false);
            $this->invalidateErrorLogCount();
        }
    }

    /**
     * The pending buffer is flushed first so already-processed rows aren't
     * lost, but a throwing flush is caught and only warned about: nothing may
     * stop the ERROR status from landing. The buffer is released either way —
     * rows a failed flush couldn't write have nowhere else to go, and the run is
     * over.
     */
    public function fail(LogRecord $log, string $error): void
    {
        try {
            $this->flush($log);
        } catch (Throwable $e) {
            Craft::warning("Influx: flushing log #{$log->id} before fail() threw: {$e->getMessage()}", __METHOD__);
        }

        $this->forgetBuffer($log);

        $log->status = RunStatus::ERROR->value;
        $log->error = $error;
        $log->finishedAt = Db::prepareDateForDb(new DateTime());

        if ($log->id) {
            $log->save(false);
            $this->invalidateErrorLogCount();
        }
    }

    /**
     * The pending-row buffer for a log record, created on first use. Keyed by
     * the record id so a reloaded record shares the buffer of the id it carries
     * — and re-created lazily after a {@see forgetBuffer()}, which is what makes
     * a log reopened across queue steps
     * ({@see SynchronizationService::batchStep()}) safe: it simply buffers into a
     * fresh one.
     */
    protected function bufferFor(LogRecord $log): LogItemBuffer
    {
        return $this->buffers[$log->id] ??= new LogItemBuffer();
    }

    /**
     * Release a log's buffer entry. {@see flush()} empties the buffer OBJECT but
     * leaves its map entry behind, so without this a long-lived queue worker
     * accumulates one drained {@see LogItemBuffer} per log id it ever wrote.
     * Called wherever a log's lifecycle ends: {@see finish()} and {@see fail()}
     * (after their flush — the rows must land first), and {@see delete()}, where
     * any still-pending rows have nowhere left to land anyway.
     *
     * {@see deleteOlderThan()} needs no release: it only ever removes logs older
     * than a retention window in days, never one this request opened.
     */
    protected function forgetBuffer(LogRecord $log): void
    {
        if ($log->id) {
            unset($this->buffers[$log->id]);
        }
    }

    /**
     * How many logs have errors — the CP nav badge count. A log "has errors"
     * when its run failed (`status = 'error'`) OR it recorded at least one
     * error item (an item that threw while the run itself finished ok). Counts
     * distinct logs, not error occurrences, so the badge reads as "N logs need
     * a look". Zero while everything's clean; clears as error logs are deleted
     * or age out of retention.
     *
     * CACHED, because {@see \GlueAgency\Influx\Influx::getCpNavItem()} asks on
     * EVERY control-panel request and the answer only changes when a log is
     * written or removed: a per-request memo in front of a Craft cache entry, so
     * the COUNT + subquery runs once per change rather than once per nav render.
     *
     * INVALIDATED BY (the complete set of writes that can move the number):
     *   - {@see flush()}, when the rows it inserts include an error item
     *     (buffered by {@see recordItem()}, so an in-flight run's error only
     *     counts once it has actually landed);
     *   - {@see finish()} and {@see fail()} — a run's final status;
     *   - {@see delete()}, {@see clear()}, {@see deleteOlderThan()} — rows going
     *     away.
     *
     * {@see start()} deliberately doesn't: a freshly opened run is `running`,
     * which is neither an error status nor an error item.
     */
    public function errorLogCount(): int
    {
        if ($this->memoizedErrorLogCount !== null) {
            return $this->memoizedErrorLogCount;
        }

        $cache = Craft::$app->getCache();
        $cached = $cache->get(self::ERROR_COUNT_CACHE_KEY);

        if ($cached !== false) {
            return $this->memoizedErrorLogCount = (int) $cached;
        }

        $count = $this->queryErrorLogCount();
        $cache->set(self::ERROR_COUNT_CACHE_KEY, $count, self::ERROR_COUNT_CACHE_DURATION);

        return $this->memoizedErrorLogCount = $count;
    }

    /**
     * The uncached COUNT behind {@see errorLogCount()}.
     */
    protected function queryErrorLogCount(): int
    {
        return (int) LogRecord::find()
            ->where(['status' => RunStatus::ERROR->value])
            ->orWhere(['id' => (new Query())
                ->select(['logId'])
                ->from(Table::LOG_ITEMS)
                ->where(['action' => ItemAction::ERROR->value]),
            ])
            ->count();
    }

    /**
     * Drop both layers of {@see errorLogCount()}'s cache. Called from every
     * write listed in that method's docblock — keep the two in step.
     */
    protected function invalidateErrorLogCount(): void
    {
        $this->memoizedErrorLogCount = null;
        Craft::$app->getCache()->delete(self::ERROR_COUNT_CACHE_KEY);
    }

    /**
     * One log record by id, or null. The controllers' only route to a log — they
     * never touch the record class themselves (see
     * {@see \GlueAgency\Influx\controllers\AbstractController::logOr404()}).
     */
    public function getLogById(int $id): ?LogRecord
    {
        return LogRecord::findOne($id);
    }

    /**
     * One log-item record by id, or null.
     */
    public function getLogItemById(int $id): ?LogItemRecord
    {
        return LogItemRecord::findOne($id);
    }

    /**
     * Log records for a set of ids, keyed by id — one query for the Links
     * overview's per-link last-run lookups.
     *
     * @param int[] $ids
     * @return array<int, LogRecord>
     */
    public function logsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return LogRecord::find()->where(['id' => $ids])->indexBy('id')->all();
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
     * Drop one log row; its item rows go with it via the FK cascade. Any pending
     * buffer goes too — the row it would insert into no longer exists.
     */
    public function delete(LogRecord $log): void
    {
        $log->delete();
        $this->forgetBuffer($log);
        Influx::getInstance()->links->forgetDeletedLogs();
        $this->invalidateErrorLogCount();
    }

    /**
     * Every log row goes, so every buffer does too — see {@see delete()}.
     */
    public function clear(): int
    {
        $deleted = LogRecord::deleteAll();
        $this->buffers = [];
        Influx::getInstance()->links->forgetDeletedLogs();
        $this->invalidateErrorLogCount();

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
            $this->invalidateErrorLogCount();
        }

        return $deleted;
    }

    /**
     * Whether runs are persisted at all — {@see start()}'s gate, and THE signal
     * callers ask before doing work only a stored row would consume (see
     * {@see recordItem()}'s `$mappings`), so the setting is read in one place
     * instead of once per caller.
     */
    public function loggingEnabled(): bool
    {
        return (bool) Influx::getInstance()->getSettings()->loggingEnabled;
    }
}
