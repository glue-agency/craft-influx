<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\models\FieldLayout;
use GlueAgency\Influx\fields\Assets;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\item\MappingApplier;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\AssetTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * An asset's focal point, through both paths that write it: an Assets field's
 * sub-field card and an asset link's own row. The asset is Craft's real one, so
 * the setter and getter in play are Craft's.
 *
 * The value rules are every other field's: an absent node leaves the point
 * alone, an empty one clears it (to the row's default when it has one), and a
 * value that isn't an `x;y` pair inside 0–1 is refused on its row without
 * touching the stored point.
 */
class FocalPointWriteTest extends Unit
{
    protected const STORED = '0.4521;0.3010';

    /** @return iterable<string, array{0: string}> */
    public static function paths(): iterable
    {
        yield 'an Assets field sub-field' => ['subField'];
        yield 'an asset link row' => ['link'];
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function invalidValues(): iterable
    {
        $values = [
            'no separator'      => 'centre',
            'one coordinate'    => '0.5',
            'three coordinates' => '0.5;0.3;0.1',
            'words'             => 'left;top',
            'percentages'       => '50%;30%',
            'out of range'      => '0.5;1.2',
            'negative'          => '-0.1;0.5',
            'a list of points'  => ['0.1;0.2', '0.3;0.4'],
        ];

        foreach (self::paths() as $pathLabel => [$path]) {
            foreach ($values as $label => $value) {
                yield "{$label} on {$pathLabel}" => [$path, $value];
            }
        }
    }

    /** @dataProvider paths */
    public function testAValueIsWritten(string $path): void
    {
        $asset = $this->image();

        $row = $this->write($path, $asset, ['focal' => self::STORED]);

        $this->assertTrue($row->changed);
        $this->assertSame(['x' => 0.4521, 'y' => 0.301], $asset->getFocalPoint());
    }

    /** @dataProvider paths */
    public function testRewritingTheSameValueIsNoChange(string $path): void
    {
        $asset = $this->image(self::STORED);

        $row = $this->write($path, $asset, ['focal' => self::STORED]);

        $this->assertFalse($row->changed);
    }

    /** @dataProvider paths */
    public function testAFinerValueIsRoundedToWhatCraftStores(string $path): void
    {
        // Craft saves four decimals, so an unrounded value would differ from the
        // stored one on every run and re-save the asset each time.
        $asset = $this->image(self::STORED);

        $row = $this->write($path, $asset, ['focal' => '0.45213;0.30104']);

        $this->assertFalse($row->changed);
    }

    /** @dataProvider paths */
    public function testAMissingNodeLeavesThePointAlone(string $path): void
    {
        $asset = $this->image(self::STORED);

        $row = $this->write($path, $asset, ['other' => 'x']);

        $this->assertTrue($row->unaddressed);
        $this->assertSame(self::STORED, $this->stored($asset));
    }

    /** @dataProvider paths */
    public function testAnEmptyStringClearsThePoint(string $path): void
    {
        $asset = $this->image(self::STORED);

        $row = $this->write($path, $asset, ['focal' => '']);

        $this->assertTrue($row->changed);
        $this->assertFalse($asset->getHasFocalPoint());
    }

    /** @dataProvider paths */
    public function testAnEmptyValueFallsBackToTheRowsDefault(string $path): void
    {
        $asset = $this->image(self::STORED);

        $this->write($path, $asset, ['focal' => ''], ['node' => 'focal', 'default' => '0.2500;0.7500']);

        $this->assertSame('0.2500;0.7500', $this->stored($asset));
    }

    /** @dataProvider invalidValues */
    public function testAnInvalidValueIsRefusedWithoutClearingThePoint(string $path, mixed $value): void
    {
        $asset = $this->image(self::STORED);

        $row = $this->write($path, $asset, ['focal' => $value]);

        $this->assertNotTrue($row->changed);
        $this->assertNotNull($row->error, 'The row must say why nothing was written.');
        $this->assertSame(self::STORED, $this->stored($asset));
    }

    /**
     * One focal-point row applied through the real applier, on either path.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $config
     */
    protected function write(string $path, Asset $asset, array $item, array $config = ['node' => 'focal']): MappingResult
    {
        $applier = new class() extends MappingApplier {
            public function exposedApplyNativeSubField(ElementInterface $element, RemoteItem $item, FieldMapping $sub): ?MappingResult
            {
                return $this->applyNativeSubField($element, $item, $sub, new Assets());
            }
        };

        if ($path === 'subField') {
            return $applier->exposedApplyNativeSubField($asset, new RemoteItem($item), FieldMapping::fromConfig('focalPoint', $config));
        }

        $link = FakeLink::make([
            'elementType'     => Asset::class,
            'elementCriteria' => ['volume' => 'images'],
            'mappings'        => ['focalPoint' => $config],
        ]);

        return $applier->apply(new SyncContext(link: $link, target: new AssetTarget()), $asset, new RemoteItem($item))[0];
    }

    /** The stored point in Craft's own column form, or null when none is set. */
    protected function stored(Asset $asset): ?string
    {
        if (! $asset->getHasFocalPoint()) {
            return null;
        }

        $point = $asset->getFocalPoint();

        return number_format($point['x'], 4) . ';' . number_format($point['y'], 4);
    }

    /**
     * A real Asset that a bootless suite can construct and read: the overrides
     * stand in for the app, the field layout and the custom-field behaviour.
     */
    protected function image(?string $focalPoint = null): Asset
    {
        $asset = new class() extends Asset {
            public function init(): void
            {
            }

            public function behaviors(): array
            {
                return [];
            }

            public function getFieldLayout(): ?FieldLayout
            {
                return null;
            }

            protected function fieldByHandle(string $handle): ?FieldInterface
            {
                return null;
            }
        };

        $asset->kind = Asset::KIND_IMAGE;
        $asset->setFocalPoint($focalPoint);

        return $asset;
    }
}
