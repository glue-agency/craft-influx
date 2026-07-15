<?php

namespace GlueAgency\Influx\integrations\feedme\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\integrations\feedme\FeedMeConverter;
use GlueAgency\Influx\integrations\feedme\FeedMeImportResult;
use InvalidArgumentException;

/**
 * Converts craftcms/feed-me feeds into Influx links — the engine behind the
 * `influx/feed-me` console command.
 *
 * Reads straight from the `feedme_feeds` table, so Feed Me only needs to be
 * (or have been) installed — the plugin itself doesn't have to be enabled.
 * Data in, {@see FeedMeImportResult}s out — console presentation lives in
 * {@see \GlueAgency\Influx\console\controllers\FeedMeController}.
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

        if (! $all && $feedIds !== '') {
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
     * Convert and (unless `$dryRun`) save each feed as an Influx link.
     *
     * @return list<FeedMeImportResult>
     */
    public function importFeeds(array $feeds, bool $dryRun = false, bool $force = false): array
    {
        $plugin = Influx::getInstance();
        $converter = new FeedMeConverter();

        // Reserve existing handles so generated ones can't collide (saved links or this batch)
        $takenHandles = array_keys($plugin->links->getAllLinks());

        $results = [];

        foreach ($feeds as $feed) {
            $conversion = $converter->convert($feed);
            $link = $conversion->link;
            $link->handle = $this->uniqueHandle($link->handle, $takenHandles);
            $takenHandles[] = $link->handle;

            if ($dryRun) {
                $results[] = new FeedMeImportResult($feed, $link, $conversion->warnings);

                continue;
            }

            $saved = $plugin->links->saveLink($link, ! $force);
            $results[] = new FeedMeImportResult(
                $feed,
                $link,
                $conversion->warnings,
                $saved,
                $saved ? [] : $link->getErrorSummary(true),
            );
        }

        return $results;
    }

    /**
     * Append a numeric suffix until the handle is free.
     *
     * @param string[] $taken
     */
    protected function uniqueHandle(string $handle, array $taken): string
    {
        if (! in_array($handle, $taken, true)) {
            return $handle;
        }
        $i = 2;

        while (in_array("{$handle}{$i}", $taken, true)) {
            $i++;
        }

        return "{$handle}{$i}";
    }
}
