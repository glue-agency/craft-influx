<?php

namespace TDM\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use TDM\Influx\fields\Lightswitch;
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
        $strategy = $this->build(['featured' => true]);
        $this->assertTrue($strategy->parseField());

        $strategy = $this->build(['featured' => false]);
        $this->assertFalse($strategy->parseField());
    }

    public function testStringTruthyValuesAreCoerced(): void
    {
        foreach (['true', '1', 'yes', 'on', 'TRUE', 'Yes'] as $truthy) {
            $strategy = $this->build(['featured' => $truthy]);
            $this->assertTrue(
                $strategy->parseField(),
                "Expected '$truthy' to coerce to true under default truthy list.",
            );
        }
    }

    public function testStringFalseyValuesAreCoerced(): void
    {
        foreach (['no', 'false', '0', '', 'maybe', null] as $falsey) {
            $strategy = $this->build(['featured' => $falsey]);
            $this->assertFalse(
                $strategy->parseField(),
                'Expected ' . var_export($falsey, true) . ' to coerce to false.',
            );
        }
    }

    public function testCustomTruthyListOverridesDefaults(): void
    {
        // With a custom list, the defaults no longer apply: 'yes' is no longer truthy.
        $strategy = $this->build(
            feed: ['featured' => 'yes'],
            mapping: ['node' => 'featured', 'options' => ['truthy' => ['ja']]],
        );
        $this->assertFalse($strategy->parseField());

        $strategy = $this->build(
            feed: ['featured' => 'ja'],
            mapping: ['node' => 'featured', 'options' => ['truthy' => ['ja']]],
        );
        $this->assertTrue($strategy->parseField());
    }

    public function testFallsBackToDefaultWhenNodeMissing(): void
    {
        $strategy = $this->build(
            feed: [],
            mapping: ['node' => 'featured', 'default' => 'true'],
        );
        $this->assertTrue($strategy->parseField());
    }

    public function testCraftFieldClassIsLightswitch(): void
    {
        $this->assertSame(\craft\fields\Lightswitch::class, Lightswitch::craftFieldClass());
    }

    private function build(array $feed, array $mapping = ['node' => 'featured']): Lightswitch
    {
        $strategy = new Lightswitch();
        $strategy->setContext(
            craftField: null,
            fieldHandle: 'featured',
            fieldInfo: $mapping,
            item: $feed,
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
        return $strategy;
    }
}
