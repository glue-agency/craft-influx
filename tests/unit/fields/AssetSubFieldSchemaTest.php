<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\fields\Assets as CraftAssetsField;
use craft\fields\BaseRelationField;
use craft\models\FieldLayout;
use GlueAgency\Influx\fields\Assets;

/**
 * Behaviour spec for the sub-field cards an Assets mapping row offers.
 *
 * The row splits them in two because they're written through two different
 * channels: "Sub-fields" carries the asset element's own attributes (alt /
 * title) on the `nativeFields` channel, "Asset fields" carries the custom
 * fields of the volumes the field may relate on the `fields` channel — which
 * the runtime already applied through the asset's field layout long before the
 * schema offered them.
 *
 * The volume walk itself needs a booted Craft (it decodes `volume:UID` sources
 * against the Volumes service), so the layout seam is stubbed here; the runtime
 * scoping that shares the decoding is specced in {@see AssetUrlMatchTest}.
 */
class AssetSubFieldSchemaTest extends Unit
{
    public function testVolumeCustomFieldsGetTheirOwnCard(): void
    {
        $nodes = $this->schema([$this->fakeLayout(['caption', 'photographer'])]);

        $card = end($nodes);
        $this->assertSame('subFields', $card['type']);
        $this->assertSame('fields', $card['handle'], 'The card writes the mapping’s flat custom-field channel.');
        $this->assertSame('Asset fields', $card['label']);

        $this->assertSame(['caption', 'photographer'], array_column($card['subFields'], 'handle'));
        $this->assertSame(['Caption', 'Photographer'], array_column($card['subFields'], 'label'));
        $this->assertSame(['text', 'text'], array_column($card['subFields'], 'type'));
    }

    public function testAHandleSharedByTwoVolumesIsOfferedOnce(): void
    {
        // The field relates two volumes whose layouts both carry `caption`; the
        // union is what the mapping may address, and the first layout names it.
        $nodes = $this->schema([
            $this->fakeLayout(['caption'], 'Caption'),
            $this->fakeLayout(['caption', 'credit'], 'Byline'),
        ]);

        $card = end($nodes);
        $this->assertSame(['caption', 'credit'], array_column($card['subFields'], 'handle'));
        $this->assertSame('Caption', $card['subFields'][0]['label']);
    }

    public function testNoCustomFieldsAnywhereMeansNoCard(): void
    {
        foreach ([[], [$this->fakeLayout([])]] as $layouts) {
            $nodes = $this->schema($layouts);

            $this->assertSame([], array_filter($nodes, static fn(array $node): bool => $node['type'] === 'subFields'));
        }
    }

    public function testTheNativeAltAndTitleCardComesFirstAndIsUntouched(): void
    {
        // alt and title are element attributes, writable on any asset whatever
        // its volume's layout includes — so the custom fields are appended
        // beside them, never merged into them.
        $nodes = $this->schema([$this->fakeLayout(['caption'])]);

        $cards = array_values(array_filter(
            $nodes,
            static fn(array $node): bool => in_array($node['type'], ['elementSubFields', 'subFields'], true),
        ));

        $this->assertSame(['elementSubFields', 'subFields'], array_column($cards, 'type'));
        $this->assertSame('nativeFields', $cards[0]['handle']);
        $this->assertSame('Sub-fields', $cards[0]['label']);
        $this->assertSame(['alt', 'title'], array_column($cards[0]['subFields'], 'handle'));
        $this->assertSame(['Alt text', 'Title'], array_column($cards[0]['subFields'], 'label'));
    }

    public function testAVolumeFieldNamedLikeAnAttributeIsStillOffered(): void
    {
        // A volume field really handled `alt` is a different thing from the
        // asset's own alt text and is written through a different channel, so
        // both rows stand; each shows its handle beside its label.
        $nodes = $this->schema([$this->fakeLayout(['alt'])]);

        $card = end($nodes);
        $this->assertSame(['alt'], array_column($card['subFields'], 'handle'));
    }

    /**
     * The schema nodes an Assets row is built from, with the volume-layout walk
     * stubbed out.
     *
     * @param list<FieldLayout> $layouts
     * @return list<array>
     */
    protected function schema(array $layouts): array
    {
        $strategy = new class($layouts) extends Assets {
            /** @var list<FieldLayout> */
            public array $layouts = [];

            public function __construct(array $layouts)
            {
                $this->layouts = $layouts;
            }

            protected function sourceFieldLayouts(BaseRelationField $field): iterable
            {
                return $this->layouts;
            }
        };

        return $strategy->schema($this->createMock(CraftAssetsField::class))->toArray();
    }

    /**
     * A volume's fake field layout: one custom field per handle. Plain carriers
     * rather than field mocks — the schema reads nothing off a custom field but
     * its handle and name.
     *
     * @param list<string> $handles
     * @param ?string $name Name for every field, when the spec cares which
     * layout named a shared handle.
     */
    protected function fakeLayout(array $handles, ?string $name = null): FieldLayout
    {
        $customFields = array_map(static fn(string $handle): object => new class($handle, $name) {
            public string $handle;

            public string $name;

            public function __construct(string $handle, ?string $name)
            {
                $this->handle = $handle;
                $this->name = $name ?? ucfirst($handle);
            }
        }, $handles);

        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getCustomFields')->willReturn($customFields);

        return $layout;
    }
}
