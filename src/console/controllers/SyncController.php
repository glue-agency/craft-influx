<?php

namespace TDM\Influx\console\controllers;

use Craft;
use craft\console\Controller;
use TDM\Influx\Influx;
use yii\console\ExitCode;

/**
 * `./craft influx/sync` — kick off feed runs from the CLI.
 *
 *   ./craft influx/sync news                 # one feed
 *   ./craft influx/sync news,events          # multiple
 *   ./craft influx/sync --all                # everything
 *   ./craft influx/sync news --ago=hour      # use the "hour" preset from the feed YAML
 */
class SyncController extends Controller
{
    public bool $all = false;
    public ?string $ago = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['all', 'ago']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['a' => 'all']);
    }

    public function actionIndex(string $handles = ''): int
    {
        $plugin = Influx::getInstance();

        if ($this->all) {
            $feeds = $plugin->feeds->all();
        } else {
            $handleList = array_filter(array_map('trim', explode(',', $handles)));
            if (!$handleList) {
                $this->stderr("Pass one or more feed handles, or --all\n");
                return ExitCode::USAGE;
            }
            $feeds = [];
            foreach ($handleList as $handle) {
                $feed = $plugin->feeds->getByHandle($handle);
                if (!$feed) {
                    $this->stderr("Feed '{$handle}' not found.\n");
                    return ExitCode::DATAERR;
                }
                $feeds[$handle] = $feed;
            }
        }

        foreach ($feeds as $feed) {
            $this->stdout("→ Syncing '{$feed->handle}'\n");
            try {
                $log = $plugin->synchronization->syncFeed($feed, $this->ago, 'console');
                $this->stdout(sprintf(
                    "  done. seen=%d created=%d updated=%d unchanged=%d skipped=%d\n",
                    $log->itemsSeen,
                    $log->itemsCreated,
                    $log->itemsUpdated,
                    $log->itemsUnchanged,
                    $log->itemsSkipped,
                ));
            } catch (\Throwable $e) {
                $this->stderr("  FAILED: " . $e->getMessage() . "\n");
                Craft::error($e, __METHOD__);
                return ExitCode::SOFTWARE;
            }
        }

        return ExitCode::OK;
    }
}
