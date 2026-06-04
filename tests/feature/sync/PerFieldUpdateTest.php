<?php

namespace TDM\Influx\Tests\feature\sync;

use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use TDM\Influx\Influx;
use TDM\Influx\Tests\feature\Support\FeatureTestCase;
use TDM\Influx\Tests\feature\Support\LinkBuilder;

/**
 * Per-field-type behaviour spec. One test per "what does this field do when
 * a sync delivers data for it" question.
 *
 * Each test starts from the same seeded project config (see
 * tests/_craft/config/project/README.md) and exercises a single field type
 * end-to-end through SynchronizationService.
 */
class PerFieldUpdateTest extends FeatureTestCase
{
    // -- Native attributes ---------------------------------------------------

    public function testTitleAndSlugRouteThroughNativeAttribute(): void
    {
        $link = LinkBuilder::articles()->save();
        $this->data->queueFetch([
            ['id' => 'remote-1', 'title' => 'Hello', 'slug' => 'hello'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $entry = Entry::find()->importId('remote-1')->status(null)->one();
        $this->assertSame('Hello', $entry->title);
        $this->assertSame('hello', $entry->slug);
    }

    public function testStatusMapsToEnabledFlag(): void
    {
        $link = LinkBuilder::articles()
            ->withMappings(['status' => ['node' => 'state']])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'On',  'slug' => 'on',  'state' => 'live'],
            ['id' => 'r2', 'title' => 'Off', 'slug' => 'off', 'state' => 'disabled'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $on  = Entry::find()->importId('r1')->status(null)->one();
        $off = Entry::find()->importId('r2')->status(null)->one();
        $this->assertTrue($on->enabled);
        $this->assertFalse($off->enabled);
    }

    public function testNativeApplyReturnsTrueOnWriteFalseOnNoOp(): void
    {
        // Behavioural check: the engine treats `applyNativeAttribute() === true`
        // as "this contributed to a change". When the feed has no value AND no
        // default, no write should happen and the engine should be free to
        // declare 'unchanged'.
        $link = LinkBuilder::articles()->save();

        // Pre-create an entry that already matches what we'll send.
        $this->seedEntry(['importId' => 'r1', 'title' => 'A', 'slug' => 'a']);

        // Now sync a payload where title is absent — applyNativeAttribute
        // should not flag the entry as changed.
        $this->data->queueFetch([
            ['id' => 'r1', 'slug' => 'a'],
        ]);

        $log = Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame(1, (int)$log->itemsUnchanged);
        $this->assertSame(0, (int)$log->itemsUpdated);
    }

    // -- Plain custom field --------------------------------------------------

    public function testPlainTextFieldRoutesViaDefaultStrategy(): void
    {
        $link = LinkBuilder::articles()
            ->withMappings(['summary' => ['node' => 'excerpt']])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'excerpt' => 'A blurb'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $entry = Entry::find()->importId('r1')->status(null)->one();
        $this->assertSame('A blurb', $entry->getFieldValue('summary'));
    }

    // -- Lightswitch ---------------------------------------------------------

    public function testLightswitchCoercesTruthyStrings(): void
    {
        $link = LinkBuilder::articles()
            ->withMappings(['featured' => ['node' => 'is_featured']])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'is_featured' => 'yes'],
            ['id' => 'r2', 'title' => 'B', 'slug' => 'b', 'is_featured' => 'no'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $this->assertTrue(Entry::find()->importId('r1')->one()->getFieldValue('featured'));
        $this->assertFalse(Entry::find()->importId('r2')->one()->getFieldValue('featured'));
    }

    public function testLightswitchCustomTruthyList(): void
    {
        $link = LinkBuilder::articles()
            ->withMappings([
                'featured' => [
                    'node' => 'flag',
                    'options' => ['truthy' => ['ja']],
                ],
            ])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'flag' => 'ja'],
            ['id' => 'r2', 'title' => 'B', 'slug' => 'b', 'flag' => 'yes'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $this->assertTrue(Entry::find()->importId('r1')->one()->getFieldValue('featured'));
        $this->assertFalse(Entry::find()->importId('r2')->one()->getFieldValue('featured'));
    }

    // -- Dropdown ------------------------------------------------------------

    public function testDropdownAcceptsValueDirectly(): void
    {
        $link = LinkBuilder::articles()
            ->withMappings(['region' => ['node' => 'area']])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'area' => 'north'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $this->assertSame('north', Entry::find()->importId('r1')->one()->getFieldValue('region')->value);
    }

    public function testDropdownValueMapRewritesIncomingValue(): void
    {
        $link = LinkBuilder::articles()
            ->withMappings([
                'region' => [
                    'node' => 'area',
                    'options' => ['valueMap' => ['N' => 'north', 'S' => 'south']],
                ],
            ])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'area' => 'N'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);
        $this->assertSame('north', Entry::find()->importId('r1')->one()->getFieldValue('region')->value);
    }

    // -- Assets --------------------------------------------------------------

    public function testAssetsModeIdAttachesExistingAsset(): void
    {
        $asset = $this->seedAsset('cover.jpg');
        $link = LinkBuilder::articles()
            ->withMappings([
                'cover' => ['node' => 'cover_id', 'options' => ['mode' => 'id']],
            ])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'cover_id' => $asset->id],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $ids = Entry::find()->importId('r1')->one()->getFieldValue('cover')->ids();
        $this->assertSame([$asset->id], $ids);
    }

    public function testAssetsModeUrlMatchesByFilename(): void
    {
        $asset = $this->seedAsset('existing.png');
        $link = LinkBuilder::articles()
            ->withMappings([
                'cover' => ['node' => 'image', 'options' => ['mode' => 'url']],
            ])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'image' => $asset->getUrl()],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $ids = Entry::find()->importId('r1')->one()->getFieldValue('cover')->ids();
        $this->assertSame([$asset->id], $ids);
    }

    public function testAssetsUploadDownloadsRemoteUrlWhenEnabled(): void
    {
        // This test stubs AssetUploadService too so no real HTTP fires.
        $upload = new class extends \TDM\Influx\services\AssetUploadService {
            public ?Asset $next = null;
            public function init(): void {}
            public function uploadFromUrl(
                string $volumeHandle,
                string $url,
                string $folderPath = '',
                string $conflict = 'index',
            ): ?Asset {
                return $this->next;
            }
        };

        $newlyUploaded = $this->seedAsset('downloaded.jpg');
        $upload->next = $newlyUploaded;
        Influx::getInstance()->set('assetUpload', $upload);

        $link = LinkBuilder::articles()
            ->withMappings([
                'cover' => [
                    'node' => 'image',
                    'options' => [
                        'mode'     => 'url',
                        'upload'   => true,
                        'volume'   => 'testUploads',
                        'conflict' => 'index',
                    ],
                ],
            ])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'image' => 'https://remote.test/foo.jpg'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $ids = Entry::find()->importId('r1')->one()->getFieldValue('cover')->ids();
        $this->assertSame([$newlyUploaded->id], $ids);
    }

    public function testAssetsRecursiveNativeSubFieldsPopulateAltAndTitle(): void
    {
        $asset = $this->seedAsset('cover.jpg');
        $link = LinkBuilder::articles()
            ->withMappings([
                'cover' => [
                    'node' => 'cover_id',
                    'options' => ['mode' => 'id'],
                    'nativeFields' => [
                        'title' => ['node' => 'cover_title'],
                        'alt'   => ['node' => 'cover_alt'],
                    ],
                ],
            ])
            ->save();

        $this->data->queueFetch([
            [
                'id' => 'r1', 'title' => 'A', 'slug' => 'a',
                'cover_id'    => $asset->id,
                'cover_title' => 'Cover title',
                'cover_alt'   => 'Cover alt text',
            ],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $refreshed = Asset::find()->id($asset->id)->status(null)->one();
        $this->assertSame('Cover title',    $refreshed->title);
        $this->assertSame('Cover alt text', $refreshed->alt);
    }

    // -- Relations -----------------------------------------------------------

    public function testEntriesRelationMatchesByTitle(): void
    {
        $existing = $this->seedEntry(['importId' => 'related-1', 'title' => 'Related One']);
        $link = LinkBuilder::articles()
            ->withMappings([
                'related' => [
                    'node' => 'related_title',
                    'options' => ['match' => 'title'],
                ],
            ])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'related_title' => 'Related One'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $ids = Entry::find()->importId('r1')->one()->getFieldValue('related')->ids();
        $this->assertSame([$existing->id], $ids);
    }

    public function testEntriesRelationCreatesMissingWhenAuthorised(): void
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('articles');
        $type = $section->getEntryTypes()[0];

        $link = LinkBuilder::articles()
            ->withMappings([
                'related' => [
                    'node' => 'related_title',
                    'options' => [
                        'match'  => 'title',
                        'create' => true,
                        'group'  => ['sectionId' => $section->id, 'typeId' => $type->id],
                    ],
                ],
            ])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'related_title' => 'Brand new related'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $createdRelated = Entry::find()->title('Brand new related')->status(null)->one();
        $this->assertNotNull($createdRelated, 'options.create must materialise a missing related entry.');

        $ids = Entry::find()->importId('r1')->one()->getFieldValue('related')->ids();
        $this->assertSame([$createdRelated->id], $ids);
    }

    public function testEntriesRelationWithoutGroupDoesNotCreate(): void
    {
        $link = LinkBuilder::articles()
            ->withMappings([
                'related' => [
                    'node' => 'related_title',
                    'options' => ['match' => 'title', 'create' => true], // missing group -> bail
                ],
            ])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'related_title' => 'Brand new related'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $this->assertNull(
            Entry::find()->title('Brand new related')->status(null)->one(),
            'Without group.sectionId/typeId the strategy must NOT create — we’d be guessing where to drop it.',
        );
    }

    public function testCategoriesRelationMatchesBySlugAndScopesToGroup(): void
    {
        $group = Craft::$app->getCategories()->getGroupByHandle('regions');
        $existing = $this->seedCategory($group->id, 'North', 'north');

        $link = LinkBuilder::articles()
            ->withMappings([
                'regions' => [
                    'node' => 'region_slug',
                    'options' => ['match' => 'slug'],
                ],
            ])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'region_slug' => 'north'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);
        $ids = Entry::find()->importId('r1')->one()->getFieldValue('regions')->ids();
        $this->assertSame([$existing->id], $ids);
    }

    public function testTagsAutoCreateInConfiguredGroup(): void
    {
        $link = LinkBuilder::articles()
            ->withMappings([
                'topics' => [
                    'node' => 'topic',
                    'options' => ['match' => 'title'],
                    // No 'create' key: Tags strategy defaults to true.
                ],
            ])
            ->save();

        $this->data->queueFetch([
            ['id' => 'r1', 'title' => 'A', 'slug' => 'a', 'topic' => 'breaking news'],
        ]);

        Influx::getInstance()->synchronization->syncLink($link);

        $created = Tag::find()->title('breaking news')->status(null)->one();
        $this->assertNotNull($created, 'Tags strategy auto-creates by default.');

        $ids = Entry::find()->importId('r1')->one()->getFieldValue('topics')->ids();
        $this->assertSame([$created->id], $ids);
    }

    // -- Test helpers --------------------------------------------------------

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

    private function seedAsset(string $filename): Asset
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('testUploads');
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

        $tmp = tempnam(sys_get_temp_dir(), 'influx-test-');
        file_put_contents($tmp, str_repeat('x', 16));

        $asset = new Asset();
        $asset->tempFilePath = $tmp;
        $asset->setFilename($filename);
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volume->id);
        $asset->setScenario(Asset::SCENARIO_CREATE);
        Craft::$app->getElements()->saveElement($asset);

        return $asset;
    }

    private function seedCategory(int $groupId, string $title, string $slug): Category
    {
        $cat = new Category();
        $cat->groupId = $groupId;
        $cat->title = $title;
        $cat->slug = $slug;
        Craft::$app->getElements()->saveElement($cat);
        return $cat;
    }
}
