<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\elements\Address as CraftAddressElement;
use craft\fieldlayoutelements\addresses\AddressField;
use craft\fieldlayoutelements\addresses\CountryCodeField;
use craft\fieldlayoutelements\addresses\LabelField;
use craft\fields\Addresses as CraftAddressesField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use GlueAgency\Influx\fields\Addresses;
use GlueAgency\Influx\fields\DefaultField;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Addresses strategy. On the DefaultField fallback the
 * field stored nothing at all — an address is a nested element, and Craft's
 * normalize only reads its own serialized shape — so the sync reported success
 * over an empty field. This builds that shape.
 */
class AddressesFieldTest extends Unit
{
    public function testCraftFieldClassIsAddresses(): void
    {
        $this->assertSame(CraftAddressesField::class, Addresses::craftFieldClass());
    }

    /**
     * Neither cell is declared, which is the whole statement: an address's value
     * only ever comes from its sub-mappings, so there's nothing for a feed node or
     * a default to say.
     */
    public function testTheRowHasNeitherCellOfItsOwn(): void
    {
        $schema = (new AddressesWithStubbedLayout())->schema($this->createMock(CraftFieldInterface::class));

        $this->assertFalse($schema->has('source'));
        $this->assertFalse($schema->has('default'));
        $this->assertTrue($schema->has('extra'), 'The sub-field card is all the row renders.');
    }

    /**
     * Craft assigns an address's natives off the top level of each record and its
     * custom fields out of a nested `fields` key.
     */
    public function testNativesAndCustomFieldsLandInTheirOwnChannels(): void
    {
        $parsed = (new AddressesWithStubbedLayout())->parse($this->context(
            feed: ['city' => 'Ghent', 'cc' => 'BE', 'note' => 'HQ'],
            fields: [
                'locality'    => ['node' => 'city'],
                'countryCode' => ['node' => 'cc'],
                'notes'       => ['node' => 'note'],
            ],
        ));

        $this->assertSame([
            'new1' => [
                'locality'    => 'Ghent',
                'countryCode' => 'BE',
                'fields'      => ['notes' => 'HQ'],
            ],
        ], $parsed);
    }

    /**
     * The fan-out rule Matrix and Table share: record N takes the Nth value of
     * every mapped slot.
     */
    public function testListsAreIndexZippedIntoOneRecordPerEntry(): void
    {
        $parsed = (new AddressesWithStubbedLayout())->parse($this->context(
            feed: ['city' => ['Ghent', 'Bruges'], 'cc' => ['BE', 'BE']],
            fields: [
                'locality'    => ['node' => 'city'],
                'countryCode' => ['node' => 'cc'],
            ],
        ));

        $this->assertSame([
            'new1' => ['locality' => 'Ghent',  'countryCode' => 'BE'],
            'new2' => ['locality' => 'Bruges', 'countryCode' => 'BE'],
        ], $parsed);
    }

    /**
     * countryCode is a non-null string on the element, and Address::init() has
     * already defaulted it from the general config — so an empty one is skipped
     * rather than written over that default.
     */
    public function testAnEmptyCountryCodeIsSkippedRatherThanWrittenEmpty(): void
    {
        $parsed = (new AddressesWithStubbedLayout())->parse($this->context(
            feed: ['city' => 'Ghent', 'cc' => ''],
            fields: [
                'locality'    => ['node' => 'city'],
                'countryCode' => ['node' => 'cc'],
            ],
        ));

        $this->assertSame(['new1' => ['locality' => 'Ghent']], $parsed);
    }

    /**
     * Craft would happily assign any of its 17 native address properties, but an
     * Address only EXPOSES the ones its layout includes — a stock layout shows
     * four of them. Offering a property no editor can see or correct is the same
     * mistake as offering `title` for an entry type that hides it.
     */
    public function testOnlyTheNativesTheLayoutExposesAreOffered(): void
    {
        $rows = $this->slotHandles(new AddressesWithStubbedLayout());

        $this->assertContains('title', $rows, 'LabelField is in the layout, so its Label row is.');
        $this->assertContains('countryCode', $rows);
        $this->assertContains('locality', $rows, 'AddressField stands for the whole address block.');
        $this->assertContains('postalCode', $rows);

        $this->assertNotContains('fullName', $rows, 'No FullNameField in the layout.');
        $this->assertNotContains('organization', $rows);
        $this->assertNotContains('organizationTaxId', $rows);
        $this->assertNotContains('latitude', $rows);
        $this->assertNotContains('longitude', $rows);
    }

    /**
     * The card reads down the same sequence the address editor does, so an
     * operator maps top-to-bottom against the screen in front of them. Within the
     * address block that's Craft's own render order
     * ({@see \craft\helpers\Cp::addressFieldsHtml()}) — administrative area before
     * locality — which is NOT the order its deserializer lists them in.
     */
    public function testRowsFollowTheEditorsOrder(): void
    {
        $this->assertSame([
            'title',
            'countryCode',
            'addressLine1',
            'addressLine2',
            'addressLine3',
            'administrativeArea',
            'locality',
            'dependentLocality',
            'postalCode',
            'sortingCode',
            'notes',
        ], $this->slotHandles(new AddressesWithStubbedLayout()));
    }

    /**
     * The layout walk itself, against Craft's real layout elements rather than a
     * stubbed attribute list.
     *
     * The regression: the walk filtered on `BaseNativeField`, and the element
     * standing for the whole address block extends `BaseField` directly — it isn't
     * one input for one property. So every address line, state, city and
     * postal-code row silently vanished and the card held only Label and Country.
     * Stubbing the walk is what let it through, hence this spec goes through it.
     */
    public function testTheLayoutWalkReadsCompositeElementsToo(): void
    {
        // Real layout elements — the point is which base class each extends — in a
        // mocked container, because building a tab wants a booted Craft.
        $tab = $this->createMock(FieldLayoutTab::class);
        $tab->method('getElements')->willReturn([
            new LabelField(),
            new CountryCodeField(),
            new AddressField(),
        ]);

        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getTabs')->willReturn([$tab]);

        $this->assertSame(
            ['title', 'countryCode', 'address'],
            (new AddressesWithRealLayout($layout))->attributesForSpec(),
            'The address block is one element standing for eight properties; dropping it empties the card.',
        );
    }

    /** A site that rearranges its layout rearranges the card with it. */
    public function testARearrangedLayoutRearrangesTheCard(): void
    {
        $rows = $this->slotHandles(new AddressesWithStubbedLayout(['address', 'countryCode', 'title']));

        $this->assertSame(['addressLine1', 'countryCode', 'title'], [
            $rows[0],
            $rows[8],
            $rows[9],
        ]);
    }

    public function testTheOptionalElementsBringTheirOwnSlots(): void
    {
        $rows = $this->slotHandles(new AddressesWithStubbedLayout(['title', 'organization', 'latLong']));

        $this->assertContains('organization', $rows);
        $this->assertContains('latitude', $rows, 'One LatLong element stands for two properties.');
        $this->assertContains('longitude', $rows);
    }

    /**
     * Craft renders Full Name as one input or as a First/Last pair depending on
     * `showFirstAndLastNameFields`, and validates on the same setting — so only
     * one of the two shapes may be offered.
     */
    public function testAFullNameElementOffersWhicheverShapeTheCpRenders(): void
    {
        $whole = $this->slotHandles(new AddressesWithStubbedLayout(['fullName'], ['fullName']));
        $this->assertContains('fullName', $whole);
        $this->assertNotContains('firstName', $whole);

        $split = $this->slotHandles(
            new AddressesWithStubbedLayout(['fullName'], ['firstName', 'lastName']),
        );
        $this->assertContains('firstName', $split);
        $this->assertContains('lastName', $split);
        $this->assertNotContains('fullName', $split);
    }

    public function testANativeTheLayoutHidesIsNotWrittenEither(): void
    {
        $parsed = (new AddressesWithStubbedLayout())->parse($this->context(
            feed: ['city' => 'Ghent', 'lat' => '51.05'],
            fields: [
                'locality' => ['node' => 'city'],
                'latitude' => ['node' => 'lat'],
            ],
        ));

        $this->assertSame(
            ['new1' => ['locality' => 'Ghent']],
            $parsed,
            'A mapping that outlived its layout element is skipped, not written.',
        );
    }

    public function testAHandleTheLayoutNoLongerDeclaresIsSkipped(): void
    {
        $parsed = (new AddressesWithStubbedLayout())->parse($this->context(
            feed: ['city' => 'Ghent', 'gone' => 'x'],
            fields: [
                'locality'   => ['node' => 'city'],
                'removed_it' => ['node' => 'gone'],
            ],
        ));

        $this->assertSame(['new1' => ['locality' => 'Ghent']], $parsed);
    }

    public function testNothingAddressedClearsTheField(): void
    {
        $parsed = (new AddressesWithStubbedLayout())->parse($this->context(
            feed: [],
            fields: ['locality' => ['node' => 'city']],
        ));

        $this->assertSame([], $parsed);
    }

    public function testTheRowIsAddressedThroughItsSubMappings(): void
    {
        $strategy = new AddressesWithStubbedLayout();

        $this->assertTrue($strategy->addressed($this->context(
            feed: ['city' => 'Ghent'],
            fields: ['locality' => ['node' => 'city']],
        )));

        $this->assertFalse($strategy->addressed($this->context(
            feed: [],
            fields: ['locality' => ['node' => 'city']],
        )));
    }

    public function testADifferentAddressCountIsAChange(): void
    {
        $strategy = new AddressesWithStubbedLayout();
        $incoming = ['new1' => ['locality' => 'Ghent'], 'new2' => ['locality' => 'Bruges']];

        $this->assertTrue($strategy->hasChanged(
            $this->context([], [], current: [$this->address(['locality' => 'Ghent'])]),
            $incoming,
        ));
    }

    public function testAnUnchangedAddressListIsNotAChange(): void
    {
        $strategy = new AddressesWithStubbedLayout();
        $incoming = ['new1' => ['locality' => 'Ghent', 'countryCode' => 'BE']];

        $this->assertFalse($strategy->hasChanged(
            $this->context([], [], current: [$this->address(['locality' => 'Ghent', 'countryCode' => 'BE'])]),
            $incoming,
        ));
    }

    public function testAChangedNativeIsAChange(): void
    {
        $strategy = new AddressesWithStubbedLayout();
        $incoming = ['new1' => ['locality' => 'Bruges', 'countryCode' => 'BE']];

        $this->assertTrue($strategy->hasChanged(
            $this->context([], [], current: [$this->address(['locality' => 'Ghent', 'countryCode' => 'BE'])]),
            $incoming,
        ));
    }

    /**
     * A slot the feed doesn't address is not this mapping's business — counting
     * it would make an untouched address look changed on its own.
     */
    public function testAnUnmappedSlotDoesNotCountTowardsTheComparison(): void
    {
        $strategy = new AddressesWithStubbedLayout();
        $incoming = ['new1' => ['locality' => 'Ghent']];

        $this->assertFalse($strategy->hasChanged(
            $this->context([], [], current: [$this->address(['locality' => 'Ghent', 'postalCode' => '9000'])]),
            $incoming,
        ));
    }

    /**
     * The sub-field row handles the schema's one card declares.
     *
     * @return list<string>
     */
    private function slotHandles(Addresses $strategy): array
    {
        $card = $strategy->schema($this->createMock(CraftFieldInterface::class))->toArray()['extra'][0] ?? [];

        return array_column($card['subFields'] ?? [], 'handle');
    }

    private function address(array $natives): CraftAddressElement
    {
        $address = $this->createMock(CraftAddressElement::class);

        foreach ($natives as $handle => $value) {
            $address->$handle = $value;
        }

        return $address;
    }

    private function context(array $feed, array $fields, mixed $current = null): FieldContext
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);

        return new FieldContext(
            craftField: null,
            handle: 'locations',
            mapping: FieldMapping::fromConfig('locations', ['fields' => $fields]),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $element,
            // The one custom child here is a plain-text field, so the leaf
            // strategy is the shared fallback — what a booted plugin resolves.
            strategyResolver: static fn(CraftFieldInterface $childField): Field => new DefaultField(),
        );
    }
}

/**
 * @internal The real layout comes off Craft's address service, which needs a
 * booted app. The default here mirrors what a stock Address layout exposes —
 * Label, Country and the address block — plus one custom field, which is enough
 * to spec the two-channel split.
 */
class AddressesWithRealLayout extends Addresses
{
    protected FieldLayout $layout;

    public function __construct(FieldLayout $layout)
    {
        $this->layout = $layout;
    }

    /** @return list<string> */
    public function attributesForSpec(): array
    {
        return $this->layoutAttributes();
    }

    protected function addressLayout(): ?FieldLayout
    {
        return $this->layout;
    }
}

/**
 * @internal Stubs the attribute walk itself, so the rest of the specs don't need a
 * real layout. {@see AddressesWithRealLayout} covers the walk.
 */
class AddressesWithStubbedLayout extends Addresses
{
    /**
     * Native layout attributes, in the order the stubbed layout puts them.
     *
     * @var list<string>
     */
    protected array $attributes;

    /** @var list<string> */
    protected array $names;

    /**
     * Defaults to what a stock Address layout exposes, in the order the editor
     * renders it: the mandatory Label, the country selector, the address block.
     */
    public function __construct(
        ?array $attributes = null,
        array $names = ['fullName'],
    ) {
        $this->attributes = $attributes ?? ['title', 'countryCode', 'address'];
        $this->names = $names;
    }

    protected function layoutAttributes(): array
    {
        return $this->attributes;
    }

    /** The real one reads `showFirstAndLastNameFields` off the general config. */
    protected function nameSlots(): array
    {
        return $this->names;
    }

    /** The real one reads country-aware labels off an Address element. */
    protected function nativeLabel(string $handle): string
    {
        return $handle;
    }

    protected function layoutFields(): array
    {
        $notes = new PlainText();
        $notes->handle = 'notes';
        $notes->name = 'Notes';

        return [$notes];
    }

    /** The real seam asks the plugin's registry, which wants a booted plugin. */
    protected function childRowFor(CraftFieldInterface $craftField): array
    {
        return ['default' => ['type' => 'text'], 'extra' => []];
    }
}
