<?php

namespace TDM\Influx\Tests\feature\sync;

use Craft;
use craft\elements\Entry;
use TDM\Influx\Influx;
use TDM\Influx\Tests\feature\Support\FeatureTestCase;
use TDM\Influx\Tests\feature\Support\LinkBuilder;

/**
 * Compare-and-decide spec. The unit tests cover the per-strategy hasChanged
 * algorithm in isolation; this suite verifies the *integrated* outcome: when
 * the engine concludes "no change", `saveElement` is not invoked and the
 * 'unchanged' branch fires.
 *
 * The dateUpdated assertions are the most reliable proof of "saveElement
 * wasn't called" — a rolled-back DB transaction makes touch timestamps the
 * only observable side-effect.
 */
class CompareSemanticsTest extends FeatureTestCase
{
    public function testUnchangedItemDoesNotBumpDateUpdated(): void
    {
        $link = LinkBuilder::articles()->save();
        $existing = $this->seedEntry(['importId' => 'r1', 'title' => 'A', 'slug' => 'a']);

        $before = $existing->dateUpdated->getTimestamp();

        // Give the timestamp a chance to differ if a save *did* happen.
        sleep(1);

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a'],
        ]);
        Influx::getInstance()->synchronization->syncLink($link);

        $after = Entry::find()->id($existing->id)->status(null)->one()->dateUpdated->getTimestamp();
        $this->assertSame($before, $after, 'No-change sync MUST NOT touch dateUpdated.');
    }

    public function testSingleFieldChangeMarksItemAsUpdated(): void
    {
        $link = LinkBuilder::articles()
            ->withMappings(['summary' => ['node' => 'excerpt']])
            ->save();

        $existing = $this->seedEntry(['importId' => 'r1', 'title' => 'A', 'slug' => 'a']);
        $existing->setFieldValue('summary', 'Original');
        Craft::$app->getElements()->saveElement($existing, false);

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'excerpt' => 'Changed'],
        ]);

        $log = Influx::getInstance()->synchronization->syncLink($link);
        $this->assertSame(1, (int)$log->itemsUpdated);
        $this->assertSame('Changed', Entry::find()->id($existing->id)->one()->getFieldValue('summary'));
    }

    public function testAssetsCompareIsOrderInsensitive(): void
    {
        // The Assets strategy sorts current vs incoming IDs before comparing.
        // Two assets in [A, B] vs [B, A] is unchanged.
        $a = $this->seedAsset('a.jpg');
        $b = $this->seedAsset('b.jpg');

        $entry = $this->seedEntry(['importId' => 'r1', 'title' => 'A', 'slug' => 'a']);
        $entry->setFieldValue('cover', [$a->id, $b->id]);
        Craft::$app->getElements()->saveElement($entry, false);
        $beforeTouch = Entry::find()->id($entry->id)->one()->dateUpdated->getTimestamp();

        $link = LinkBuilder::articles()
            ->withMappings([
                'cover' => ['node' => 'cover_ids', 'options' => ['mode' => 'id']],
            ])
            ->save();

        sleep(1);

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'cover_ids' => [$b->id, $a->id]],
        ]);

        $log = Influx::getInstance()->synchronization->syncLink($link);
        $this->assertSame(1, (int)$log->itemsUnchanged);
        $this->assertSame(
            $beforeTouch,
            Entry::find()->id($entry->id)->one()->dateUpdated->getTimestamp(),
        );
    }

    private function seedEntry(array $attrs): Entry
    {
        $entry = new Entry();
        $section = Craft::$app->getEntries()->getSectionByHandle('articles');
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->title = $attrs['title'] ?? 'Untitled';
        if (isset($attrs['slug'])) {
            $entry->slug = $attrs['slug'];
        }
        Craft::$app->getElements()->saveElement($entry, false);
        if (isset($attrs['importId'])) {
            $entry->setFieldValue('importId', $attrs['importId']);
            Craft::$app->getElements()->saveElement($entry, false);
        }
        return $entry;
    }

    private function seedAsset(string $filename): \craft\elements\Asset
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('testUploads');
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

        $tmp = tempnam(sys_get_temp_dir(), 'influx-test-');
        file_put_contents($tmp, str_repeat('x', 16));

        $asset = new \craft\elements\Asset();
        $asset->tempFilePath = $tmp;
        $asset->setFilename($filename);
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volume->id);
        $asset->setScenario(\craft\elements\Asset::SCENARIO_CREATE);
        Craft::$app->getElements()->saveElement($asset);
        return $asset;
    }
}
