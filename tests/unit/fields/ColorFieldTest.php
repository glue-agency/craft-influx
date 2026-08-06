<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\Color as CraftColorField;
use craft\fields\data\ColorData;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Color;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Color strategy: every spelling of one colour is one
 * value, so a feed shipping `#E53935` against a stored `#e53935` is not a change.
 */
class ColorFieldTest extends Unit
{
    public function testCraftFieldClassIsColor(): void
    {
        $this->assertSame(CraftColorField::class, Color::craftFieldClass());
    }

    /**
     * @dataProvider spellings
     */
    public function testNormalisesEverySpellingToCanonicalHex(string $raw, string $expected): void
    {
        $this->assertSame($expected, (new Color())->parse($this->context(['tint' => $raw])));
    }

    public static function spellings(): array
    {
        return [
            'already canonical' => ['#e53935', '#e53935'],
            'uppercase'         => ['#E53935', '#e53935'],
            'no hash'           => ['e53935', '#e53935'],
            'shorthand'         => ['#abc', '#aabbcc'],
            'shorthand no hash' => ['abc', '#aabbcc'],
            'padded'            => ['  #E53935  ', '#e53935'],
            'transparent'       => ['transparent', 'transparent'],
        ];
    }

    public function testANonColourThrowsRatherThanReachingTheField(): void
    {
        // Craft's normaliser only reshapes — it would turn 'red' into '#red'.
        $this->expectException(MappingValueException::class);

        (new Color())->parse($this->context(['tint' => 'red']));
    }

    public function testAbsentValueYieldsNull(): void
    {
        $this->assertNull((new Color())->parse($this->context([])));
    }

    public function testUnchangedFeedIsNotAChangeWhateverTheSpelling(): void
    {
        $strategy = new Color();
        $incoming = $strategy->parse($this->context(['tint' => '#E53935']));

        $this->assertFalse(
            $strategy->hasChanged($this->context([], new ColorData('#e53935')), $incoming),
            'A stored ColorData and the same colour spelled in uppercase are one value.',
        );

        $this->assertFalse(
            $strategy->hasChanged($this->context([], '#e53935'), $incoming),
            'The hex string a nested fingerprint carries is the same value too.',
        );
    }

    /**
     * A palette is a closed set, so the "use default" cell offers it rather than
     * asking the operator to type a hex. Values are the canonical form parse()
     * produces, so a picked default and a fed colour compare equal.
     */
    public function testTheDefaultIsPickedFromTheFieldsOwnPalette(): void
    {
        $field = $this->createMock(CraftColorField::class);
        $field->palette = [
            ['color' => '#E53935', 'label' => 'Red', 'default' => true],
            ['color' => '#43a047', 'label' => 'Green'],
            ['color' => '#1e88e5', 'label' => ''],
        ];

        $cell = (new Color())->schema($field)->toArray()['default'][0];

        $this->assertSame(SchemaBuilder::SELECT, $cell['type']);
        // The palette and nothing else: "— no default —" is the preset's
        // sentinel, carried beside the options rather than inside them.
        $this->assertSame([
            ['value' => '#e53935', 'label' => 'Red'],
            ['value' => '#43a047', 'label' => 'Green'],
            // No label on the palette entry, so it labels itself.
            ['value' => '#1e88e5', 'label' => '#1e88e5'],
        ], $cell['options']);
    }

    public function testAFieldWithNoPaletteKeepsAPlainTextDefault(): void
    {
        $field = $this->createMock(CraftColorField::class);
        $field->palette = [];

        $this->assertSame(
            [['type' => SchemaBuilder::TEXT]],
            (new Color())->schema($field)->toArray()['default'],
            'Nothing to pick from, and any hex is valid — a text box is right.',
        );
    }

    public function testADifferentColourIsAChange(): void
    {
        $strategy = new Color();
        $incoming = $strategy->parse($this->context(['tint' => '#43a047']));

        $this->assertTrue($strategy->hasChanged($this->context([], new ColorData('#e53935')), $incoming));
    }

    private function context(array $feed, mixed $current = null): FieldContext
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);

        return new FieldContext(
            craftField: null,
            handle: 'tint',
            mapping: FieldMapping::fromConfig('tint', ['node' => 'tint']),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $element,
        );
    }
}
