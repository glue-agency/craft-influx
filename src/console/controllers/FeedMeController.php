<?php

namespace GlueAgency\Influx\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use GlueAgency\Influx\console\ConsoleOutputCompatTrait;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\integrations\feedme\FeedMeImportResult;
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
 * The heavy lifting lives in {@see \GlueAgency\Influx\integrations\feedme\services\FeedMeService};
 * this controller only renders its results.
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

        if (! $feedMe->isInstalled()) {
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
        if (! $this->all && $feedIds === '') {
            $this->listFeeds($feeds);

            return ExitCode::OK;
        }

        $imported = 0;
        $failed = 0;

        foreach ($feedMe->importFeeds($feeds, $this->dryRun, $this->force) as $result) {
            $this->reportResult($result) ? $imported++ : $failed++;
        }

        $verb = $this->dryRun ? 'converted (dry run)' : 'imported';
        $this->stdout("\n{$imported} feed(s) {$verb}, {$failed} failed.\n");

        return $failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Print the feeds available for import.
     */
    protected function listFeeds(array $feeds): void
    {
        $this->stdout("Available Feed Me feeds:\n\n");
        Console::table(
            ['ID', 'Name', 'Type', 'Element', 'URL'],
            array_map(fn(array $feed) => [
                $feed['id'],
                $feed['name'],
                $feed['feedType'],
                $feed['elementType'],
                $feed['feedUrl'],
            ], $feeds),
        );
        $this->tip('Import with `influx/feed-me <ids>` or `influx/feed-me --all`.');
    }

    /**
     * Render one import result. Returns whether it counts as a success.
     */
    protected function reportResult(FeedMeImportResult $result): bool
    {
        $this->stdout("→ Converting feed #{$result->feed['id']} “{$result->feed['name']}”\n");

        foreach ($result->warnings as $warning) {
            $this->warning($warning);
        }

        if ($this->dryRun) {
            $this->stdout("  Link “{$result->link->handle}” (dry run):\n");
            $this->stdout(Json::encode($result->link->getConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            return true;
        }

        if ($result->saved) {
            $this->success("Imported as link “{$result->link->handle}”.");

            return true;
        }

        $this->failure("Link “{$result->link->handle}” failed validation and was not saved:");

        foreach ($result->errors as $error) {
            $this->stderr("  - {$error}\n");
        }
        $this->tip('Re-run with `--force` to save it anyway and finish it in the link builder.');

        return false;
    }
}
