<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use TDM\Influx\models\Feed;

/**
 * Per-feed pre-run DB backup. Delegates to Craft's own backup machinery.
 *
 * Backups are off by default — only feeds with `backup: true` trigger one,
 * and only once per run regardless of how many sites the feed touches.
 */
class BackupService extends Component
{
    /**
     * @return string|null Path to the backup file, or null if backup is off /
     *                     could not be taken.
     */
    public function backupForFeed(Feed $feed): ?string
    {
        if (!$feed->backup) {
            return null;
        }

        try {
            return Craft::$app->getDb()->backup();
        } catch (\Throwable $e) {
            Craft::error(
                "Influx: backup for feed '{$feed->handle}' failed: " . $e->getMessage(),
                __METHOD__
            );
            return null;
        }
    }
}
