<?php

namespace TDM\Influx\services;

use craft\base\Component;
use craft\helpers\Db;
use TDM\Influx\enums\ItemAction;
use TDM\Influx\enums\SyncTrigger;
use TDM\Influx\Influx;
use TDM\Influx\models\Link;
use TDM\Influx\records\Log as LogRecord;
use TDM\Influx\records\LogItem as LogItemRecord;

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
        $log->startedAt = Db::prepareDateForDb(new \DateTime());

        if ($this->loggingEnabled()) {
            $log->save(false);
        }

        return $log;
    }

    public function recordItem(
        LogRecord $log,
        ItemAction $action,
        ?int $elementId = null,
        ?string $matchValue = null,
        ?string $message = null,
        ?array $payload = null,
    ): void {
        if (!$log->id) {
            return;
        }

        $item = new LogItemRecord();
        $item->logId = $log->id;
        $item->elementId = $elementId;
        $item->matchValue = $matchValue !== null ? (string)$matchValue : null;
        $item->action = $action->value;
        $item->message = $message;
        $item->payload = $payload !== null ? json_encode($payload) : null;
        $item->save(false);

        $counterAttr = $action->counterAttribute();

        if ($counterAttr) {
            $log->$counterAttr = (int)$log->$counterAttr + 1;
        }
        $log->itemsSeen = (int)$log->itemsSeen + 1;
        $log->save(false);
    }

    public function finish(LogRecord $log): void
    {
        $log->status = 'ok';
        $log->finishedAt = Db::prepareDateForDb(new \DateTime());
        if ($log->id) {
            $log->save(false);
        }
    }

    public function fail(LogRecord $log, string $error): void
    {
        $log->status = 'error';
        $log->error = $error;
        $log->finishedAt = Db::prepareDateForDb(new \DateTime());
        if ($log->id) {
            $log->save(false);
        }
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
        $cutoff = (new \DateTime())->modify("-{$days} days");
        return LogRecord::deleteAll([
            '<', 'startedAt', Db::prepareDateForDb($cutoff),
        ]);
    }

    protected function loggingEnabled(): bool
    {
        return (bool)Influx::getInstance()->getSettings()->loggingEnabled;
    }
}
