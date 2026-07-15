<?php

namespace GlueAgency\Influx\console\controllers;

use Craft;
use craft\console\Controller;
use GlueAgency\Influx\console\ConsoleOutputCompatTrait;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;
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

        // One backup covering the whole run, taken once when ANY targeted link
        // requires it — then each link syncs with its own per-run backup
        // skipped. Same "one dump, then fan out" principle as a CP fan-out.
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
                // syncLink() runs synchronously here and returns one log per
                // site — the console reports only the dispatch, so the return is
                // ignored; the per-site logs are viewable in the CP. A single
                // site failing its own feed fetch is isolated to that site's log
                // and does NOT abort the run or change the exit code (D3); only a
                // non-fetch throw propagates here and returns SOFTWARE.
                $plugin->synchronization->syncLink($link, $this->offset, SyncTrigger::CONSOLE, $this->site);
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
