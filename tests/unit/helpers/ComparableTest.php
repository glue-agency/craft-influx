<?php

namespace GlueAgency\Influx\Tests\unit\helpers;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use GlueAgency\Influx\helpers\Comparable;

/**
 * Behaviour spec for THE change-detection normaliser, shared by the custom-field
 * strategies ({@see \GlueAgency\Influx\fields\Field::normalize()}) and the native
 * attribute path ({@see \GlueAgency\Influx\targets\AbstractElementTarget::comparable()}).
 * Two semantically-equal values must reduce to the same representation, so a
 * re-applied flag / date / relation never reads as a change.
 */
class ComparableTest extends Unit
{
    public function testEmptyValuesCollapseToNull(): void
    {
        $this->assertNull(Comparable::of(null));
        $this->assertNull(Comparable::of(''));
    }

    public function testBooleansAreRealValuesNotEmptiness(): void
    {
        $this->assertSame('1', Comparable::of(true));
        $this->assertSame('0', Comparable::of(false));
        $this->assertNotSame(Comparable::of(null), Comparable::of(false), 'Unset and false are different states.');
    }

    public function testDatesCompareByInstant(): void
    {
        $utc = new DateTime('2024-03-02 10:00:00', new DateTimeZone('UTC'));
        $brussels = new DateTimeImmutable('2024-03-02 11:00:00', new DateTimeZone('Europe/Brussels'));

        $this->assertSame(Comparable::of($utc), Comparable::of($brussels));
        $this->assertNotSame(Comparable::of($utc), Comparable::of(new DateTime('2024-03-02 10:00:01', new DateTimeZone('UTC'))));
    }

    public function testElementsCompareById(): void
    {
        $element = $this->createMock(ElementInterface::class);
        $element->id = 12;
        $same = $this->createMock(ElementInterface::class);
        $same->id = 12;

        $this->assertSame(Comparable::of($element), Comparable::of($same));
        $this->assertSame(12, Comparable::of($element));
    }

    public function testScalarsCompareAsStringsAndTheRestAsJson(): void
    {
        $this->assertSame('1', Comparable::of(1));
        $this->assertSame('1', Comparable::of('1'));
        $this->assertSame('1.5', Comparable::of(1.5));
        $this->assertSame('[1,2]', Comparable::of([1, 2]));
    }
}
