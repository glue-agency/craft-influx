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
     * Default batch size for paginated link processing.
     */
    public int $defaultBatchSize = 100;
}
