<?php

namespace GlueAgency\Influx\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Json;
use GlueAgency\Influx\console\ConsoleOutputCompatTrait;
use GlueAgency\Influx\integrations\feedme\FeedMeConverter;
use GlueAgency\Influx\Influx;
use yii\console\ExitCode;

/**
 * `./craft influx/import-feed-me` — convert craftcms/feed-me feeds into
 * Influx links.
 *
 *   ./craft influx/import-feed-me                # list available feeds
 *   ./craft influx/import-feed-me 1,3            # import specific feeds
 *   ./craft influx/import-feed-me --all          # import everything
 *   ./craft influx/import-feed-me 1 --dry-run    # preview the link config without saving
 *   ./craft influx/import-feed-me 1 --force      # save even when the link doesn't validate
 *
 * Reads straight from the `feedme_feeds` table, so Feed Me only needs to be
 * (or have been) installed — the plugin itself doesn't have to be enabled.
 * The conversion is best-effort: anything that couldn't be carried over is
 * printed as a warning, and `--force` lets you save a half-converted link to
 * finish in the link builder.
 */
class ImportFeedMeController extends Controller
{
    use ConsoleOutputCompatTrait;

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

    public function actionIndex(string $feedIds = ''): int
    {
        if (!Craft::$app->getDb()->tableExists('{{%feedme_feeds}}')) {
            $this->failure('The `feedme_feeds` table does not exist — is Feed Me installed on this site?');
            return ExitCode::UNAVAILABLE;
        }

        $feeds = $this->fetchFeeds($feedIds);
        if ($feeds === null) {
            return ExitCode::USAGE;
        }
        if (empty($feeds)) {
            $this->stdout("No matching Feed Me feeds found.\n");
            return ExitCode::OK;
        }

        // No selection: list what's available instead of importing everything.
        if (!$this->all && $feedIds === '') {
            $this->listFeeds($feeds);
            return ExitCode::OK;
        }

        return $this->importFeeds($feeds);
    }

    /**
     * Load the requested `feedme_feeds` rows, or null on unusable input.
     * No ids + no --all returns every row (the caller lists them).
     */
    protected function fetchFeeds(string $feedIds): ?array
    {
        $query = (new Query())
            ->from('{{%feedme_feeds}}')
            ->orderBy(['id' => SORT_ASC]);

        if (!$this->all && $feedIds !== '') {
            $ids = array_filter(array_map('intval', explode(',', $feedIds)));
            if (empty($ids)) {
                $this->stderr("Pass one or more numeric feed ids, or --all\n");
                return null;
            }
            $query->where(['id' => $ids]);

            $feeds = $query->all();
            $found = array_column($feeds, 'id');
            $missing = array_diff($ids, array_map('intval', $found));
            if ($missing) {
                $this->stderr('Feed id(s) not found: ' . implode(', ', $missing) . "\n");
                return null;
            }
            return $feeds;
        }

        return $query->all();
    }

    /**
     * Print the feeds available for import.
     */
    protected function listFeeds(array $feeds): void
    {
        $this->stdout("Available Feed Me feeds:\n\n");
        $this->table(
            ['ID', 'Name', 'Type', 'Element', 'URL'],
            array_map(fn(array $feed) => [
                $feed['id'],
                $feed['name'],
                $feed['feedType'],
                $feed['elementType'],
                $feed['feedUrl'],
            ], $feeds),
        );
        $this->tip('Import with `influx/import-feed-me <ids>` or `influx/import-feed-me --all`.');
    }

    /**
     * Convert and (unless --dry-run) save each feed as an Influx link.
     */
    protected function importFeeds(array $feeds): int
    {
        $plugin = Influx::getInstance();
        $converter = new FeedMeConverter();

        // Reserve existing handles so generated ones can't collide — neither
        // with saved links nor between feeds in this batch.
        $takenHandles = array_keys($plugin->links->getAllLinks());

        $imported = 0;
        $failed = 0;

        foreach ($feeds as $feed) {
            $this->stdout("→ Converting feed #{$feed['id']} “{$feed['name']}”\n");

            $conversion = $converter->convert($feed);
            $link = $conversion->link;
            $link->handle = $this->uniqueHandle($link->handle, $takenHandles);
            $takenHandles[] = $link->handle;

            foreach ($conversion->warnings as $warning) {
                $this->warning($warning);
            }

            if ($this->dryRun) {
                $this->stdout("  Link “{$link->handle}” (dry run):\n");
                $this->stdout(Json::encode($link->getConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                $imported++;
                continue;
            }

            if ($plugin->links->saveLink($link, !$this->force)) {
                $this->success("Imported as link “{$link->handle}”.");
                $imported++;
                continue;
            }

            $failed++;
            $this->failure("Link “{$link->handle}” failed validation and was not saved:");
            foreach ($link->getErrorSummary(true) as $error) {
                $this->stderr("  - {$error}\n");
            }
            $this->tip('Re-run with `--force` to save it anyway and finish it in the link builder.');
        }

        $verb = $this->dryRun ? 'converted (dry run)' : 'imported';
        $this->stdout("\n{$imported} feed(s) {$verb}, {$failed} failed.\n");

        return $failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Append a numeric suffix until the handle is free.
     *
     * @param string[] $taken
     */
    protected function uniqueHandle(string $handle, array $taken): string
    {
        if (!in_array($handle, $taken, true)) {
            return $handle;
        }
        $i = 2;
        while (in_array("{$handle}{$i}", $taken, true)) {
            $i++;
        }
        return "{$handle}{$i}";
    }
}
