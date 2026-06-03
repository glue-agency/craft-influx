<?php

namespace TDM\Influx\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    /**
     * Directory (relative to Craft config path) that holds feed YAML files.
     */
    public string $configDirectory = 'influx';

    /**
     * Default cooldown (seconds) between per-element manual syncs.
     */
    public int $defaultItemCooldown = 30;

    /**
     * Default batch size for paginated feed processing.
     */
    public int $defaultBatchSize = 100;

    public function absoluteConfigPath(): string
    {
        return Craft::$app->path->getConfigPath() . DIRECTORY_SEPARATOR . $this->configDirectory;
    }
}
