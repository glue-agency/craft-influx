<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use DateTime;
use DateTimeZone;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\fields\DefaultField;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use RuntimeException;

/**
 * Behaviour spec for Field::hasChanged. The sync engine uses it to skip
 * elements where nothing has actually changed: if every field's hasChanged
 * returns false AND the element isn't new, no saveElement call is made and
 * the item is logged as 'unchanged'.
 *
 * Default semantics (Field base):
 *   - getFieldValue throws -> assume changed (safer default)
 *   - normalise both sides through the shared comparison normaliser
 *     ({@see \GlueAgency\Influx\helpers\Comparable::of()}) and compare: scalars
 *     as strings, bools as '1'/'0', dates by instant, elements by id
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
        $element->method('getFieldValue')->willThrowException(new RuntimeException('boom'));
        $context = $this->context($element);
        $this->assertTrue(
            (new DefaultField())->hasChanged($context, 'anything'),
            "Failing to read the current value must default to 'changed' — otherwise we'd silently skip syncs on broken fields.",
        );
    }

    public function testLightswitchReadsTheBooleanSemantically(): void
    {
        // Lightswitch inherits the base hasChanged; the shared normaliser keeps
        // a bool a real value on both sides (true -> "1", false -> "0"), so a
        // flag flip is a change and a re-applied flag isn't.
        $strategy = new Lightswitch();
        $context = $this->context($this->elementReturning(true), handle: 'featured');
        $this->assertFalse($strategy->hasChanged($context, true));
        $this->assertTrue($strategy->hasChanged($context, false));
    }

    public function testDateComparesByInstantWhateverShapeTheStoredValueTakes(): void
    {
        $strategy = new Date();

        // The top-level path is unchanged: a Date field's current value is its
        // normalized DateTime, and two DateTimes compare by instant.
        $context = $this->context(
            $this->elementReturning(new DateTime('2024-03-02 10:00:00', new DateTimeZone('UTC'))),
            handle: 'published',
        );
        $this->assertFalse($strategy->hasChanged(
            $context,
            new DateTime('2024-03-02 11:00:00', new DateTimeZone('Europe/Brussels')),
        ));
        $this->assertTrue($strategy->hasChanged(
            $context,
            new DateTime('2024-03-02 10:00:01', new DateTimeZone('UTC')),
        ));

        // A current value Craft already serialized reads as the same instant too —
        // the Matrix fingerprint's stored side arrives in exactly that shape.
        $serialized = $this->context($this->elementReturning('2024-03-02T10:00:00+00:00'), handle: 'published');
        $this->assertFalse($strategy->hasChanged(
            $serialized,
            new DateTime('2024-03-02 10:00:00', new DateTimeZone('UTC')),
        ));
    }

    public function testDateUnwrapsTheStoredTimeZoneEnvelope(): void
    {
        // Craft before 4.15 serialized a Date field with "Show time zone" on as
        // {date, tz}, where date is already UTC and tz is display-only — the
        // envelope has to be unwrapped rather than JSON-compared whole. The inner
        // string is written in whatever format the running Craft serializes to, so
        // this reads the same on either major.
        $instant = new DateTime('2024-03-02 10:00:00', new DateTimeZone('UTC'));
        $stored = [
            'date' => $instant->format(Compat::serializedDateFormat()),
            'tz'   => 'Europe/Brussels',
        ];

        $context = $this->context($this->elementReturning($stored), handle: 'published');
        $this->assertFalse((new Date())->hasChanged($context, $instant));
    }

    public function testDateKeepsNonDatesDistinguishable(): void
    {
        // Reading a date out of a value is a widening of the base normalisation,
        // not a replacement: two values no date parse accepts must not both
        // collapse to "no value" and read as equal.
        $context = $this->context($this->elementReturning('not-a-date'), handle: 'published');
        $this->assertTrue((new Date())->hasChanged($context, 'another-non-date'));
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
