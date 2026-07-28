<?php

namespace GlueAgency\Influx\sync;

use GlueAgency\Influx\sync\item\ItemRunner;
use GlueAgency\Influx\sync\run\MissingElementsSweeper;

/**
 * The one place a match value is stringified for a log row. Shared by
 * {@see ItemRunner} (the feed's own value) and {@see MissingElementsSweeper}
 * (the value read back off a swept element), so every
 * {@see \GlueAgency\Influx\services\LogsService::recordItem()} call records it
 * the same way.
 */
class MatchValue
{
    /**
     * Null is preserved rather than cast: an item with no match value must log
     * NULL, not an empty string, so the two stay distinguishable in the viewer.
     */
    public static function forLog(mixed $value): ?string
    {
        return $value !== null ? (string) $value : null;
    }
}
