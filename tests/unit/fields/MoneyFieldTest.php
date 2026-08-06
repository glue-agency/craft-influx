<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\Money as CraftMoneyField;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Money;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use Money\Currency;
use Money\Money as MoneyLibrary;

/**
 * Behaviour spec for the Money strategy.
 *
 * Two regressions in one: on the DefaultField fallback the field re-saved on
 * every run (a `Money\Money` reduces through `json_encode`, the feed's string
 * doesn't), and Craft inferred the unit from punctuation — so `20` quietly meant
 * twenty cents while `20.00` meant twenty euros.
 */
class MoneyFieldTest extends Unit
{
    public function testCraftFieldClassIsMoney(): void
    {
        $this->assertSame(CraftMoneyField::class, Money::craftFieldClass());
    }

    /**
     * @dataProvider majorAmounts
     */
    public function testMajorUnitsAreParsedAsPeopleWriteThem(mixed $raw, string $expectedMinor): void
    {
        $parsed = (new Money())->parse($this->context(['price' => $raw]));

        $this->assertInstanceOf(MoneyLibrary::class, $parsed);
        $this->assertSame($expectedMinor, $parsed->getAmount());
        $this->assertSame('EUR', $parsed->getCurrency()->getCode());
    }

    public static function majorAmounts(): array
    {
        return [
            'plain decimal'     => ['19.99', '1999'],
            'whole number'      => ['20', '2000'],
            'float'             => [19.99, '1999'],
            'int'               => [20, '2000'],
            'currency symbol'   => ['€19.99', '1999'],
            'thousands comma'   => ['1,234.56', '123456'],
            'european notation' => ['1.234,56', '123456'],
            'decimal comma'     => ['19,99', '1999'],
            'negative'          => ['-19.99', '-1999'],
        ];
    }

    /**
     * The unit is declared, not inferred: the same `2000` means twenty euros as a
     * major amount and twenty euros as a minor one only because the mapping says
     * which it is.
     */
    public function testMinorUnitsAreTakenVerbatim(): void
    {
        $parsed = (new Money())->parse($this->context(['price' => 1999], units: 'minor'));

        $this->assertSame('1999', $parsed->getAmount());
    }

    public function testTheSameFeedNumberMeansDifferentThingsUnderEachUnit(): void
    {
        $this->assertSame('2000', (new Money())->parse($this->context(['price' => '20']))->getAmount());
        $this->assertSame('20', (new Money())->parse($this->context(['price' => '20'], units: 'minor'))->getAmount());
    }

    public function testAnUnparseableAmountThrows(): void
    {
        $this->expectException(MappingValueException::class);

        (new Money())->parse($this->context(['price' => 'free']));
    }

    public function testAbsentValueYieldsNull(): void
    {
        $this->assertNull((new Money())->parse($this->context([])));
    }

    /**
     * THE regression: an unchanged price must not re-save the element.
     */
    public function testUnchangedFeedIsNotAChange(): void
    {
        $strategy = new Money();
        $incoming = $strategy->parse($this->context(['price' => '19.99']));
        $stored = new MoneyLibrary('1999', new Currency('EUR'));

        $this->assertFalse(
            $strategy->hasChanged($this->context([], current: $stored), $incoming),
            'A stored Money\Money and the same amount from the feed are not a change.',
        );

        $this->assertFalse(
            $strategy->hasChanged($this->context([], current: '1999'), $incoming),
            'The minor-unit string Craft serializes to is the same value too.',
        );
    }

    public function testADifferentPriceIsAChange(): void
    {
        $strategy = new Money();
        $incoming = $strategy->parse($this->context(['price' => '24.99']));
        $stored = new MoneyLibrary('1999', new Currency('EUR'));

        $this->assertTrue($strategy->hasChanged($this->context([], current: $stored), $incoming));
    }

    private function context(
        array $feed,
        mixed $current = null,
        string $units = 'major',
        string $currency = 'EUR',
    ): FieldContext {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);

        $field = $this->createMock(CraftMoneyField::class);
        $field->currency = $currency;

        return new FieldContext(
            craftField: $field,
            handle: 'price',
            mapping: FieldMapping::fromConfig('price', ['node' => 'price', 'options' => ['units' => $units]]),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $element,
        );
    }
}
