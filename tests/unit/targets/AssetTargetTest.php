<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craft\models\Volume;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\AssetUploadService;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\AssetTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * AssetTarget: the volume scopes and owns, and the FILE arrives through the
 * native `url` mapping like any other value.
 *
 * What's pinned here is the fetch/skip decision — the thing that decides whether
 * a nightly re-sync re-downloads a whole library — plus the fileless-create
 * refusal, which is the one failure Craft would otherwise report against an
 * attribute no mapping row is named after.
 *
 * The download service comes through the `uploads()` seam, so nothing here
 * touches HTTP or a booted Craft.
 */
class AssetTargetTest extends Unit
{
    public function testTheVolumeCriterionGatesBothPredicates(): void
    {
        $target = $this->targetWithVolumes(['images', 'documents']);
        $link = $this->link(['volume' => 'images']);

        $asset = $this->asset('images', null);
        $this->assertTrue($target->targetsElement($link, $asset));
        $this->assertFalse($target->claimsElement($link, $asset));

        $this->assertTrue($target->claimsElement($link, $this->asset('images', 'abc')));
        $this->assertFalse($target->targetsElement($link, $this->asset('documents', 'abc')));
    }

    public function testAScopedLinkClaimsExactlyItsVolume(): void
    {
        $this->assertSame(['images'], (new AssetTarget())->claimCells($this->link(['volume' => 'images'])));
    }

    public function testCreatingAnAssetWithoutAFileIsRefusedOnItsOwnRow(): void
    {
        $target = new AssetTarget();
        $asset = $this->asset('images', 'abc');

        $this->assertFalse($target->save($asset));
        // On `url`, so ValidationErrorRouter lands it on the row the operator
        // would go fix — Craft's own failure keys `tempFilePath`, which no row is
        // named after.
        $this->assertArrayHasKey('url', $asset->getErrors());
    }

    public function testANewAssetFetchesTheFileAndTakesItsFilename(): void
    {
        $target = $this->target();
        $asset = $this->asset('images', 'abc');

        $changed = $this->applyUrl($target, $asset, 'https://example.test/pics/cat.jpg');

        $this->assertTrue($changed);
        $this->assertSame('/tmp/influx-cat.jpg', $asset->tempFilePath);
        $this->assertSame('cat.jpg', $asset->getFilename());
        $this->assertSame(Asset::SCENARIO_CREATE, $asset->getScenario());
    }

    public function testAnExistingAssetWithTheSameFilenameIsLeftAlone(): void
    {
        // The whole point: a re-sync must not re-download a library that hasn't
        // changed.
        $target = $this->target();
        $asset = $this->existingAsset('cat.jpg');

        $changed = $this->applyUrl($target, $asset, 'https://example.test/pics/cat.jpg');

        $this->assertFalse($changed);
        $this->assertNull($asset->tempFilePath);
        $this->assertSame(0, $target->downloads);
    }

    public function testReplaceRefetchesTheSameFilename(): void
    {
        $target = $this->target();
        $asset = $this->existingAsset('cat.jpg');

        $changed = $this->applyUrl($target, $asset, 'https://example.test/pics/cat.jpg', ['conflict' => 'replace']);

        $this->assertTrue($changed);
        $this->assertSame(1, $target->downloads);
        // The element — and every relation to it — survives; only the bytes change.
        $this->assertSame('cat.jpg', $asset->getFilename());
        $this->assertSame(Asset::SCENARIO_REPLACE, $asset->getScenario());
    }

    public function testADryRunDownloadsNothing(): void
    {
        $target = $this->target();
        $asset = $this->asset('images', 'abc');

        $changed = $this->applyUrl($target, $asset, 'https://example.test/pics/cat.jpg', [], dryRun: true);

        $this->assertFalse($changed);
        $this->assertSame(0, $target->downloads);
    }

    public function testAnEmptyUrlIsANoOpRatherThanAClear(): void
    {
        // There is no such thing as an asset with its file removed, so the feed's
        // silence can't be authoritative here the way it is for a title.
        $target = $this->target();
        $asset = $this->existingAsset('cat.jpg');

        $this->assertFalse($this->applyUrl($target, $asset, null));
        $this->assertSame(0, $target->downloads);
        $this->assertSame('cat.jpg', $asset->getFilename());
    }

    public function testRenamingGoesThroughNewFilenameAndSkipsAnUnchangedName(): void
    {
        $target = $this->target();
        $asset = $this->existingAsset('cat.jpg');

        $changed = $target->applyNativeAttribute(
            $this->context(),
            $asset,
            'filename',
            new RemoteItem(['name' => 'kitten.jpg']),
            FieldMapping::fromConfig('filename', ['node' => 'name']),
        );

        $this->assertTrue($changed);
        // `newFilename`, not `filename` — that's what makes Craft move the file
        // rather than leave the row pointing at a name that isn't there.
        $this->assertSame('kitten.jpg', $asset->newFilename);

        $unchanged = $target->applyNativeAttribute(
            $this->context(),
            $this->existingAsset('cat.jpg'),
            'filename',
            new RemoteItem(['name' => 'cat.jpg']),
            FieldMapping::fromConfig('filename', ['node' => 'name']),
        );

        $this->assertFalse($unchanged);
    }

    // -- fixtures -------------------------------------------------------------

    protected function applyUrl(AssetTarget $target, Asset $asset, ?string $url, array $options = [], bool $dryRun = false): bool
    {
        return $target->applyNativeAttribute(
            $this->context($dryRun),
            $asset,
            'url',
            new RemoteItem(['file' => $url]),
            FieldMapping::fromConfig('url', ['node' => 'file', 'options' => $options]),
        );
    }

    protected function link(array $criteria): Link
    {
        return FakeLink::make([
            'elementType'     => Asset::class,
            'elementCriteria' => $criteria,
            'match'           => ['attribute' => 'importId'],
        ]);
    }

    protected function context(bool $dryRun = false): SyncContext
    {
        return new SyncContext(
            link: $this->link(['volume' => 'images']),
            target: new AssetTarget(),
            dryRun: $dryRun,
        );
    }

    /**
     * An asset in the given volume. Volumes are identified by a stable id derived
     * from the handle, so the target's id comparison lines up with what
     * {@see targetWithVolumes()} hands back for the same handle.
     */
    protected function asset(string $volume, mixed $match): Asset
    {
        $asset = new class() extends Asset {
            public mixed $importId = null;

            public function __construct()
            {
                // Skip Asset::init()'s Craft dependencies.
            }
        };

        $asset->setVolumeId(crc32($volume));
        $asset->importId = $match;

        return $asset;
    }

    /**
     * A target whose volume lookup answers for the given handles.
     *
     * @param list<string> $handles
     */
    protected function targetWithVolumes(array $handles): AssetTarget
    {
        $target = new class() extends AssetTarget {
            /** @var list<string> */
            public array $handles = [];

            protected function volumeByHandle(string $handle): ?Volume
            {
                if (! in_array($handle, $this->handles, true)) {
                    return null;
                }

                return new class($handle) extends Volume {
                    public function __construct(string $handle)
                    {
                        $this->handle = $handle;
                        $this->name = ucfirst($handle);
                        $this->id = crc32($handle);
                    }
                };
            }
        };
        $target->handles = $handles;

        return $target;
    }

    /** A saved asset that already has a file. */
    protected function existingAsset(string $filename): Asset
    {
        $asset = $this->asset('images', 'abc');
        $asset->id = 12;
        $asset->setFilename($filename);

        return $asset;
    }

    /**
     * A target whose downloads are counted rather than performed, over a stub
     * service that answers with the same filename the real one would choose.
     */
    protected function target(): AssetTarget
    {
        return new class() extends AssetTarget {
            public int $downloads = 0;

            protected function uploads(): AssetUploadService
            {
                $target = $this;

                return new class($target) extends AssetUploadService {
                    protected object $owner;

                    public function __construct(object $owner)
                    {
                        // Skip AssetUploadService::init()'s Guzzle client.
                        $this->owner = $owner;
                    }

                    public function downloadToTemp(string $url): string
                    {
                        $this->owner->downloads++;

                        return '/tmp/influx-' . $this->filenameFor($url);
                    }

                    /**
                     * The real one runs Craft's own name sanitiser, which needs a
                     * booted app; the basename is the part this spec is about.
                     */
                    public function filenameFor(string $url): string
                    {
                        return basename(parse_url($url, PHP_URL_PATH) ?: '');
                    }
                };
            }
        };
    }
}
