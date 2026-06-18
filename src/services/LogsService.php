<?php

namespace GlueAgency\Influx\services;

use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;

/**
 * Thin wrapper around the log records. SynchronizationService opens a run
 * with start(), writes per-item rows via recordItem(), and closes the run
 * with finish() or fail().
 *
 * When `Settings::$loggingEnabled` is off, start() returns an unsaved record
 * (id === null) and the other methods short-circuit, so callers can keep the
 * same control flow without writing any rows.
 */
class LogsService extends Component
{
    public function start(Link $link, SyncTrigger $trigger, ?string $siteHandle = null): LogRecord
    {
        $log = new LogRecord();
        $log->linkHandle = $link->handle;
        $log->trigger = $trigger->value;
        $log->siteHandle = $siteHandle;
        $log->status = 'running';
        $log->startedAt = Db::prepareDateForDb(new DateTime());

        if ($this->loggingEnabled()) {
            $log->save(false);
        }

        return $log;
    }

    /**
     * @param array<string, string> $fieldErrors {handle: message} for fields
     * whose strategy threw — stored so the drill-down can show each on its own
     * field row even when re-inspection can't reproduce it.
     */
    public function recordItem(
        LogRecord $log,
        ItemAction $action,
        ?int $elementId = null,
        ?string $matchValue = null,
        ?string $message = null,
        ?array $payload = null,
        array $fieldErrors = [],
    ): void {
        if (! $log->id) {
            return;
        }

        $item = new LogItemRecord();
        $item->logId = $log->id;
        $item->elementId = $elementId;
        $item->matchValue = $matchValue !== null ? (string) $matchValue : null;
        $item->action = $action->value;
        $item->message = $message;
        $item->fieldErrors = $fieldErrors !== [] ? json_encode($fieldErrors) : null;
        $item->payload = $payload !== null ? json_encode($payload) : null;
        $item->save(false);

        $counterAttr = $action->counterAttribute();

        if ($counterAttr) {
            $log->$counterAttr = (int) $log->$counterAttr + 1;
        }
        $log->itemsSeen = (int) $log->itemsSeen + 1;
        $log->save(false);
    }

    public function finish(LogRecord $log): void
    {
        $log->status = 'ok';
        $log->finishedAt = Db::prepareDateForDb(new DateTime());

        if ($log->id) {
            $log->save(false);
        }
    }

    public function fail(LogRecord $log, string $error): void
    {
        $log->status = 'error';
        $log->error = $error;
        $log->finishedAt = Db::prepareDateForDb(new DateTime());

        if ($log->id) {
            $log->save(false);
        }
    }

    /**
     * Most recent log per link handle, keyed by handle — powers the "last
     * run" column on the links index. One newest-first query, keeping the
     * first (newest) row seen for each handle.
     *
     * @return array<string, LogRecord>
     */
    public function lastRunPerLink(): array
    {
        $out = [];
        $logs = LogRecord::find()
            ->orderBy(['startedAt' => SORT_DESC])
            ->all();

        foreach ($logs as $log) {
            if (! isset($out[$log->linkHandle])) {
                $out[$log->linkHandle] = $log;
            }
        }

        return $out;
    }

    /**
     * One page of logs, newest first, plus the total for the pager.
     *
     * @return array{logs: LogRecord[], total: int}
     */
    public function paginate(int $page, int $perPage): array
    {
        $query = LogRecord::find()->orderBy(['startedAt' => SORT_DESC]);
        $total = (int) $query->count();
        $logs = $query->offset(($page - 1) * $perPage)->limit($perPage)->all();

        return ['logs' => $logs, 'total' => $total];
    }

    /**
     * Recent runs for one link handle, newest first — powers the read-only
     * link view's run history.
     *
     * @return LogRecord[]
     */
    public function recentForLink(string $linkHandle, int $limit): array
    {
        return LogRecord::find()
            ->where(['linkHandle' => $linkHandle])
            ->orderBy(['startedAt' => SORT_DESC])
            ->limit($limit)
            ->all();
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
    public function itemPage(LogRecord $log, array $actions, int $offset, int $limit): array
    {
        $query = LogItemRecord::find()->where(['logId' => $log->id]);

        if ($actions !== []) {
            $query->andWhere(['action' => $actions]);
        }

        return $query
            ->orderBy(['id' => SORT_DESC])
            ->offset(max(0, $offset))
            ->limit($limit)
            ->all();
    }

    /**
     * Total items of a log matching the action filter (empty = all) — the
     * page count the log-detail pager divides by.
     *
     * @param string[] $actions
     */
    public function itemCount(LogRecord $log, array $actions): int
    {
        $query = LogItemRecord::find()->where(['logId' => $log->id]);

        if ($actions !== []) {
            $query->andWhere(['action' => $actions]);
        }

        return (int) $query->count();
    }

    public function clear(): int
    {
        return LogRecord::deleteAll();
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

        return LogRecord::deleteAll([
            '<', 'startedAt', Db::prepareDateForDb($cutoff),
        ]);
    }

    protected function loggingEnabled(): bool
    {
        return (bool) Influx::getInstance()->getSettings()->loggingEnabled;
    }
}
