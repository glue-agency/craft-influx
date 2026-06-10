<?php

namespace TDM\Influx\enums;

/**
 * What kicked off a sync run. Stored verbatim on the log record's `trigger`
 * column, so the backed values must stay stable.
 */
enum SyncTrigger: string
{
    case Console = 'console';
    case Cp = 'cp';
    case Queue = 'queue';
    case Element = 'element';
}
