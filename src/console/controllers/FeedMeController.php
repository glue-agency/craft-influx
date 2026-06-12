<?php

namespace GlueAgency\Influx\console\controllers;

use craft\console\Controller;
use GlueAgency\Influx\console\ConsoleOutputCompatTrait;
use GlueAgency\Influx\Influx;
use InvalidArgumentException;
use yii\console\ExitCode;

/**
 * `./craft influx/feed-me` — convert craftcms/feed-me feeds into
 * Influx links.
 *
 *   ./craft influx/feed-me/import                # list available feeds
 *   ./craft influx/feed-me/import 1,3            # import specific feeds
 *   ./craft influx/feed-me/import --all          # import everything
 *   ./craft influx/feed-me/import 1 --dry-run    # preview the link config without saving
 *   ./craft influx/feed-me/import 1 --force      # save even when the link doesn't validate
 *
 * The heavy lifting lives in {@see \GlueAgency\Influx\integrations\feedme\services\FeedMeService}.
 */
class FeedMeController extends Controller
{
    use ConsoleOutputCompatTrait;

    /** @var string The default action: `influx/feed-me` runs the import. */
    public $defaultAction = 'import';

    /** Import every feed in the table. */
    public bool $all = false;

    /** Print the converted link config instead of saving. */
    public bool $dryRun = false;

    /** Save links even when they fail validation (finish them in the builder). */
    public bool $force = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['all', 'dryRun', 'force']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['a' => 'all', 'f' => 'force']);
    }

    public function actionImport(string $feedIds = ''): int
    {
        $feedMe = Influx::getInstance()->feedMe;

        if (!$feedMe->isInstalled()) {
            $this->failure('The `feedme_feeds` table does not exist — is Feed Me installed on this site?');
            return ExitCode::UNAVAILABLE;
        }

        try {
            $feeds = $feedMe->fetchFeeds($feedIds, $this->all);
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n");
            return ExitCode::USAGE;
        }

        if (empty($feeds)) {
            $this->stdout("No matching Feed Me feeds found.\n");
            return ExitCode::OK;
        }

        // No selection: list what's available instead of importing everything.
        if (!$this->all && $feedIds === '') {
            $feedMe->listFeeds($feeds);
            return ExitCode::OK;
        }

        $result = $feedMe->importFeeds($feeds, $this->dryRun, $this->force);

        return $result['failed'] === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
