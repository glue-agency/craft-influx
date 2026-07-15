<?php

namespace GlueAgency\Influx\enums;

use Craft;

/**
 * What kicked off a sync run. Stored verbatim on the log record's `trigger`
 * column, so the backed values must stay stable.
 */
enum SyncTrigger: string
{
    case CONSOLE = 'console';
    case CP = 'cp';
    case QUEUE = 'queue';
    case ELEMENT = 'element';

    /**
     * Human-readable label for the CP — e.g. the logs overview trigger filter.
     */
    public function label(): string
    {
        return match ($this) {
            self::CONSOLE => Craft::t('influx', 'Console'),
            self::CP      => Craft::t('influx', 'Control panel'),
            self::QUEUE   => Craft::t('influx', 'Queue'),
            self::ELEMENT => Craft::t('influx', 'Element'),
        };
    }
}
