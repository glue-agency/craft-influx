<?php

namespace TDM\Influx\services;

use craft\base\Component;
use craft\helpers\Db;
use TDM\Influx\models\Feed;
use TDM\Influx\records\Log as LogRecord;
use TDM\Influx\records\LogItem as LogItemRecord;

/**
 * Thin wrapper around the log records. The sync engine opens a run with
 * start(), writes per-item rows via recordItem(), and closes the run with
 * finish() or fail().
 */
class LogsService extends Component
{
    public function start(Feed $feed, string $trigger, ?string $siteHandle = null): LogRecord
    {
        $log = new LogRecord();
        $log->feedHandle = $feed->handle;
        $log->trigger = $trigger;
        $log->siteHandle = $siteHandle;
        $log->status = 'running';
        $log->startedAt = Db::prepareDateForDb(new \DateTime());
        $log->save(false);

        return $log;
    }

    public function recordItem(
        LogRecord $log,
        string $action,
        ?int $elementId = null,
        ?string $matchValue = null,
        ?string $message = null,
        ?array $payload = null,
    ): void {
        $item = new LogItemRecord();
        $item->logId = $log->id;
        $item->elementId = $elementId;
        $item->matchValue = $matchValue !== null ? (string)$matchValue : null;
        $item->action = $action;
        $item->message = $message;
        $item->payload = $payload !== null ? json_encode($payload) : null;
        $item->save(false);

        // Roll up counters on the parent run for cheap dashboarding.
        $counterAttr = match ($action) {
            'created'   => 'itemsCreated',
            'updated'   => 'itemsUpdated',
            'unchanged' => 'itemsUnchanged',
            'skipped'   => 'itemsSkipped',
            'deleted', 'deleted-for-site', 'disabled' => 'itemsDeleted',
            default => null,
        };

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
        $log->save(false);
    }

    public function fail(LogRecord $log, string $error): void
    {
        $log->status = 'error';
        $log->error = $error;
        $log->finishedAt = Db::prepareDateForDb(new \DateTime());
        $log->save(false);
    }

    public function clear(): int
    {
        return LogRecord::deleteAll();
    }
}
