<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\data\IconData;
use craft\fields\Icon as CraftIconField;
use GlueAgency\Influx\fields\Icon;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Icon strategy: Craft stores a bare Font Awesome name,
 * feeds ship the prefixed class name, and the two must be one value.
 */
class IconFieldTest extends Unit
{
    public function testCraftFieldClassIsIcon(): void
    {
        $this->assertSame(CraftIconField::class, Icon::craftFieldClass());
    }

    /**
     * @dataProvider spellings
     */
    public function testStripsThePrefixCraftDoesNotStore(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, (new Icon())->parse($this->context(['glyph' => $raw])));
    }

    public static function spellings(): array
    {
        return [
            'bare name'   => ['user', 'user'],
            'prefixed'    => ['fa-user', 'user'],
            'padded'      => ['  fa-user  ', 'user'],
            'hyphenated'  => ['fa-user-group', 'user-group'],
            'prefix only' => ['fa-', null],
        ];
    }

    /**
     * The icon set runs to thousands of names and depends on whether Pro icons
     * are enabled, so an unknown name is passed through rather than rejected.
     */
    public function testAnUnknownNameIsNotAnError(): void
    {
        $this->assertSame('not-a-real-icon', (new Icon())->parse($this->context(['glyph' => 'not-a-real-icon'])));
    }

    /**
     * The default cell mounts Craft's own picker rather than any list of its own:
     * the icon set is thousands of entries with their own search terms and Pro
     * gating, all of which Craft already searches server-side. Nothing about the
     * field's settings rides the node — the server derives Pro gating from the
     * field, the way it derives an element picker's sources.
     */
    public function testTheDefaultMountsCraftsOwnIconPicker(): void
    {
        $this->assertSame(
            [['type' => SchemaBuilder::ICON]],
            (new Icon())->schema($this->createMock(CraftIconField::class))->toArray()['default'],
        );
    }

    public function testAbsentValueYieldsNull(): void
    {
        $this->assertNull((new Icon())->parse($this->context([])));
    }

    public function testUnchangedFeedIsNotAChangeAcrossThePrefix(): void
    {
        $strategy = new Icon();
        $incoming = $strategy->parse($this->context(['glyph' => 'fa-user']));

        $this->assertFalse(
            $strategy->hasChanged($this->context([], new IconData('user', [])), $incoming),
            "A stored 'user' and the feed's 'fa-user' are one icon.",
        );

        $this->assertFalse($strategy->hasChanged($this->context([], 'user'), $incoming));
    }

    public function testADifferentIconIsAChange(): void
    {
        $strategy = new Icon();
        $incoming = $strategy->parse($this->context(['glyph' => 'fa-house']));

        $this->assertTrue($strategy->hasChanged($this->context([], new IconData('user', [])), $incoming));
    }

    private function context(array $feed, mixed $current = null): FieldContext
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);

        return new FieldContext(
            craftField: null,
            handle: 'glyph',
            mapping: FieldMapping::fromConfig('glyph', ['node' => 'glyph']),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $element,
        );
    }
}
