<?php

namespace TDM\Influx\Tests\feature\sync;

use Craft;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\services\Elements;
use TDM\Influx\Influx;
use TDM\Influx\Tests\feature\Support\FeatureTestCase;
use TDM\Influx\Tests\feature\Support\LinkBuilder;
use yii\base\Event;

/**
 * Craft fires `Elements::EVENT_BEFORE_SAVE_ELEMENT` /
 * `EVENT_AFTER_SAVE_ELEMENT` around its own save pipeline. Sync writes go
 * through `Craft::$app->getElements()->saveElement(...)`, so:
 *
 *   - Save events MUST fire for create + update paths.
 *   - Save events MUST NOT fire when an item is unchanged or skipped.
 *
 * This is the contract third-party listeners (analytics, search indexers,
 * preview-cache busts, ...) rely on.
 */
class ElementSaveEventsTest extends FeatureTestCase
{
    /** @var list<string> */
    private array $saveLog = [];

    protected function _before(): void
    {
        parent::_before();
        $this->saveLog = [];

        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, function (ModelEvent $e) {
            $this->saveLog[] = 'before:' . ($e->sender::class);
        });
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function (ModelEvent $e) {
            $this->saveLog[] = 'after:' . ($e->sender::class);
        });
    }

    public function testSaveEventsFireOnCreate(): void
    {
        $link = LinkBuilder::articles()->save();
        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $this->assertContains('before:' . Entry::class, $this->saveLog);
        $this->assertContains('after:' . Entry::class, $this->saveLog);
    }

    public function testSaveEventsFireOnUpdate(): void
    {
        $link = LinkBuilder::articles()->save();
        $existing = $this->seedEntry(['importId' => 'r1', 'title' => 'Old']);

        // Reset so we only capture saves triggered by the sync run.
        $this->saveLog = [];

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'New', 'slug' => 'new'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $this->assertContains('before:' . Entry::class, $this->saveLog);
        $this->assertContains('after:' . Entry::class, $this->saveLog);
    }

    public function testNoSaveEventsForUnchangedItems(): void
    {
        $link = LinkBuilder::articles()->save();
        $this->seedEntry(['importId' => 'r1', 'title' => 'Same', 'slug' => 'same']);

        $this->saveLog = [];

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'Same', 'slug' => 'same'],
        ]);
        Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(
            [],
            $this->saveLog,
            'Unchanged items must NOT trigger Elements::EVENT_BEFORE/AFTER_SAVE.',
        );
    }

    public function testNoSaveEventsForSkippedItems(): void
    {
        $link = LinkBuilder::articles()->withProcessing(['update'])->save(); // no create

        $this->saveLog = [];
        $this->data->queueFetch([
            ['id' => 'fresh', 'title' => 'X', 'slug' => 'x'],
        ]);
        Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame([], $this->saveLog);
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
