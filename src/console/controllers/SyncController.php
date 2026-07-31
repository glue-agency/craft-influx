<?php

namespace GlueAgency\Influx\console\controllers;

use Craft;
use craft\console\Controller;
use GlueAgency\Influx\console\ConsoleOutputCompatTrait;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\sync\run\RunOrigin;
use Throwable;
use yii\console\ExitCode;

/**
 * `./craft influx/sync` — kick off link runs from the CLI.
 *
 *   ./craft influx/sync news                 # one link
 *   ./craft influx/sync news,events          # multiple
 *   ./craft influx/sync --all                # everything
 *   ./craft influx/sync news --offset=hour   # use the "hour" preset from the link config
 *   ./craft influx/sync news --site=fr       # only the "fr" site-specific endpoint
 */
class SyncController extends Controller
{
    use ConsoleOutputCompatTrait;

    public bool $all = false;
    public ?string $offset = null;
    public ?string $site = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['all', 'offset', 'site']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['a' => 'all']);
    }

    /**
     * One backup covers the whole run, taken once up front if any targeted link
     * asks for it. The links themselves sync synchronously and their per-site
     * logs are viewable in the CP, so `syncLink()`'s return is ignored — a
     * site's own feed-fetch failure stays in that site's log, and only a
     * non-fetch throw propagates here and exits with `SOFTWARE`.
     */
    public function actionIndex(string $handles = ''): int
    {
        $plugin = Influx::getInstance();

        if ($this->all) {
            $links = $plugin->links->getAllLinks();
        } else {
            $handleList = array_filter(array_map('trim', explode(',', $handles)));

            if (! $handleList) {
                $this->stderr("Pass one or more link handles, or --all\n");

                return ExitCode::USAGE;
            }

            $links = [];

            foreach ($handleList as $handle) {
                $link = $plugin->links->getLinkByHandle($handle);

                if (! $link) {
                    $this->failure("Link '{$handle}' not found.");

                    return ExitCode::DATAERR;
                }

                $links[$handle] = $link;
            }
        }

        try {
            $plugin->backup->backupForLinks($links);
        } catch (Throwable $e) {
            $this->failure('Backup failed, aborting: ' . $e->getMessage());
            Craft::error($e, __METHOD__);

            return ExitCode::SOFTWARE;
        }

        foreach ($links as $link) {
            $this->stdout("→ Syncing '{$link->handle}'\n");

            try {
                $plugin->synchronization->syncLink($link, $this->offset, RunOrigin::console(), $this->site);
                $this->success('done.');
            } catch (Throwable $e) {
                $this->failure('FAILED: ' . $e->getMessage());
                Craft::error($e, __METHOD__);

                return ExitCode::SOFTWARE;
            }
        }

        return ExitCode::OK;
    }
}
