<?php

namespace TDM\Influx\Tests\feature\sync;

use craft\elements\Entry;
use TDM\Influx\events\SyncItemEvent;
use TDM\Influx\events\SyncLinkEvent;
use TDM\Influx\Influx;
use TDM\Influx\services\SynchronizationService;
use TDM\Influx\Tests\feature\Support\EventRecorder;
use TDM\Influx\Tests\feature\Support\FeatureTestCase;
use TDM\Influx\Tests\feature\Support\LinkBuilder;

/**
 * End-to-end behaviour spec for {@see SynchronizationService::syncLink}.
 *
 * What gets pinned here:
 *
 *   - Lifecycle event ordering (beforeSyncLink → per-item events → afterSyncLink)
 *   - The four item actions: created, updated, unchanged, skipped
 *   - Hooks that can short-circuit the run (`beforeSyncLink.isValid=false`)
 *     or one item (`beforeItem.skip=true`, listener swapping `$event->element`)
 *   - `processing` whitelist: omit 'create' to skip unknown items, omit
 *     'update' to skip existing ones
 *   - Trigger label propagates to the Log row
 *   - Per-site loop when `siteEndpoints` is set
 *   - Paginator follows `paginatorNode`
 *   - `ago` presets turn into query params on the first fetch
 *
 * Each test is independent — DB rolls back at teardown.
 */
class SyncLinkLifecycleTest extends FeatureTestCase
{
    public function testCreatesNewEntryFromFeedItem(): void
    {
        $link = LinkBuilder::articles()->save();

        $this->data->queueFetch([
            ['id' => 'remote-1', 'title' => 'Hello world', 'slug' => 'hello-world'],
        ]);

        $log = Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(1, (int)$log->itemsCreated);
        $this->assertSame(0, (int)$log->itemsUpdated);
        $this->assertSame('ok', $log->status);

        $entry = Entry::find()->section('articles')->importId('remote-1')->one();
        $this->assertNotNull($entry, 'A new Entry should exist after a create sync.');
        $this->assertSame('Hello world', $entry->title);
        $this->assertSame('hello-world', $entry->slug);
    }

    public function testUpdatesExistingEntryByMatchValue(): void
    {
        $link = LinkBuilder::articles()->save();

        // Seed an entry with the matching importId, then sync a feed item
        // that changes its title.
        $existing = $this->seedEntry(['importId' => 'remote-1', 'title' => 'Old']);

        $this->data->queueFetch([
            ['id' => 'remote-1', 'title' => 'New', 'slug' => 'hello'],
        ]);

        $log = Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(0, (int)$log->itemsCreated);
        $this->assertSame(1, (int)$log->itemsUpdated);

        $refreshed = Entry::find()->id($existing->id)->status(null)->one();
        $this->assertSame('New', $refreshed->title);
    }

    public function testLogsUnchangedWhenNothingDiffers(): void
    {
        $link = LinkBuilder::articles()->save();

        $existing = $this->seedEntry([
            'importId' => 'remote-1',
            'title'    => 'Same',
            'slug'     => 'same',
        ]);

        $this->data->queueFetch([
            ['id' => 'remote-1', 'title' => 'Same', 'slug' => 'same'],
        ]);

        $log = Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(1, (int)$log->itemsUnchanged);
        $this->assertSame(0, (int)$log->itemsUpdated);
        $this->assertSame(
            $existing->dateUpdated->getTimestamp(),
            Entry::find()->id($existing->id)->status(null)->one()->dateUpdated->getTimestamp(),
            'Unchanged items must not bump dateUpdated — that proves no save was issued.',
        );
    }

    public function testSkipsItemsWithoutMatchValue(): void
    {
        $link = LinkBuilder::articles()->save();

        $this->data->queueFetch([
            ['title' => 'no-id', 'slug' => 'no-id'], // missing id node
        ]);

        $log = Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(1, (int)$log->itemsSkipped);
        $this->assertSame(0, (int)$log->itemsCreated);
    }

    public function testProcessingWhitelistGatesCreate(): void
    {
        $link = LinkBuilder::articles()
            ->withProcessing(['update']) // create not allowed
            ->save();

        $this->data->queueFetch([
            ['id' => 'fresh', 'title' => 'New', 'slug' => 'new'],
        ]);

        $log = Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(0, (int)$log->itemsCreated);
        $this->assertSame(1, (int)$log->itemsSkipped);
        $this->assertNull(
            Entry::find()->section('articles')->importId('fresh')->one(),
            'create not in processing list -> no entry must be created.',
        );
    }

    public function testProcessingWhitelistGatesUpdate(): void
    {
        $link = LinkBuilder::articles()
            ->withProcessing(['create']) // update not allowed
            ->save();

        $this->seedEntry(['importId' => 'remote-1', 'title' => 'Old']);

        $this->data->queueFetch([
            ['id' => 'remote-1', 'title' => 'New', 'slug' => 'new'],
        ]);

        $log = Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(1, (int)$log->itemsSkipped);
        $this->assertSame(
            'Old',
            Entry::find()->importId('remote-1')->status(null)->one()->title,
            'update not in processing list -> existing entries must be left alone.',
        );
    }

    public function testTriggerLabelIsPersistedOnTheLog(): void
    {
        $link = LinkBuilder::articles()->save();
        $this->data->queueFetch([]);

        $log = Influx::getInstance()->synchronization->syncLink($link, trigger: 'queue');
        $this->assertSame('queue', $log->trigger);

        $log = Influx::getInstance()->synchronization->syncLink($link, trigger: 'cp');
        $this->assertSame('cp', $log->trigger);
    }

    public function testBeforeSyncLinkCancelsTheRun(): void
    {
        $link = LinkBuilder::articles()->save();

        \yii\base\Event::on(
            SynchronizationService::class,
            SynchronizationService::EVENT_BEFORE_SYNC_LINK,
            static function (SyncLinkEvent $e) { $e->isValid = false; },
        );

        $this->data->queueFetch([['id' => 'remote-1', 'title' => 'x', 'slug' => 'x']]);

        $threw = null;
        try {
            Influx::getInstance()->synchronization->syncLink($link);
        } catch (\TDM\Influx\exceptions\InfluxException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw, 'Cancellation must surface as an InfluxException.');
        $this->assertCount(
            0,
            array_filter($this->data->calls, static fn($c) => $c['method'] === 'fetch'),
            'When the before-event cancels the run, DataService::fetch must NOT be called.',
        );
    }

    public function testBeforeItemCanSkipASingleItem(): void
    {
        $link = LinkBuilder::articles()->save();

        \yii\base\Event::on(
            SynchronizationService::class,
            SynchronizationService::EVENT_BEFORE_ITEM,
            static function (SyncItemEvent $e) {
                if (($e->item['id'] ?? null) === 'skipme') {
                    $e->skip = true;
                }
            },
        );

        $this->data->queueFetch([
            ['id' => 'keep',   'title' => 'A', 'slug' => 'a'],
            ['id' => 'skipme', 'title' => 'B', 'slug' => 'b'],
        ]);

        $log = Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(1, (int)$log->itemsCreated);
        $this->assertSame(1, (int)$log->itemsSkipped);
    }

    public function testBeforeItemCanSwapInADifferentElement(): void
    {
        $link = LinkBuilder::articles()->save();
        $alt = $this->seedEntry(['importId' => 'swap-target', 'title' => 'Old']);

        \yii\base\Event::on(
            SynchronizationService::class,
            SynchronizationService::EVENT_BEFORE_ITEM,
            static function (SyncItemEvent $e) use ($alt) {
                $e->element = $alt; // Force this item onto the seeded entry.
            },
        );

        $this->data->queueFetch([
            ['id' => 'irrelevant', 'title' => 'New', 'slug' => 'new'],
        ]);

        $log = Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(1, (int)$log->itemsUpdated);
        $refreshed = Entry::find()->id($alt->id)->status(null)->one();
        $this->assertSame('New', $refreshed->title);
    }

    public function testFiresEventsInExpectedOrder(): void
    {
        $link = LinkBuilder::articles()->save();
        $recorder = EventRecorder::attach();

        $this->data->queueFetch([
            ['id' => 'remote-1', 'title' => 'A', 'slug' => 'a'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(
            [
                SynchronizationService::EVENT_BEFORE_SYNC_LINK,
                SynchronizationService::EVENT_BEFORE_ITEM,
                SynchronizationService::EVENT_AFTER_ITEM_MAPPING,
                SynchronizationService::EVENT_AFTER_ITEM,
                SynchronizationService::EVENT_AFTER_SYNC_LINK,
            ],
            $recorder->names(),
        );
    }

    public function testAfterItemActionMatchesLogRow(): void
    {
        $link = LinkBuilder::articles()->save();
        $this->seedEntry(['importId' => 'remote-1', 'title' => 'Same', 'slug' => 'same']);
        $recorder = EventRecorder::attach();

        $this->data->queueFetch([
            ['id' => 'remote-1', 'title' => 'Same', 'slug' => 'same'], // unchanged
            ['id' => 'remote-2', 'title' => 'New',  'slug' => 'new'],  // created
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(['unchanged', 'created'], $recorder->actions());
    }

    public function testAfterSyncLinkCarriesAggregateCounters(): void
    {
        $link = LinkBuilder::articles()->save();
        $this->seedEntry(['importId' => 'remote-1', 'title' => 'Old']);
        $recorder = EventRecorder::attach();

        $this->data->queueFetch([
            ['id' => 'remote-1', 'title' => 'New', 'slug' => 'new'], // updated
            ['id' => 'remote-2', 'title' => 'X',   'slug' => 'x'],   // created
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $after = $recorder->payloads(SynchronizationService::EVENT_AFTER_SYNC_LINK);
        $this->assertCount(1, $after);
        /** @var SyncLinkEvent $payload */
        $payload = $after[0];
        $this->assertSame(2, $payload->itemsSeen);
        $this->assertSame(1, $payload->itemsCreated);
        $this->assertSame(1, $payload->itemsUpdated);
        $this->assertSame(0, $payload->itemsUnchanged);
    }

    public function testFollowsPaginatorAcrossPages(): void
    {
        $link = LinkBuilder::articles()->withPaginator('meta.next')->save();

        $this->data->queueFetch([
            'meta' => ['next' => 'https://example.test/articles?page=2'],
            'data' => [['id' => 'p1-1', 'title' => 'P1', 'slug' => 'p1']],
        ]);
        $this->data->queueFetchUrl('https://example.test/articles?page=2', [
            'meta' => ['next' => null],
            'data' => [['id' => 'p2-1', 'title' => 'P2', 'slug' => 'p2']],
        ]);

        // Tell the link the root list lives under .data
        $link->rootNode = 'data';

        $log = Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(2, (int)$log->itemsCreated);
        $this->assertSame(2, (int)$log->itemsSeen);
        // and the next-page URL was fetched
        $urls = array_column(
            array_filter($this->data->calls, static fn($c) => $c['method'] === 'fetchUrl'),
            'args',
        );
        $this->assertSame(['url' => 'https://example.test/articles?page=2'], $urls[0]);
    }

    public function testIteratesPerSiteWhenSiteEndpointsConfigured(): void
    {
        $link = LinkBuilder::articles()
            ->withSiteEndpoints([
                'default' => 'https://example.test/en/articles',
                'nl'      => 'https://example.test/nl/articles',
            ])
            ->save();

        $this->data->queueFetch([['id' => 'remote-1', 'title' => 'EN', 'slug' => 'en']]);
        $this->data->queueFetch([['id' => 'remote-1', 'title' => 'NL', 'slug' => 'nl']]);

        Influx::getInstance()->synchronization->syncLink($link);

        $sites = array_column(
            array_filter($this->data->calls, static fn($c) => $c['method'] === 'fetch'),
            'args',
        );
        $this->assertSame('default', $sites[0]['siteHandle']);
        $this->assertSame('nl',      $sites[1]['siteHandle']);
    }

    public function testAgoPresetTurnsIntoQueryParam(): void
    {
        $link = LinkBuilder::articles()
            ->withAgo([
                'hour' => ['since' => '-1 hour', 'queryParam' => 'modified_since'],
            ])
            ->save();

        $this->data->queueFetch([]);

        Influx::getInstance()->synchronization->syncLink($link, ago: 'hour');

        $first = $this->data->calls[0];
        $this->assertSame('fetch', $first['method']);
        $this->assertArrayHasKey('modified_since', $first['args']['queryParams']);
    }

    private function seedEntry(array $attrs): Entry
    {
        $entry = new Entry();
        $section = \Craft::$app->getEntries()->getSectionByHandle('articles');
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->title = $attrs['title'] ?? 'Untitled';
        if (isset($attrs['slug'])) {
            $entry->slug = $attrs['slug'];
        }
        \Craft::$app->getElements()->saveElement($entry, false);
        if (isset($attrs['importId'])) {
            $entry->setFieldValue('importId', $attrs['importId']);
            \Craft::$app->getElements()->saveElement($entry, false);
        }
        return $entry;
    }
}
