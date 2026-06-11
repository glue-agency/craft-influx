<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Lightswitch field strategy. Coercion is automatic
 * — booleans pass through, the common truthy spellings ('true', '1', 'yes',
 * 'on', any casing) resolve to TRUE, and anything else (null, empty string,
 * "no", numbers other than 1, ...) is FALSE. No per-mapping configuration.
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

    public function testLegacyTruthyOptionIsIgnored(): void
    {
        // Coercion is automatic now — a leftover options.truthy list from an
        // older config must not change the outcome.
        $context = $this->context(
            feed: ['featured' => 'yes'],
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
