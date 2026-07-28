<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\Date as CraftDateField;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Date field strategy's explicit-format path: an
 * `options.format` on the mapping wins over the auto-detector. Parsing itself is
 * {@see Date::tryParse()}, shared with the native date attributes
 * ({@see \GlueAgency\Influx\targets\EntryTarget::assignDate()}) — only the policy
 * for an unparseable value differs (error row here, no-op there).
 *
 * The auto-detect fallback (no format option) routes through Craft's
 * `DateTimeHelper::toDateTime`, which needs a booted app for its timezone
 * lookup — the no-boot suite covers the format branch only.
 */
class DateFieldTest extends Unit
{
    public function testExplicitFormatDisambiguatesDayMonthOrder(): void
    {
        // '02/03/2024' is ambiguous — under d/m/Y it must land on March 2nd,
        // not February 3rd (what a US-order auto-detect would produce).
        $parsed = (new Date())->parse($this->context(
            feed: ['published' => '02/03/2024'],
            mapping: ['node' => 'published', 'options' => ['format' => 'd/m/Y']],
        ));

        $this->assertInstanceOf(DateTimeInterface::class, $parsed);
        $this->assertSame('2024-03-02', $parsed->format('Y-m-d'));
    }

    public function testTimestampSentinelParsesUnixSeconds(): void
    {
        $parsed = (new Date())->parse($this->context(
            feed: ['published' => '1719878400'],
            mapping: ['node' => 'published', 'options' => ['format' => 'timestamp']],
        ));

        $this->assertInstanceOf(DateTimeInterface::class, $parsed);
        $this->assertSame(1719878400, $parsed->getTimestamp());
    }

    public function testValueNotMatchingExplicitFormatThrows(): void
    {
        $this->expectException(MappingValueException::class);

        (new Date())->parse($this->context(
            feed: ['published' => 'not-a-date'],
            mapping: ['node' => 'published', 'options' => ['format' => 'Y-m-d']],
        ));
    }

    public function testAbsentValueYieldsNull(): void
    {
        $context = $this->context(
            feed: [],
            mapping: ['node' => 'published', 'options' => ['format' => 'Y-m-d']],
        );

        $this->assertNull((new Date())->parse($context));
    }

    public function testTryParseReturnsNullOnUnparseableValueSoCallersOwnThePolicy(): void
    {
        // The shared core never throws and never guesses: the field strategy
        // turns this null into an error row, the native date attributes into a
        // no-op.
        $this->assertNull(Date::tryParse('not-a-date', 'Y-m-d'));
        $this->assertNull(Date::tryParse('not-a-number', 'timestamp'));
    }

    public function testTryParsePassesADateTimeStraightThrough(): void
    {
        $date = new DateTime('2024-03-02 10:00:00', new DateTimeZone('UTC'));

        $this->assertSame($date, Date::tryParse($date, 'd/m/Y'));
    }

    public function testChangeDetectionComparesTheInstantNotTheRepresentation(): void
    {
        // No Date-specific override: the shared comparison normaliser reduces
        // both sides to a timestamp, so the same instant in another timezone is
        // not a change while a second's difference is.
        $current = new DateTime('2024-03-02 10:00:00', new DateTimeZone('UTC'));
        $context = $this->context(feed: [], mapping: ['node' => 'published'], current: $current);

        $this->assertFalse((new Date())->hasChanged($context, new DateTime('2024-03-02 11:00:00', new DateTimeZone('Europe/Brussels'))));
        $this->assertTrue((new Date())->hasChanged($context, new DateTime('2024-03-02 10:00:01', new DateTimeZone('UTC'))));

        $empty = $this->context(feed: [], mapping: ['node' => 'published'], current: null);
        $this->assertTrue((new Date())->hasChanged($empty, $current), 'A date landing on an empty field is a change.');
    }

    public function testCraftFieldClassIsDate(): void
    {
        $this->assertSame(CraftDateField::class, Date::craftFieldClass());
    }

    private function context(array $feed, array $mapping, mixed $current = null): FieldContext
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);

        return new FieldContext(
            craftField: null,
            handle: 'published',
            mapping: FieldMapping::fromConfig('published', $mapping),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $element,
        );
    }
}
