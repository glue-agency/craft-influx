<?php

namespace TDM\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use TDM\Influx\fields\DefaultField;
use TDM\Influx\fields\Lightswitch;
use TDM\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for Field::hasChanged. The sync engine uses it to skip
 * elements where nothing has actually changed: if every field's hasChanged
 * returns false AND the element isn't new, no saveElement call is made and
 * the item is logged as 'unchanged'.
 *
 * Default semantics (Field base):
 *   - getFieldValue throws -> assume changed (safer default)
 *   - normalise both sides, compare as strings
 *   - null === '' (both round-trip to null)
 *
 * Strategies that handle list-valued fields (Assets, Relation) override
 * hasChanged to compare sorted id arrays — covered by feature tests.
 */
class CompareTest extends Unit
{
    public function testEqualScalarsAreUnchanged(): void
    {
        $strategy = $this->buildDefault();
        $element = $this->elementReturning('hello');
        $this->assertFalse($strategy->hasChanged($element, 'hello'));
    }

    public function testDifferentScalarsAreChanged(): void
    {
        $strategy = $this->buildDefault();
        $element = $this->elementReturning('hello');
        $this->assertTrue($strategy->hasChanged($element, 'world'));
    }

    public function testNullAndEmptyStringAreEquivalent(): void
    {
        $strategy = $this->buildDefault();
        $element = $this->elementReturning('');
        $this->assertFalse($strategy->hasChanged($element, null));

        $element = $this->elementReturning(null);
        $this->assertFalse($strategy->hasChanged($element, ''));
    }

    public function testNumericCoercionFollowsStringNormalisation(): void
    {
        // normalise() converts scalars to strings; the comparison is then
        // string-vs-string so '1' equals 1 (intentional: feeds often hand
        // numbers as strings).
        $strategy = $this->buildDefault();
        $element = $this->elementReturning(1);
        $this->assertFalse($strategy->hasChanged($element, '1'));
    }

    public function testReadFailureTreatedAsChanged(): void
    {
        $strategy = $this->buildDefault();
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willThrowException(new \RuntimeException('boom'));
        $this->assertTrue(
            $strategy->hasChanged($element, 'anything'),
            "Failing to read the current value must default to 'changed' — otherwise we'd silently skip syncs on broken fields.",
        );
    }

    public function testLightswitchReadsTheBooleanSemantically(): void
    {
        // Lightswitch inherits the base hasChanged; both sides normalise to
        // a string. true -> "1", false -> "" (empty -> null).
        $strategy = new Lightswitch();
        $strategy->setContext(
            craftField: null,
            fieldHandle: 'featured',
            fieldInfo: ['node' => 'featured'],
            feedData: ['featured' => true],
            link: FakeLink::make(),
            element: $this->elementReturning(true),
        );
        $this->assertFalse($strategy->hasChanged($this->elementReturning(true), true));
        $this->assertTrue($strategy->hasChanged($this->elementReturning(true), false));
    }

    private function buildDefault(): DefaultField
    {
        $strategy = new DefaultField();
        $strategy->setContext(
            craftField: null,
            fieldHandle: 'summary',
            fieldInfo: ['node' => 'summary'],
            feedData: [],
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
        return $strategy;
    }

    private function elementReturning(mixed $current): ElementInterface
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);
        return $element;
    }
}
