<?php

namespace GlueAgency\Influx\integrations\feedme\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Console;
use craft\helpers\Json;
use GlueAgency\Influx\integrations\feedme\FeedMeConverter;
use GlueAgency\Influx\Influx;
use InvalidArgumentException;

/**
 * Converts craftcms/feed-me feeds into Influx links — the engine behind the
 * `influx/feed-me` console command.
 *
 * Reads straight from the `feedme_feeds` table, so Feed Me only needs to be
 * (or have been) installed — the plugin itself doesn't have to be enabled.
 * Progress and results are written to the console via {@see Console}.
 */
class FeedMeService extends Component
{
    /**
     * Whether the `feedme_feeds` table exists — i.e. Feed Me is (or has at
     * some point been) installed on this site.
     */
    public function isInstalled(): bool
    {
        return Craft::$app->getDb()->tableExists('{{%feedme_feeds}}');
    }

    /**
     * Load the requested `feedme_feeds` rows.
     * No ids + `$all = false` returns every row (callers list them).
     *
     * @return array[]
     * @throws InvalidArgumentException when no usable ids are passed or an id doesn't exist
     */
    public function fetchFeeds(string $feedIds = '', bool $all = false): array
    {
        $query = (new Query())
            ->from('{{%feedme_feeds}}')
            ->orderBy(['id' => SORT_ASC]);

        if (!$all && $feedIds !== '') {
            $ids = array_filter(array_map('intval', explode(',', $feedIds)));
            if (empty($ids)) {
                throw new InvalidArgumentException('Pass one or more numeric feed ids, or --all');
            }
            $query->where(['id' => $ids]);

            $feeds = $query->all();
            $found = array_column($feeds, 'id');
            $missing = array_diff($ids, array_map('intval', $found));
            if ($missing) {
                throw new InvalidArgumentException('Feed id(s) not found: ' . implode(', ', $missing));
            }
            return $feeds;
        }

        return $query->all();
    }

    /**
     * Print the feeds available for import.
     */
    public function listFeeds(array $feeds): void
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
     * Convert and (unless `$dryRun`) save each feed as an Influx link.
     *
     * @return array{imported: int, failed: int}
     */
    public function importFeeds(array $feeds, bool $dryRun = false, bool $force = false): array
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

            if ($dryRun) {
                $this->stdout("  Link “{$link->handle}” (dry run):\n");
                $this->stdout(Json::encode($link->getConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                $imported++;
                continue;
            }

            if ($plugin->links->saveLink($link, !$force)) {
                $this->success("Imported as link “{$link->handle}”.");
                $imported++;
                continue;
            }

            $failed++;
            $this->failure("Link “{$link->handle}” failed validation and was not saved:");
            foreach ($link->getErrorSummary(true) as $error) {
                Console::stderr("  - {$error}\n");
            }
            $this->tip('Re-run with `--force` to save it anyway and finish it in the link builder.');
        }

        $verb = $dryRun ? 'converted (dry run)' : 'imported';
        $this->stdout("\n{$imported} feed(s) {$verb}, {$failed} failed.\n");

        return ['imported' => $imported, 'failed' => $failed];
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

    /**
     * Write to stdout, optionally ANSI-formatted — a service-context stand-in
     * for `craft\console\Controller::stdout()`.
     */
    protected function stdout(string $string, int ...$format): void
    {
        Console::stdout($format ? Console::ansiFormat($string, $format) : $string);
    }

    /** Emoji output helpers, matching {@see \GlueAgency\Influx\console\ConsoleOutputCompatTrait}. */
    protected function success(string $message): void
    {
        $this->stdout("✅ {$message}\n", Console::FG_GREEN);
    }

    protected function failure(string $message): void
    {
        $this->stdout("❌ {$message}\n", Console::FG_RED);
    }

    protected function warning(string $message): void
    {
        $this->stdout("⚠️ {$message}\n", Console::FG_YELLOW);
    }

    protected function tip(string $message): void
    {
        $this->stdout("💡 {$message}\n", Console::FG_YELLOW);
    }
}
