<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Asset;
use GlueAgency\Influx\fields\Assets;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * A default picked for a multi-relation Assets field is a LIST of asset ids —
 * the CP picker allows as many as the field's `maxRelations`
 * ({@see \GlueAgency\Influx\services\LinkBuilderService::elementSelectConfigFor()}).
 *
 * The same rule as a single pick has to hold for each entry of that list: the id
 * branch is forced whatever `options.mode` says, because a picked default is an
 * id, and under `url` mode it would otherwise be basename-matched into nothing
 * ({@see Assets::parse()}).
 */
class AssetDefaultListTest extends Unit
{
    public function testEveryPickedIdTakesTheIdBranchUnderUrlMode(): void
    {
        $strategy = $this->strategy([12 => $this->asset(12), 34 => $this->asset(34)]);
        $context = $this->context([
            'useDefault' => true,
            'default'    => ['12', '34'],
            'options'    => ['mode' => 'url'],
        ]);

        $this->assertSame([12, 34], $strategy->parse($context));
        $this->assertSame(['12', '34'], $strategy->byId);
        $this->assertSame([], $strategy->byUrl);
    }

    public function testAPickThatNoLongerResolvesDropsOutOfTheList(): void
    {
        $strategy = $this->strategy([34 => $this->asset(34)]);
        $context = $this->context([
            'useDefault' => true,
            'default'    => ['12', '34'],
        ]);

        $this->assertSame([34], $strategy->parse($context));
    }

    /**
     * An Assets strategy with both resolution branches stubbed out, recording
     * which one each reference took — the no-boot seam the URL-match spec uses
     * ({@see AssetUrlMatchTest}), one level up.
     *
     * @param array<int, Asset> $assetsById
     */
    protected function strategy(array $assetsById): Assets
    {
        $strategy = new class() extends Assets {
            /** @var array<int, Asset> */
            public array $assetsById = [];

            /** @var list<string> */
            public array $byId = [];

            /** @var list<string> */
            public array $byUrl = [];

            protected function findById(FieldContext $context, mixed $raw): ?Asset
            {
                $this->byId[] = (string) $raw;

                return $this->assetsById[(int) $raw] ?? null;
            }

            protected function resolveByUrl(FieldContext $context, string $url): ?ElementInterface
            {
                $this->byUrl[] = $url;

                return null;
            }
        };
        $strategy->assetsById = $assetsById;

        return $strategy;
    }

    protected function asset(int $id): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->id = $id;

        return $asset;
    }

    protected function context(array $mapping): FieldContext
    {
        return new FieldContext(
            craftField: null,
            handle: 'images',
            mapping: FieldMapping::fromConfig('images', $mapping),
            item: new RemoteItem([]),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }
}
