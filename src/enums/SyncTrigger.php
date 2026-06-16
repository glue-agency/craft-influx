<?php

namespace GlueAgency\Influx\enums;

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
}
