<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\Range as CraftRangeField;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Range;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Range strategy: write what the scale can hold, so the
 * CP's slider and the feed agree from the first sync instead of ping-ponging.
 */
class RangeFieldTest extends Unit
{
    public function testCraftFieldClassIsRange(): void
    {
        $this->assertSame(CraftRangeField::class, Range::craftFieldClass());
    }

    /**
     * @dataProvider scalePositions
     */
    public function testClampsAndSnapsToTheFieldsOwnScale(mixed $raw, int|float $expected): void
    {
        $field = $this->createMock(CraftRangeField::class);
        $field->min = 0;
        $field->max = 100;
        $field->step = 5;

        $this->assertSame($expected, (new Range())->parse($this->context(['score' => $raw], field: $field)));
    }

    public static function scalePositions(): array
    {
        return [
            'on a step'      => [20, 20],
            'between steps'  => [7, 5],
            'rounds up'      => [8, 10],
            'above the max'  => [250, 100],
            'below the min'  => [-40, 0],
            'numeric string' => ['20', 20],
            'decimal'        => [19.6, 20],
        ];
    }

    /**
     * A scale that doesn't start at zero measures its steps from the min, so a
     * 10–100 range in fives offers 10, 15, 20 — not 15, 20, 25.
     */
    public function testStepsAreMeasuredFromTheMinNotFromZero(): void
    {
        $field = $this->createMock(CraftRangeField::class);
        $field->min = 10;
        $field->max = 100;
        $field->step = 5;

        $this->assertSame(10, (new Range())->parse($this->context(['score' => 11], field: $field)));
        $this->assertSame(15, (new Range())->parse($this->context(['score' => 14], field: $field)));
    }

    public function testAFractionalScaleKeepsItsFractions(): void
    {
        $field = $this->createMock(CraftRangeField::class);
        $field->min = 0;
        $field->max = 1;
        $field->step = 0.25;

        $this->assertSame(0.5, (new Range())->parse($this->context(['score' => 0.6], field: $field)));
    }

    public function testANonNumericValueThrows(): void
    {
        $this->expectException(MappingValueException::class);

        (new Range())->parse($this->context(['score' => 'high']));
    }

    public function testAbsentValueYieldsNull(): void
    {
        $this->assertNull((new Range())->parse($this->context([])));
    }

    public function testTheSameNumberSpelledDifferentlyIsNotAChange(): void
    {
        $strategy = new Range();

        $this->assertFalse($strategy->hasChanged($this->context([], current: 20), 20));
        $this->assertFalse($strategy->hasChanged($this->context([], current: '20'), 20));
        $this->assertFalse($strategy->hasChanged($this->context([], current: 20.0), 20));
        $this->assertTrue($strategy->hasChanged($this->context([], current: 25), 20));
    }

    private function context(array $feed, mixed $current = null, mixed $field = null): FieldContext
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);

        return new FieldContext(
            craftField: $field,
            handle: 'score',
            mapping: FieldMapping::fromConfig('score', ['node' => 'score']),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $element,
        );
    }
}
