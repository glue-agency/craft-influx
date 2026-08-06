<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\Time as CraftTimeField;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Time;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Time strategy. The headline is
 * {@see testUnchangedFeedIsNotAChange()}: on the DefaultField fallback this field
 * re-saved its element on every run, because Craft normalizes a Time to a
 * DateTime and serializes it to an `H:i:s` string, and the two never reduced to
 * the same thing.
 */
class TimeFieldTest extends Unit
{
    public function testCraftFieldClassIsTime(): void
    {
        $this->assertSame(CraftTimeField::class, Time::craftFieldClass());
    }

    /**
     * @dataProvider clockSpellings
     */
    public function testParsesTheClockSpellingsFeedsShip(string $raw, string $expected): void
    {
        $parsed = (new Time())->parse($this->context(['at' => $raw]));

        $this->assertInstanceOf(DateTimeInterface::class, $parsed);
        $this->assertSame($expected, $parsed->format('H:i:s'));
    }

    public static function clockSpellings(): array
    {
        return [
            'seconds'    => ['09:30:45', '09:30:45'],
            'no seconds' => ['09:30', '09:30:00'],
            'midnight'   => ['00:00', '00:00:00'],
            'end of day' => ['23:59:59', '23:59:59'],
            '12-hour'    => ['9:30 AM', '09:30:00'],
            '12-hour pm' => ['9:30 PM', '21:30:00'],
            'padded'     => ['  09:30  ', '09:30:00'],
        ];
    }

    /**
     * `H:i` would happily swallow `09:30:45` and drop the seconds — PHP only
     * raises a warning for trailing data. The strategy rejects a partial match so
     * the more specific format wins.
     */
    public function testSecondsSurviveTheMoreSpecificFormat(): void
    {
        $parsed = (new Time())->parse($this->context(['at' => '09:30:45']));

        $this->assertSame('09:30:45', $parsed->format('H:i:s'));
    }

    /**
     * A Time field stores no date, so the date part must not come from "today" —
     * otherwise two reads either side of midnight would disagree.
     */
    public function testTheDatePartIsFixedRatherThanToday(): void
    {
        $parsed = Time::tryParse('09:30');

        $this->assertSame('1970-01-01', $parsed->format('Y-m-d'));
    }

    public function testAFullDatetimeFallsBackToItsTimePart(): void
    {
        $parsed = Time::tryParse(new DateTime('2024-03-02 14:15:16', new DateTimeZone('UTC')));

        $this->assertSame('14:15:16', $parsed->format('H:i:s'));
    }

    public function testUnparseableValueThrows(): void
    {
        $this->expectException(MappingValueException::class);

        (new Time())->parse($this->context(['at' => 'half past nine']));
    }

    public function testAbsentValueYieldsNull(): void
    {
        $this->assertNull((new Time())->parse($this->context([])));
    }

    /**
     * THE regression. Every shape a Time reaches a comparison in must reduce to
     * the same clock time: the stored DateTime at the top level, the serialized
     * `H:i:s` string inside a nested fingerprint, and the DateTime parse() built.
     */
    public function testUnchangedFeedIsNotAChange(): void
    {
        $strategy = new Time();
        $incoming = $strategy->parse($this->context(['at' => '09:30']));

        $stored = new DateTime('2024-03-02 09:30:00', new DateTimeZone('UTC'));
        $this->assertFalse(
            $strategy->hasChanged($this->context(['at' => '09:30'], $stored), $incoming),
            'A stored DateTime and the same clock time from the feed are not a change.',
        );

        $this->assertFalse(
            $strategy->hasChanged($this->context(['at' => '09:30'], '09:30:00'), $incoming),
            'The serialized H:i:s a nested fingerprint carries is the same value too.',
        );
    }

    public function testADifferentTimeIsAChange(): void
    {
        $strategy = new Time();
        $incoming = $strategy->parse($this->context(['at' => '09:31']));
        $stored = new DateTime('2024-03-02 09:30:00', new DateTimeZone('UTC'));

        $this->assertTrue($strategy->hasChanged($this->context(['at' => '09:31'], $stored), $incoming));
    }

    public function testATimeLandingOnAnEmptyFieldIsAChange(): void
    {
        $strategy = new Time();
        $incoming = $strategy->parse($this->context(['at' => '09:30']));

        $this->assertTrue($strategy->hasChanged($this->context(['at' => '09:30'], null), $incoming));
    }

    private function context(array $feed, mixed $current = null): FieldContext
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);

        return new FieldContext(
            craftField: null,
            handle: 'at',
            mapping: FieldMapping::fromConfig('at', ['node' => 'at']),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $element,
        );
    }
}
