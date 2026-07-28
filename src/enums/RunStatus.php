<?php

namespace GlueAgency\Influx\enums;

use Craft;

/**
 * Lifecycle state of a sync run. Stored verbatim on the log record's `status`
 * column, so the backed values must stay stable.
 *
 * A run is opened as RUNNING ({@see \GlueAgency\Influx\services\LogsService::start()})
 * and closed as OK or ERROR — nothing ever pre-creates a row, which is why
 * there's no "pending" state to model.
 */
enum RunStatus: string
{
    case RUNNING = 'running';
    case OK = 'ok';
    case ERROR = 'error';

    /**
     * Whether the run is still in flight: the log viewer keeps polling and the
     * overviews lead with a progress pill only while it is.
     */
    public function isLive(): bool
    {
        return $this === self::RUNNING;
    }

    /**
     * Craft status-dot class for the run — `live` (ok), `expired` (error),
     * `pending` (still running). Shared by both overviews.
     */
    public function color(): string
    {
        return match ($this) {
            self::OK      => 'live',
            self::ERROR   => 'expired',
            self::RUNNING => 'pending',
        };
    }

    /**
     * Human-readable label for the CP — e.g. the logs overview status filter.
     */
    public function label(): string
    {
        return match ($this) {
            self::RUNNING => Craft::t('influx', 'Running'),
            self::OK      => Craft::t('influx', 'OK'),
            self::ERROR   => Craft::t('influx', 'Failed'),
        };
    }
}
