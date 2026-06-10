<?php

namespace TDM\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use TDM\Influx\fields\Lightswitch;
use TDM\Influx\models\FieldMapping;
use TDM\Influx\sync\FieldContext;
use TDM\Influx\sync\RemoteItem;
use TDM\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Lightswitch field strategy.
 *
 *   options.truthy: list of values that should coerce to TRUE.
 *                   Default: ['true', '1', 'yes', 'on'].
 *
 * Anything else (null, empty string, "no", numbers other than 1, ...) is FALSE.
 */
class LightswitchTest extends Unit
{
    public function testBooleanFeedValuePassesThrough(): void
    {
        $this->assertTrue((new Lightswitch())->parse($this->context(['featured' => true])));
        $this->assertFalse((new Lightswitch())->parse($this->context(['featured' => false])));
    }

    public function testStringTruthyValuesAreCoerced(): void
    {
        foreach (['true', '1', 'yes', 'on', 'TRUE', 'Yes'] as $truthy) {
            $this->assertTrue(
                (new Lightswitch())->parse($this->context(['featured' => $truthy])),
                "Expected '$truthy' to coerce to true under default truthy list.",
            );
        }
    }

    public function testStringFalseyValuesAreCoerced(): void
    {
        foreach (['no', 'false', '0', '', 'maybe', null] as $falsey) {
            $this->assertFalse(
                (new Lightswitch())->parse($this->context(['featured' => $falsey])),
                'Expected ' . var_export($falsey, true) . ' to coerce to false.',
            );
        }
    }

    public function testCustomTruthyListOverridesDefaults(): void
    {
        // With a custom list, the defaults no longer apply: 'yes' is no longer truthy.
        $context = $this->context(
            feed: ['featured' => 'yes'],
            mapping: ['node' => 'featured', 'options' => ['truthy' => ['ja']]],
        );
        $this->assertFalse((new Lightswitch())->parse($context));

        $context = $this->context(
            feed: ['featured' => 'ja'],
            mapping: ['node' => 'featured', 'options' => ['truthy' => ['ja']]],
        );
        $this->assertTrue((new Lightswitch())->parse($context));
    }

    public function testFallsBackToDefaultWhenNodeMissing(): void
    {
        $context = $this->context(
            feed: [],
            mapping: ['node' => 'featured', 'default' => 'true'],
        );
        $this->assertTrue((new Lightswitch())->parse($context));
    }

    public function testCraftFieldClassIsLightswitch(): void
    {
        $this->assertSame(\craft\fields\Lightswitch::class, Lightswitch::craftFieldClass());
    }

    private function context(array $feed, array $mapping = ['node' => 'featured']): FieldContext
    {
        return new FieldContext(
            craftField: null,
            handle: 'featured',
            mapping: FieldMapping::fromConfig('featured', $mapping),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }
}
