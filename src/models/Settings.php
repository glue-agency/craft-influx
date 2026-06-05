<?php

namespace TDM\Influx\models;

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
     * them. `0` disables automatic deletion, so runs accumulate indefinitely.
     * Pruning runs on Craft's GC event, not on every sync.
     */
    public int $logRetentionDays = 0;

    public function defineRules(): array
    {
        return [
            [['defaultItemCooldown', 'logRetentionDays'], 'integer', 'min' => 0],
            [['loggingEnabled'], 'boolean'],
        ];
    }
}
