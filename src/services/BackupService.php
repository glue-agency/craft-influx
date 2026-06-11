<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use GlueAgency\Influx\models\Link;

/**
 * Per-link pre-run DB backup. Delegates to Craft's own backup machinery.
 */
class BackupService extends Component
{
    /**
     * @return string|null Path to the backup file, or null if backup is off /
     *                     could not be taken.
     */
    public function backupForLink(Link $link): ?string
    {
        if (!$link->backup) {
            return null;
        }

        try {
            return Craft::$app->getDb()->backup();
        } catch (\Throwable $e) {
            Craft::error(
                "Influx: backup for link '{$link->handle}' failed: " . $e->getMessage(),
                __METHOD__,
            );
            return null;
        }
    }
}
