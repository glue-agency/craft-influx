<?php

namespace GlueAgency\Influx\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Default cooldown (seconds) between per-element manual syncs.
     */
    public int $defaultItemCooldown = 30;

    /**
     * Whether sync runs write to the log tables. Disable to skip all log
     * writes (run records + per-item rows) — useful for high-volume feeds
     * where the audit trail isn't worth the table growth.
     */
    public bool $loggingEnabled = true;

    /**
     * How many days of log history to keep before garbage collection removes
     * them (minimum 1). Pruning runs on Craft's GC event, not on every sync.
     */
    public int $logRetentionDays = 14;

    /**
     * How often (seconds) the log viewer asks for new rows while a run is
     * still going. Every poll is a request per open viewer, so a busy CP is
     * the reason to raise it; the trade is how far behind a live run reads.
     */
    public int $logPollInterval = 3;

    /**
     * Whether the HTTP client follows 3xx redirects when fetching feeds and
     * downloading assets. Off by default: a redirect can send a credentialed
     * request to an unexpected host. When on, redirects are capped and
     * restricted to http(s).
     */
    public bool $followRedirects = false;

    public function defineRules(): array
    {
        return [
            [['defaultItemCooldown'], 'integer', 'min' => 0],
            [['logRetentionDays'], 'integer', 'min' => 1],
            [['logPollInterval'], 'integer', 'min' => 1],
            [['loggingEnabled', 'followRedirects'], 'boolean'],
        ];
    }
}
