<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\fields\DefaultField;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

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
        $context = $this->context($this->elementReturning('hello'));
        $this->assertFalse((new DefaultField())->hasChanged($context, 'hello'));
    }

    public function testDifferentScalarsAreChanged(): void
    {
        $context = $this->context($this->elementReturning('hello'));
        $this->assertTrue((new DefaultField())->hasChanged($context, 'world'));
    }

    public function testNullAndEmptyStringAreEquivalent(): void
    {
        $context = $this->context($this->elementReturning(''));
        $this->assertFalse((new DefaultField())->hasChanged($context, null));

        $context = $this->context($this->elementReturning(null));
        $this->assertFalse((new DefaultField())->hasChanged($context, ''));
    }

    public function testNumericCoercionFollowsStringNormalisation(): void
    {
        // normalise() converts scalars to strings; the comparison is then
        // string-vs-string so '1' equals 1 (intentional: feeds often hand
        // numbers as strings).
        $context = $this->context($this->elementReturning(1));
        $this->assertFalse((new DefaultField())->hasChanged($context, '1'));
    }

    public function testReadFailureTreatedAsChanged(): void
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willThrowException(new \RuntimeException('boom'));
        $context = $this->context($element);
        $this->assertTrue(
            (new DefaultField())->hasChanged($context, 'anything'),
            "Failing to read the current value must default to 'changed' — otherwise we'd silently skip syncs on broken fields.",
        );
    }

    public function testLightswitchReadsTheBooleanSemantically(): void
    {
        // Lightswitch inherits the base hasChanged; both sides normalise to
        // a string. true -> "1", false -> "" (empty -> null).
        $strategy = new Lightswitch();
        $context = $this->context($this->elementReturning(true), handle: 'featured');
        $this->assertFalse($strategy->hasChanged($context, true));
        $this->assertTrue($strategy->hasChanged($context, false));
    }

    private function context(ElementInterface $element, string $handle = 'summary'): FieldContext
    {
        return new FieldContext(
            craftField: null,
            handle: $handle,
            mapping: FieldMapping::fromConfig($handle, ['node' => $handle]),
            item: new RemoteItem([]),
            link: FakeLink::make(),
            element: $element,
        );
    }

    private function elementReturning(mixed $current): ElementInterface
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);
        return $element;
    }
}
