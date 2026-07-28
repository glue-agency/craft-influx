<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\Lightswitch as CraftLightswitchField;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Lightswitch field strategy and the truthy coercion it
 * owns for the whole plugin ({@see Lightswitch::coerce()}, shared with the
 * targets' native `enabled` flag). Coercion is automatic — booleans pass
 * through, the common truthy spellings ('true', '1', 'yes', 'on', any casing)
 * resolve to TRUE, and anything else (null, empty string, "no", numbers other
 * than 1, ...) is FALSE. No per-mapping configuration.
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

    public function testInactiveMappingYieldsNullSoTheFieldIsLeftUntouched(): void
    {
        // No node and no default: the field isn't mapped at all, so parse must
        // return null (the walker then leaves it untouched) rather than forcing
        // it to false on every sync.
        $context = $this->context(feed: [], mapping: []);
        $this->assertNull((new Lightswitch())->parse($context));
    }

    public function testActiveButEmptyMappingStillCoercesToFalse(): void
    {
        // node mapped but the feed value is empty: the feed is authoritative and
        // an empty boolean is false — distinct from "not mapped" above.
        $context = $this->context(feed: ['featured' => ''], mapping: ['node' => 'featured']);
        $this->assertFalse((new Lightswitch())->parse($context));
    }

    public function testCoerceOwnsTheTruthyTable(): void
    {
        foreach ([true, 1, '1', 'true', 'TRUE', 'yes', 'Yes', ' on ', 'ON'] as $truthy) {
            $this->assertTrue(
                Lightswitch::coerce($truthy),
                'Expected ' . var_export($truthy, true) . ' to coerce to true.',
            );
        }

        foreach ([false, null, '', ' ', 0, '0', 2, 'no', 'false', 'off', 'maybe', 'yess'] as $falsey) {
            $this->assertFalse(
                Lightswitch::coerce($falsey),
                'Expected ' . var_export($falsey, true) . ' to coerce to false.',
            );
        }
    }

    public function testCraftFieldClassIsLightswitch(): void
    {
        $this->assertSame(CraftLightswitchField::class, Lightswitch::craftFieldClass());
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
