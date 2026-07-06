<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\Date as CraftDateField;
use DateTimeInterface;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Date field strategy's explicit-format path: an
 * `options.format` on the mapping wins over the auto-detector, matching the
 * native date attributes ({@see \GlueAgency\Influx\targets\EntryTarget::parseDateValue()}).
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

    public function testCraftFieldClassIsDate(): void
    {
        $this->assertSame(CraftDateField::class, Date::craftFieldClass());
    }

    private function context(array $feed, array $mapping): FieldContext
    {
        return new FieldContext(
            craftField: null,
            handle: 'published',
            mapping: FieldMapping::fromConfig('published', $mapping),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }
}
