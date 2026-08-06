<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use CommerceGuys\Addressing\Country\Country as CountryModel;
use craft\base\ElementInterface;
use craft\fields\Color as CraftColorField;
use craft\fields\Country as CraftCountryField;
use GlueAgency\Influx\fields\Color;
use GlueAgency\Influx\fields\Country;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Country strategy. Craft's field advertises a string and
 * hands back a Country MODEL, which the shared normaliser could only reduce to a
 * JSON blob — so the field rewrote itself every sync. The `match` option covers
 * the other half: a feed that ships country names instead of codes.
 */
class CountryFieldTest extends Unit
{
    public function testCraftFieldClassIsCountry(): void
    {
        $this->assertSame(CraftCountryField::class, Country::craftFieldClass());
    }

    /**
     * @dataProvider codeSpellings
     */
    public function testCodesAreUppercased(string $raw, string $expected): void
    {
        $this->assertSame($expected, (new CountryWithStubbedRepository())->parse($this->context(['country' => $raw])));
    }

    public static function codeSpellings(): array
    {
        return [
            'already upper' => ['BE', 'BE'],
            'lowercase'     => ['be', 'BE'],
            'padded'        => ['  be  ', 'BE'],
        ];
    }

    public function testNamesResolveThroughTheRepositoryWhenMatchingByName(): void
    {
        $strategy = new CountryWithStubbedRepository();

        $this->assertSame('BE', $strategy->parse($this->context(['country' => 'Belgium'], match: 'name')));
        $this->assertSame('NL', $strategy->parse($this->context(['country' => 'netherlands'], match: 'name')));
        $this->assertSame('BE', $strategy->parse($this->context(['country' => '  BELGIUM '], match: 'name')));
    }

    /**
     * Validating that a code exists is Craft's job — it nulls an unknown one on
     * normalize. The strategy's job is not to lose the value on the way there.
     */
    public function testAnUnmatchedNamePassesThrough(): void
    {
        $strategy = new CountryWithStubbedRepository();

        $this->assertSame('Atlantis', $strategy->parse($this->context(['country' => 'Atlantis'], match: 'name')));
    }

    public function testAbsentValueYieldsNull(): void
    {
        $this->assertNull((new CountryWithStubbedRepository())->parse($this->context([])));
    }

    /**
     * A country is a closed set of ~250 codes, so the "use default" cell offers
     * them rather than asking the operator to remember an alpha-2 — but LAZILY,
     * because an eager list would ride every builder bootstrap once per Country
     * field on the layout whether or not the row is ever opened.
     */
    public function testTheDefaultIsALazilyLoadedSelect(): void
    {
        $cell = (new CountryWithStubbedRepository())->schema(
            $this->createMock(CraftCountryField::class),
        )->toArray()['default'][0];

        $this->assertSame(SchemaBuilder::SELECT, $cell['type']);
        $this->assertTrue($cell['lazy']);
        $this->assertArrayNotHasKey('options', $cell, 'A lazy cell carries no options.');
    }

    public function testTheLazyFetchAnswersWithTheCountryList(): void
    {
        $this->assertSame(
            ['BE' => 'Belgium', 'NL' => 'Netherlands', 'FR' => 'France'],
            (new CountryWithStubbedRepository())->defaultOptions(
                $this->createMock(CraftCountryField::class),
            ),
        );
    }

    /**
     * The base hook answers from whatever the editor already declared, so an
     * eager strategy needs no override — the endpoint and the bootstrap agree.
     */
    public function testAnEagerStrategyNeedsNoSeparateOptionsHook(): void
    {
        $field = $this->createMock(CraftColorField::class);
        $field->palette = [['color' => '#e53935', 'label' => 'Red']];

        $this->assertSame(['#e53935' => 'Red'], (new Color())->defaultOptions($field));
    }

    /**
     * The promise the default region's select makes: a picked default already IS
     * a stored code, so `match: name` — which describes what the FEED sends —
     * must not translate it into nothing.
     */
    public function testAPickedDefaultIsNotRunThroughNameMatching(): void
    {
        $parsed = (new CountryWithStubbedRepository())->parse($this->context(
            [],
            match: 'name',
            mapping: ['default' => 'BE', 'useDefault' => true],
        ));

        $this->assertSame('BE', $parsed);
    }

    public function testUnchangedFeedIsNotAChange(): void
    {
        $strategy = new CountryWithStubbedRepository();
        $incoming = $strategy->parse($this->context(['country' => 'be']));

        $this->assertFalse(
            $strategy->hasChanged($this->context([], current: new CountryModel(['country_code' => 'BE', 'name' => 'Belgium', 'locale' => 'en'])), $incoming),
            'A stored Country model and the feed\'s lowercase code are one value.',
        );

        $this->assertFalse(
            $strategy->hasChanged($this->context([], current: 'BE'), $incoming),
            'The alpha-2 Craft serializes to is the same value too.',
        );
    }

    public function testADifferentCountryIsAChange(): void
    {
        $strategy = new CountryWithStubbedRepository();
        $incoming = $strategy->parse($this->context(['country' => 'NL']));

        $this->assertTrue(
            $strategy->hasChanged($this->context([], current: new CountryModel(['country_code' => 'BE', 'name' => 'Belgium', 'locale' => 'en'])), $incoming),
        );
    }

    private function context(
        array $feed,
        mixed $current = null,
        string $match = 'code',
        array $mapping = [],
    ): FieldContext {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);

        return new FieldContext(
            craftField: null,
            handle: 'country',
            mapping: FieldMapping::fromConfig('country', $mapping + [
                'node'    => 'country',
                'options' => ['match' => $match],
            ]),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $element,
        );
    }
}

/**
 * @internal The real map reads Craft's address service, which needs a booted
 * app; the lookup semantics are what this spec is about.
 */
class CountryWithStubbedRepository extends Country
{
    protected function countryList(): array
    {
        return [
            'BE' => 'Belgium',
            'NL' => 'Netherlands',
            'FR' => 'France',
        ];
    }
}
