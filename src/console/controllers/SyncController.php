<?php

namespace GlueAgency\Influx\console\controllers;

use Craft;
use craft\console\Controller;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;
use yii\console\ExitCode;

/**
 * `./craft influx/sync` — kick off link runs from the CLI.
 *
 *   ./craft influx/sync news                 # one link
 *   ./craft influx/sync news,events          # multiple
 *   ./craft influx/sync --all                # everything
 *   ./craft influx/sync news --offset=hour   # use the "hour" preset from the link config
 */
class SyncController extends Controller
{
    public bool $all = false;
    public ?string $offset = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['all', 'offset']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['a' => 'all']);
    }

    public function actionIndex(string $handles = ''): int
    {
        $plugin = Influx::getInstance();

        if ($this->all) {
            $links = $plugin->links->getAllLinks();
        } else {
            $handleList = array_filter(array_map('trim', explode(',', $handles)));
            if (!$handleList) {
                $this->stderr("Pass one or more link handles, or --all\n");
                return ExitCode::USAGE;
            }
            $links = [];
            foreach ($handleList as $handle) {
                $link = $plugin->links->getLinkByHandle($handle);
                if (!$link) {
                    $this->stderr("Link '{$handle}' not found.\n");
                    return ExitCode::DATAERR;
                }
                $links[$handle] = $link;
            }
        }

        foreach ($links as $link) {
            $this->stdout("→ Syncing '{$link->handle}'\n");
            try {
                $log = $plugin->synchronization->syncLink($link, $this->offset, SyncTrigger::Console);
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
