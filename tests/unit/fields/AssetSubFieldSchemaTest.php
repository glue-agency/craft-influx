<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fieldlayoutelements\assets\AltField;
use craft\fieldlayoutelements\assets\AssetTitleField;
use craft\fieldlayoutelements\BaseField;
use craft\fields\Assets as CraftAssetsField;
use craft\fields\BaseRelationField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use GlueAgency\Influx\fields\Assets;

/**
 * Behaviour spec for the sub-field card an Assets mapping row offers.
 *
 * ONE card over two write channels: the asset's own attributes (alt / title) on
 * `nativeFields`, the custom fields of the volumes the field may relate on
 * `fields`, marked row by row with the `channel` key. Alt and Title are
 * optional native layout elements in Craft, so each is offered only where a
 * volume's layout includes it — a row nobody can fill in the asset's own editor
 * doesn't belong in the mapping either.
 *
 * The volume walk itself needs a booted Craft (it decodes `volume:UID` sources
 * against the Volumes service), so the layout seam is stubbed here; the runtime
 * scoping that shares the decoding is specced in {@see AssetUrlMatchTest}.
 */
class AssetSubFieldSchemaTest extends Unit
{
    public function testTheAssetsOwnFieldsAreOneCard(): void
    {
        $nodes = $this->schema([$this->fakeLayout(['caption', 'photographer'], ['alt', 'title'])]);

        $cards = $this->cards($nodes);
        $this->assertCount(1, $cards, 'Two cards were the write channels showing through — the editor gets one list.');

        $card = $cards[0];
        $this->assertSame('elementSubFields', $card['type']);
        $this->assertSame('nativeFields', $card['handle']);
        $this->assertSame('Sub-fields', $card['label']);

        // Natives lead, then the volume's custom fields.
        $this->assertSame(['alt', 'title', 'caption', 'photographer'], array_column($card['subFields'], 'handle'));
        $this->assertSame(['text', 'text', 'text', 'text'], array_column($card['subFields'], 'type'));
    }

    public function testOnlyTheCustomRowsCarryAChannel(): void
    {
        // An unmarked row means `nativeFields` — both the shape these rows were
        // stored in before the key existed and the safe default, since a native
        // routed to `fields` would be dropped silently at apply time.
        $card = $this->cards($this->schema([$this->fakeLayout(['caption'], ['alt'])]))[0];

        $channels = array_map(static fn(array $row): ?string => $row['channel'] ?? null, $card['subFields']);
        $this->assertSame([null, 'fields'], $channels);
    }

    public function testAnAttributeIsOfferedOnlyWhereALayoutIncludesIt(): void
    {
        $altOnly = $this->cards($this->schema([$this->fakeLayout([], ['alt'])]))[0];
        $this->assertSame(['alt'], array_column($altOnly['subFields'], 'handle'));

        $titleOnly = $this->cards($this->schema([$this->fakeLayout([], ['title'])]))[0];
        $this->assertSame(['title'], array_column($titleOnly['subFields'], 'handle'));

        // A layout that includes neither offers neither, however writable the
        // attributes remain on the element itself.
        $customOnly = $this->cards($this->schema([$this->fakeLayout(['caption'], [])]))[0];
        $this->assertSame(['caption'], array_column($customOnly['subFields'], 'handle'));
    }

    public function testAnAttributeAnyVolumeIncludesIsOffered(): void
    {
        // The union across the field's volumes is what a mapping may address:
        // the row applies to the assets that have it and is inert for the rest.
        $card = $this->cards($this->schema([
            $this->fakeLayout([], []),
            $this->fakeLayout([], ['alt']),
        ]))[0];

        $this->assertSame(['alt'], array_column($card['subFields'], 'handle'));
    }

    public function testTheRowIsLabelledByTheLayoutElement(): void
    {
        $card = $this->cards($this->schema([$this->fakeLayout([], ['alt' => 'Alt tekst', 'title'])]))[0];

        $this->assertSame(['Alt tekst', 'Title'], array_column($card['subFields'], 'label'));
    }

    public function testTheRowsAreEmittedInAFixedOrder(): void
    {
        // Whichever volume contributes which attribute, the rows read the same —
        // layout order would reshuffle them per field.
        $card = $this->cards($this->schema([
            $this->fakeLayout([], ['title']),
            $this->fakeLayout([], ['alt']),
        ]))[0];

        $this->assertSame(['alt', 'title'], array_column($card['subFields'], 'handle'));
    }

    public function testAHandleSharedByTwoVolumesIsOfferedOnce(): void
    {
        // The field relates two volumes whose layouts both carry `caption`; the
        // union is what the mapping may address, and the first layout names it.
        $card = $this->cards($this->schema([
            $this->fakeLayout(['caption'], [], 'Caption'),
            $this->fakeLayout(['caption', 'credit'], [], 'Byline'),
        ]))[0];

        $this->assertSame(['caption', 'credit'], array_column($card['subFields'], 'handle'));
        $this->assertSame('Caption', $card['subFields'][0]['label']);
    }

    public function testAVolumeFieldNamedLikeAnAttributeYieldsOneRow(): void
    {
        // `alt` isn't a reserved field handle, so a volume field may use it. One
        // handle-keyed table can't hold both rows: the native wins where the
        // layout includes it, and the custom row stands where it doesn't.
        $native = $this->cards($this->schema([$this->fakeLayout(['alt'], ['alt'])]))[0];
        $this->assertSame(['alt'], array_column($native['subFields'], 'handle'));
        $this->assertArrayNotHasKey('channel', $native['subFields'][0]);

        $custom = $this->cards($this->schema([$this->fakeLayout(['alt'], [])]))[0];
        $this->assertSame(['alt'], array_column($custom['subFields'], 'handle'));
        $this->assertSame('fields', $custom['subFields'][0]['channel']);
    }

    public function testNothingToOfferMeansNoCard(): void
    {
        foreach ([[], [$this->fakeLayout([], [])]] as $layouts) {
            $this->assertSame([], $this->cards($this->schema($layouts)));
        }
    }

    /**
     * The sub-field cards among a schema's nodes — either card type, so a spec
     * fails on a stray second card rather than silently reading past it.
     *
     * @param list<array> $nodes
     * @return list<array>
     */
    protected function cards(array $nodes): array
    {
        return array_values(array_filter(
            $nodes,
            static fn(array $node): bool => in_array($node['type'], ['elementSubFields', 'subFields'], true),
        ));
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

            /** No booted plugin to ask: every volume field's row reads as text. */
            protected function childRowFor(CraftFieldInterface $craftField): array
            {
                return ['default' => ['type' => 'text'], 'extra' => []];
            }
        };

        return $strategy->schema($this->createMock(CraftAssetsField::class))->toArray()['extra'] ?? [];
    }

    /**
     * A volume's fake field layout: one custom field per handle, plus the native
     * layout elements it includes.
     *
     * @param list<string> $handles Custom-field handles.
     * @param array<int|string, string> $natives Included attributes, either as a
     * bare value (`'alt'`) or keyed by the label the layout element reports
     * (`'alt' => 'Alt tekst'`).
     * @param ?string $name Name for every custom field, when the spec cares
     * which layout named a shared handle.
     */
    protected function fakeLayout(array $handles, array $natives, ?string $name = null): FieldLayout
    {
        $labels = [];

        foreach ($natives as $key => $value) {
            $labels[is_int($key) ? $value : $key] = is_int($key) ? '' : $value;
        }

        $customFields = array_map(
            fn(string $handle): CraftFieldInterface => $this->fakeCustomField($handle, $name ?? ucfirst($handle)),
            $handles,
        );

        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getCustomFields')->willReturn($customFields);
        $layout->method('isFieldIncluded')->willReturnCallback(
            static fn(string $attribute): bool => array_key_exists($attribute, $labels),
        );
        $layout->method('getField')->willReturnCallback(
            fn(string $attribute): BaseField => $this->fakeNativeField($attribute, $labels[$attribute] ?? ''),
        );

        return $layout;
    }

    /**
     * A custom field the schema reads a handle and a name off. A real field mock
     * rather than a carrier object, so it also satisfies the typed seam the
     * default-editor lookup goes through.
     */
    protected function fakeCustomField(string $handle, string $name): CraftFieldInterface
    {
        $field = $this->createMock(PlainText::class);
        $field->handle = $handle;
        $field->name = $name;

        return $field;
    }

    /**
     * The native layout element behind an included attribute. Its real class
     * matters for nothing but the return type; the schema only asks for a label.
     */
    protected function fakeNativeField(string $attribute, string $label): BaseField
    {
        $field = $this->createMock($attribute === 'alt' ? AltField::class : AssetTitleField::class);
        $field->method('label')->willReturn($label);

        return $field;
    }
}
