<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\elements\Entry;
use craft\fields\Addresses as CraftAddressesField;
use craft\fields\Assets as CraftAssetsField;
use craft\fields\BaseOptionsField;
use craft\fields\Categories as CraftCategoriesField;
use craft\fields\Checkboxes;
use craft\fields\Color as CraftColorField;
use craft\fields\ContentBlock as CraftContentBlockField;
use craft\fields\Country as CraftCountryField;
use craft\fields\Date as CraftDateField;
use craft\fields\Entries as CraftEntriesField;
use craft\fields\Icon as CraftIconField;
use craft\fields\Json as CraftJsonField;
use craft\fields\Lightswitch as CraftLightswitchField;
use craft\fields\Link as CraftLinkField;
use craft\fields\Matrix as CraftMatrixField;
use craft\fields\Money as CraftMoneyField;
use craft\fields\MultiSelect;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Range as CraftRangeField;
use craft\fields\Table as CraftTableField;
use craft\fields\Tags as CraftTagsField;
use craft\fields\Time as CraftTimeField;
use craft\fields\Users as CraftUsersField;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\fields\Addresses;
use GlueAgency\Influx\fields\Assets;
use GlueAgency\Influx\fields\Categories;
use GlueAgency\Influx\fields\Color;
use GlueAgency\Influx\fields\ContentBlock;
use GlueAgency\Influx\fields\Country;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\fields\DefaultField;
use GlueAgency\Influx\fields\Dropdown;
use GlueAgency\Influx\fields\Entries;
use GlueAgency\Influx\fields\Icon;
use GlueAgency\Influx\fields\Json;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\fields\Link;
use GlueAgency\Influx\fields\Matrix;
use GlueAgency\Influx\fields\Money;
use GlueAgency\Influx\fields\Range;
use GlueAgency\Influx\fields\RichText;
use GlueAgency\Influx\fields\Table;
use GlueAgency\Influx\fields\Tags;
use GlueAgency\Influx\fields\Time;
use GlueAgency\Influx\fields\Users;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\services\FieldsService;
use ReflectionClass;

/**
 * What this registry adds on top of the shared base (specced in
 * {@see \GlueAgency\Influx\Tests\unit\services\AbstractRegistryTest}):
 *
 *   - Lookup walks parent class chain so concrete Craft Dropdown / Radio /
 *     Checkboxes / MultiSelect all resolve to the BaseOptionsField strategy.
 *   - Unknown Craft field types fall through to DefaultField.
 *   - register(Class) replaces an existing entry for the same FQCN — the hook
 *     third parties use to override built-ins — and a strategy declaring no
 *     Craft field class can't be filed, so it throws.
 */
class FieldsServiceTest extends Unit
{
    /**
     * The enumeration: every built-in, under the exact key it declares. This is
     * the guard against a strategy being written but never wired up — the whole
     * failure mode is silent, since an unregistered field type just falls to
     * DefaultField and writes something plausible.
     *
     * @dataProvider builtIns
     */
    public function testBuiltInsAreRegistered(string $craftFieldClass, string $strategy): void
    {
        $service = new FieldsService();
        $service->init();

        $byFqcn = $service->all();

        $this->assertArrayHasKey($craftFieldClass, $byFqcn);
        $this->assertInstanceOf($strategy, $byFqcn[$craftFieldClass]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function builtIns(): array
    {
        return [
            'assets'      => [CraftAssetsField::class, Assets::class],
            'lightswitch' => [CraftLightswitchField::class, Lightswitch::class],
            // The whole option family resolves through this one base key.
            'options'    => [BaseOptionsField::class, Dropdown::class],
            'entries'    => [CraftEntriesField::class, Entries::class],
            'users'      => [CraftUsersField::class, Users::class],
            'categories' => [CraftCategoriesField::class, Categories::class],
            'tags'       => [CraftTagsField::class, Tags::class],
            'date'       => [CraftDateField::class, Date::class],
            'time'       => [CraftTimeField::class, Time::class],
            'matrix'     => [CraftMatrixField::class, Matrix::class],
            // Table, Color, Money and the rest extend craft\base\Field directly,
            // so the parent-chain walk gives them nothing — each needs its own
            // entry or it falls to DefaultField.
            'table'        => [CraftTableField::class, Table::class],
            'color'        => [CraftColorField::class, Color::class],
            'country'      => [CraftCountryField::class, Country::class],
            'money'        => [CraftMoneyField::class, Money::class],
            'contentBlock' => [CraftContentBlockField::class, ContentBlock::class],
            'link'         => [CraftLinkField::class, Link::class],
            'addresses'    => [CraftAddressesField::class, Addresses::class],
            'icon'         => [CraftIconField::class, Icon::class],
            'json'         => [CraftJsonField::class, Json::class],
            'range'        => [CraftRangeField::class, Range::class],
            // Keyed by class STRING: html-field ships as its own package, which
            // a given install may not have at all.
            'richText' => ['craft\htmlfield\HtmlField', RichText::class],
        ];
    }

    /**
     * Craft 5 aliases the deprecated URL field onto Link, so the Link strategy
     * covers both there. On Craft 4 `craft\fields\Url` is a genuine string field
     * and the fallback is right for it — which is why there's no separate key.
     */
    public function testTheCraft5UrlFieldResolvesThroughTheLinkStrategy(): void
    {
        if (! class_exists(CraftLinkField::class)) {
            $this->markTestSkipped('craft\fields\Link is Craft 5.3+.');
        }

        $this->assertSame(
            CraftLinkField::class,
            (new ReflectionClass('craft\fields\Url'))->getName(),
            'craft\fields\Url must be a class_alias onto Link, not a class of its own.',
        );
    }

    public function testParentChainResolutionDispatchesDropdownVariants(): void
    {
        $service = new FieldsService();
        $service->init();

        // craft\fields\RadioButtons extends BaseOptionsField — we shouldn't
        // need a separate strategy for each option-based subclass.
        $field = $this->createMock(RadioButtons::class);
        $this->assertInstanceOf(
            Dropdown::class,
            $service->forCraftField($field),
            'RadioButtons should resolve through BaseOptionsField to the Dropdown strategy.',
        );

        $field = $this->createMock(Checkboxes::class);
        $this->assertInstanceOf(Dropdown::class, $service->forCraftField($field));

        $field = $this->createMock(MultiSelect::class);
        $this->assertInstanceOf(Dropdown::class, $service->forCraftField($field));
    }

    public function testFallsBackToDefaultField(): void
    {
        $service = new FieldsService();
        $service->init();

        $field = $this->createMock(PlainText::class);
        $this->assertInstanceOf(
            DefaultField::class,
            $service->forCraftField($field),
            'PlainText has no dedicated strategy; it must resolve to the DefaultField fallback.',
        );
    }

    /**
     * The one thing a sub-field row asks of another field's strategy: which control
     * that field's default cell renders. A relation's is a picker, so a relation
     * sub-field row offers one rather than a box to retype a reference into.
     */
    public function testAChildRowRendersTheControlItsFieldDeclares(): void
    {
        $service = new FieldsService();
        $service->init();

        $this->assertSame(
            ['type' => SchemaBuilder::ELEMENT, 'elementType' => Entry::class],
            $service->rowFor($this->createMock(CraftEntriesField::class))->region('default')[0],
            'An Entries field must default-pick entries, the way a native author picks users.',
        );
    }

    public function testAChildRowOffersAnOptionFieldsOwnOptions(): void
    {
        $service = new FieldsService();
        $service->init();

        $field = $this->createMock(RadioButtons::class);
        $field->options = [
            ['label' => 'North', 'value' => 'north'],
            ['label' => 'South', 'value' => 'south'],
        ];

        $this->assertSame(
            [
                'type'    => SchemaBuilder::SELECT,
                'options' => [
                    ['value' => 'north', 'label' => 'North'],
                    ['value' => 'south', 'label' => 'South'],
                ],
                'searchable'        => true,
                'searchPlaceholder' => 'Search options…',
                'sentinelOptions'   => [['value' => '', 'label' => '— no default —']],
            ],
            $service->rowFor($field)->region('default')[0],
            'The whole BaseOptionsField family default-picks from its own options.',
        );
    }

    public function testAChildRowIsAPlainTextInputForAFieldWithNoOpinion(): void
    {
        $service = new FieldsService();
        $service->init();

        // No strategy, no opinion: the base row declares a plain text default.
        $this->assertSame(
            ['type' => SchemaBuilder::TEXT],
            $service->rowFor($this->createMock(PlainText::class))->region('default')[0],
        );
    }

    /**
     * A nested row is configured the way the same field is at the top level: its
     * control AND its extras, all of which the applier honours because a sub-row is
     * a whole FieldMapping it descends into.
     */
    public function testAChildRowCarriesItsFieldsExtrasToo(): void
    {
        $service = new FieldsService();
        $service->init();
        $service->register(NestingStrategy::class);
        NestingStrategy::$service = $service;

        $row = $service->childRowFor(new NestedField());

        $this->assertSame(SchemaBuilder::TEXT, $row['default']['type']);
        $this->assertSame(['format'], array_column($row['extra'], 'handle'));
    }

    /**
     * THE bound, and it's load-bearing rather than a UI preference: a relation graph
     * can be cyclic — A relates B, B relates back to A — so a card whose rows built
     * their own children's cards would never terminate. One level, cut at the fetch.
     */
    public function testANestedRowsOwnChildrenGetNoExtras(): void
    {
        $service = new FieldsService();
        $service->init();
        $service->register(NestingStrategy::class);
        NestingStrategy::$service = $service;
        NestingStrategy::$reentrant = null;

        // Building the row records what its own extras asked of THEIR child — the
        // re-entrant call, which is where a cycle would otherwise run away.
        $service->childRowFor(new NestedField());

        $this->assertNotNull(NestingStrategy::$reentrant, 'The extras region did ask.');
        $this->assertSame(
            [],
            NestingStrategy::$reentrant['extra'],
            'A card never nests another card, whatever the field graph looks like.',
        );
        $this->assertSame(
            SchemaBuilder::TEXT,
            NestingStrategy::$reentrant['default']['type'],
            'The control still comes through — only the extras are cut.',
        );
    }
    public function testRegisterReplacesExistingStrategy(): void
    {
        $service = new FieldsService();
        $service->init();

        // Replace the built-in Lightswitch handler.
        $service->register(LightswitchOverride::class);

        $field = $this->createMock(CraftLightswitchField::class);
        $this->assertInstanceOf(LightswitchOverride::class, $service->forCraftField($field));
    }

    public function testMatchesAStrategyDeclaringALeadingBackslashFqcn(): void
    {
        $service = new FieldsService();
        $service->init();

        $service->register(BackslashedLightswitch::class);

        $field = $this->createMock(CraftLightswitchField::class);
        $this->assertInstanceOf(
            BackslashedLightswitch::class,
            $service->forCraftField($field),
            "'\\craft\\fields\\Lightswitch' names the same class as 'craft\\fields\\Lightswitch'.",
        );
    }

    public function testRejectsAStrategyWithoutACraftFieldClass(): void
    {
        $service = new FieldsService();
        $service->init();

        $this->expectException(InfluxException::class);
        $service->register(KeylessStrategy::class);
    }
    /**
     * A field whose strategy refuses mapping declares its notice in the SOURCE
     * region — the cell the node select would have taken — and nothing else. No
     * default cell to fall back to, no extras to configure, and no flag saying so:
     * the absent regions are the statement.
     */
    public function testAnUnmappableFieldDeclaresOnlyItsNotice(): void
    {
        $service = new FieldsService();
        $service->init();

        $service->register(UnmappableStrategy::class);

        $row = $service->rowFor(new UnmappableField());

        $this->assertSame(['source'], array_keys($row->toArray()));
        $this->assertSame(SchemaBuilder::NOTE, $row->region('source')[0]['type']);
        $this->assertSame([], $row->region('default'));
    }
}

/** @internal Inline override fixture used by the registry test above. */
class LightswitchOverride extends Lightswitch
{
    public static function craftFieldClass(): ?string
    {
        return CraftLightswitchField::class;
    }
}

/** @internal Declares its Craft field class with a leading backslash. */
class BackslashedLightswitch extends Lightswitch
{
    public static function craftFieldClass(): ?string
    {
        return '\craft\fields\Lightswitch';
    }
}

/** @internal Registerable-looking strategy that declares no Craft field class. */
class KeylessStrategy extends Lightswitch
{
    public static function craftFieldClass(): ?string
    {
        return null;
    }
}

/**
 * @internal A field type whose strategy refuses mapping, like Preparse — but
 * without needing that plugin installed.
 */
class UnmappableField extends PlainText
{
}

/** @internal Refuses mapping for {@see UnmappableField}. */
class UnmappableStrategy extends Lightswitch
{
    public static function craftFieldClass(): ?string
    {
        return UnmappableField::class;
    }

    public function schema(\craft\base\FieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            'source'  => fn(MappingSchemaBuilder $b)  => $b->note(['text' => 'Computed.']),
            'default' => false,
        ]);
    }
}

/** @internal A field type whose strategy declares one extras control. */
class NestedField extends PlainText
{
}

/**
 * @internal Declares a `format` extra for {@see NestedField}, and records what its
 * extras region asks of its own child — a field related to itself, i.e. the tightest
 * cycle a relation graph can have.
 */
class NestingStrategy extends Lightswitch
{
    /** @var array{default: array|null, extra: list<array>}|null */
    public static ?array $reentrant = null;

    public static ?FieldsService $service = null;

    public static function craftFieldClass(): ?string
    {
        return NestedField::class;
    }

    public function schema(\craft\base\FieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            'source'  => true,
            'default' => true,
            'extra'   => function(MappingSchemaBuilder $builder) use ($field): void {
                self::$reentrant = $this->childRowFor($field);
                $builder->dateFormat(['options' => []]);
            },
        ]);
    }

    /** The registry seam, pointed at the test's own service instance. */
    protected function childRowFor(\craft\base\FieldInterface $craftField): array
    {
        return self::$service->childRowFor($craftField);
    }
}
