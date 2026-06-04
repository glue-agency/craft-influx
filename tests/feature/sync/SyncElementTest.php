<?php

namespace TDM\Influx\Tests\feature\sync;

use Craft;
use craft\elements\Entry;
use TDM\Influx\Influx;
use TDM\Influx\Tests\feature\Support\FeatureTestCase;
use TDM\Influx\Tests\feature\Support\LinkBuilder;

/**
 * `syncElement()` is the per-entry "Sync from remote" button path. Unlike a
 * full link sync, it:
 *
 *   - Calls fetchOne (not fetch) with `{ id: <match-value> }`.
 *   - Doesn't paginate.
 *   - Stamps the cooldown on success.
 *   - Uses trigger='element' on the log.
 */
class SyncElementTest extends FeatureTestCase
{
    public function testSyncsSingleElementUsingItemEndpoint(): void
    {
        $link = LinkBuilder::articles();
        $link->itemEndpoint = 'https://example.test/articles/{id}';
        $link = $link->save();

        $existing = $this->seedEntry(['importId' => 'remote-1', 'title' => 'Old']);

        $this->data->queueFetchOne([
            'id' => 'remote-1', 'title' => 'Refreshed', 'slug' => 'refreshed',
        ]);

        $log = Influx::getInstance()->synchronization->syncElement($link, $existing);

        $this->assertSame('element', $log->trigger);
        $this->assertSame(1, (int)$log->itemsUpdated);
        $this->assertSame(
            'Refreshed',
            Entry::find()->id($existing->id)->status(null)->one()->title,
        );

        // and fetchOne was called with the matchValue
        $fetchOne = array_values(array_filter(
            $this->data->calls,
            static fn($c) => $c['method'] === 'fetchOne',
        ));
        $this->assertSame(['id' => 'remote-1'], $fetchOne[0]['args']['tokens']);
    }

    public function testStampsCooldownOnSuccess(): void
    {
        $link = LinkBuilder::articles();
        $link->itemEndpoint = 'https://example.test/articles/{id}';
        $link->itemCooldown = 60;
        $link = $link->save();

        $existing = $this->seedEntry(['importId' => 'remote-1', 'title' => 'Old']);

        $this->data->queueFetchOne([
            'id' => 'remote-1', 'title' => 'New', 'slug' => 'new',
        ]);

        Influx::getInstance()->synchronization->syncElement($link, $existing);

        $this->assertGreaterThan(
            0,
            Influx::getInstance()->cooldown->remaining($link, $existing),
            'Cooldown service should have stamped a non-zero remaining window.',
        );
    }

    public function testFailsLoudlyWhenElementHasNoMatchValue(): void
    {
        $link = LinkBuilder::articles();
        $link->itemEndpoint = 'https://example.test/articles/{id}';
        $link = $link->save();

        $orphan = $this->seedEntry(['title' => 'No-import-id']); // no importId set

        $this->expectException(\TDM\Influx\exceptions\InfluxException::class);
        Influx::getInstance()->synchronization->syncElement($link, $orphan);
    }

    private function seedEntry(array $attrs): Entry
    {
        $entry = new Entry();
        $section = Craft::$app->getEntries()->getSectionByHandle('articles');
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->title = $attrs['title'] ?? 'Untitled';
        Craft::$app->getElements()->saveElement($entry, false);
        if (isset($attrs['importId'])) {
            $entry->setFieldValue('importId', $attrs['importId']);
            Craft::$app->getElements()->saveElement($entry, false);
        }
        return $entry;
    }
}
