<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\elements\Address as CraftAddressElement;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\fieldlayoutelements\BaseField;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Addresses as CraftAddressesField;
use craft\models\FieldLayout;
use GlueAgency\Influx\helpers\Comparable;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use Throwable;

/**
 * Mapping strategy for Craft 5's Addresses field: a fan-out of nested
 * {@see CraftAddressElement} records over one shared layout. It sits between
 * {@see Matrix} (which fans out over several block types) and
 * {@see ContentBlock} (one record, one layout): many records, but only ever the
 * one implicit type, so there are no block-type trees — just one set of slots,
 * index-zipped into as many addresses as the feed carries.
 *
 * On the DefaultField fallback the field was unusable. An address is a nested
 * ELEMENT, and `setFieldValue()` with anything but the serialized shape Craft's
 * own {@see \craft\fields\Addresses::normalizeValue()} reads leaves the query
 * untouched — so a mapping saved cleanly and stored nothing, the same silent
 * no-op a ContentBlock had before it got a strategy. {@see parse()} builds that
 * shape here so the feed never has to carry it.
 *
 * Two channels, one list of rows. Craft reads an address's NATIVE properties off
 * the top level of each record and its custom fields out of a nested `fields`
 * key ({@see \craft\fields\Addresses::normalizeValue()}), so each row declares
 * which of the two it lands in — {@see NATIVE_FIELDS} is the split, and it is
 * Craft's own list verbatim. A custom field whose handle collides with a native
 * one is unreachable, which matches Craft: it assigns the natives first.
 *
 * `countryCode` is the one required property on an Address, and it's deliberately
 * NOT defaulted here — {@see \craft\elements\Address::init()} already falls back
 * to the `defaultCountryCode` general-config setting, so an unmapped or empty
 * country lands on the site's own default rather than one this strategy invented.
 * It's skipped rather than written empty for exactly that reason.
 *
 * Sync semantics are full-replace, like {@see Matrix} and {@see Table}: every run
 * rebuilds the whole address list from the feed. {@see valueDiffers()} compares a
 * mapped-slots-only fingerprint so an unchanged feed never triggers that rebuild.
 *
 * v1 boundary: no per-address drill-down in the inspectors. {@see Matrix} and
 * {@see Table} implement {@see collectChildren()} with a pairing pass that
 * decides what happened to each record; an address row reports as one value until
 * that's built for it.
 */
class Addresses extends Field
{
    /**
     * The address properties Craft assigns off the top level of a serialized
     * record — its own `$nativeFields` list. This decides only the CHANNEL a
     * handle writes to: anything not in here is a custom field and goes in the
     * `fields` envelope. Neither the offered set nor the row order comes from
     * here — both are the layout's ({@see nativeSlots()}).
     */
    protected const NATIVE_FIELDS = [
        'title',
        'fullName',
        'firstName',
        'lastName',
        'countryCode',
        'administrativeArea',
        'locality',
        'dependentLocality',
        'postalCode',
        'sortingCode',
        'addressLine1',
        'addressLine2',
        'addressLine3',
        'organization',
        'organizationTaxId',
        'latitude',
        'longitude',
    ];

    /**
     * Which properties each native layout ATTRIBUTE stands for, keyed the way
     * Craft's layout elements name themselves — the same attribute vocabulary
     * {@see Assets::nativeSubFields()} probes a volume's alt and title with.
     *
     * Most are one input, one property; two are composites, which is why this
     * can't just be a list of property names: Craft renders the whole
     * country-specific address block from a single element whose attribute is
     * `address`, and latitude + longitude from one whose attribute is `latLong`.
     *
     * `fullName` is absent on purpose: its element renders EITHER a full-name
     * input OR first/last ones depending on a general-config setting, so it's
     * resolved per request in {@see nameSlots()}.
     */
    protected const SLOTS_BY_LAYOUT_ATTRIBUTE = [
        'title'       => ['title'],
        'countryCode' => ['countryCode'],
        // In the order Craft's own address block renders them
        // ({@see \craft\helpers\Cp::addressFieldsHtml()}), which is not the order
        // its deserializer lists them in.
        'address' => [
            'addressLine1',
            'addressLine2',
            'addressLine3',
            'administrativeArea',
            'locality',
            'dependentLocality',
            'postalCode',
            'sortingCode',
        ],
        'organization'      => ['organization'],
        'organizationTaxId' => ['organizationTaxId'],
        'latLong'           => ['latitude', 'longitude'],
    ];

    /** The layout attribute standing for whichever name shape the CP renders. */
    protected const FULL_NAME_ATTRIBUTE = 'fullName';

    /** Required on the element, so an empty one is skipped rather than written. */
    protected const COUNTRY_CODE = 'countryCode';

    /** The nested key Craft reads an address's custom-field values out of. */
    protected const FIELDS_KEY = 'fields';

    /**
     * Throwaway Address the row labels are read off, resolved once per request.
     *
     * @see labelCarrier()
     */
    protected ?CraftAddressElement $labelCarrier = null;

    /**
     * An Addresses field relates Address elements, so like every relation it stores ids of OTHER elements.
     * See {@see Field::matchable()}.
     */
    public static function matchable(): bool
    {
        return false;
    }

    public static function craftFieldClass(): ?string
    {
        return CraftAddressesField::class;
    }

    /**
     * One card mirroring the address editor: the natives this site's layout
     * exposes, in the order that layout puts them, then its custom fields — each
     * with the default editor its own field's strategy declares, the way
     * {@see ContentBlock::schema()} builds its rows.
     *
     * Craft's deserializer would happily assign any of the 17 native properties,
     * but an Address only exposes the ones its layout includes — no site shows
     * all of them. Offering a property no editor can see or correct is the same
     * mistake as offering `title` for an entry type that hides it, which the
     * targets already avoid.
     */
    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            // The value derives entirely from the sub-mappings below, so the row
            // renders neither cell of its own — absence is the whole declaration.
            'source'  => false,
            'default' => false,
            'extra'   => function(MappingSchemaBuilder $b) use ($field) {
                $subFields = MappingSchemaBuilder::make();

                foreach ($this->nativeSlots() as $handle) {
                    $subFields->text([
                        'handle' => $handle,
                        'label'  => $this->nativeLabel($handle),
                    ]);
                }

                foreach ($this->layoutFieldsByHandle() as $childField) {
                    $subFields->fieldRow($this->childRowFor($childField), [
                        'handle' => $childField->handle,
                        'label'  => $childField->name,
                    ]);
                }

                return $b->subFields([
                    'label'     => Craft::t('influx', 'Address fields'),
                    'subFields' => $subFields->toArray(),
                ]);
            },
        ]);
    }


    /**
     * A node-less row is addressed through its sub-mappings, never its own
     * (absent) node — so it's addressed when ANY active one is addressed for this
     * item. An address row whose slots are all unaddressed leaves the field alone.
     */
    public function addressed(FieldContext $context): bool
    {
        foreach ($this->activeSubMappings($context->mapping) as $sub) {
            if ($sub->addressedBy($context->item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the serialized record set Craft consumes, index-zipping the mapped
     * slots' value lists the same way {@see Table} zips its columns: address N
     * takes the Nth value of every slot.
     *
     * Keys are `new1`, `new2`, … — the "this is a new record" form Craft's
     * deserializer reads. Reusing the existing addresses' ids would be the
     * merge-in-place behaviour {@see Matrix} also doesn't have yet; a full
     * replace is honest about what it does.
     *
     * An empty result is returned as an explicit clear rather than null:
     * {@see addressed()} was true, so the feed is authoritative even when every
     * slot resolved to nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    public function parse(FieldContext $context): mixed
    {
        $customFields = $this->layoutFieldsByHandle();
        $lists = [];
        $subs = [];

        foreach ($this->activeSubMappings($context->mapping) as $sub) {
            if (! $this->isMappableSlot($sub->handle, $customFields)) {
                continue;
            }

            $resolved = $sub->resolve($context->item);
            $lists[$sub->handle] = $resolved === null ? [] : $this->valueList($resolved);
            $subs[$sub->handle] = $sub;
        }

        $count = $this->maxLength(array_values($lists));

        if ($count === 0) {
            return [];
        }

        $addresses = [];

        for ($i = 0; $i < $count; $i++) {
            $record = [];
            $custom = [];

            foreach ($lists as $handle => $values) {
                $value = $values[$i] ?? null;

                if (isset($customFields[$handle])) {
                    $custom[$handle] = $this->coerceChildValue(
                        $context,
                        $context->element,
                        $subs[$handle],
                        $customFields[$handle],
                        $value,
                    );

                    continue;
                }

                if ($handle === self::COUNTRY_CODE && $this->isBlank($value)) {
                    continue;
                }

                $record[$handle] = $value;
            }

            if ($custom !== []) {
                $record[self::FIELDS_KEY] = $custom;
            }

            $addresses['new' . ($i + 1)] = $record;
        }

        return $addresses;
    }

    /**
     * Full-replace fingerprint comparison over the mapped slots only, so an
     * unchanged feed never triggers a destructive rebuild — and so a slot the
     * feed doesn't address (which the replace writes empty) can't make every sync
     * look like a change on its own.
     *
     * Order is part of the comparison: a full replace writes the feed's order, so
     * two lists holding the same addresses in a different order genuinely differ.
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        $records = is_array($incoming) ? array_values($incoming) : [];
        $stored = $this->storedAddresses($current);

        if (count($records) !== count($stored)) {
            return true;
        }

        $customFields = $this->layoutFieldsByHandle();

        foreach ($records as $index => $record) {
            $incomingPrint = $this->recordPrint($context, is_array($record) ? $record : [], $customFields);
            $storedPrint = $this->addressPrint($context, $stored[$index], array_keys($incomingPrint), $customFields);

            if ($incomingPrint !== $storedPrint) {
                return true;
            }
        }

        return false;
    }

    /**
     * One incoming record reduced to its comparable slots, each leaf through the
     * strategy that owns it — the same leaf-normalisation rule
     * {@see ContentBlock::valueDiffers()} applies.
     *
     * @param array<string, mixed> $record
     * @param array<string, CraftFieldInterface> $customFields
     * @return array<string, mixed>
     */
    protected function recordPrint(FieldContext $context, array $record, array $customFields): array
    {
        $print = [];

        foreach ($record as $handle => $value) {
            if ($handle === self::FIELDS_KEY) {
                continue;
            }

            $print[$handle] = Comparable::of($value);
        }

        foreach ($record[self::FIELDS_KEY] ?? [] as $handle => $value) {
            $print[$handle] = $this->leafPrint($context, $handle, $value, $customFields);
        }

        ksort($print);

        return $print;
    }

    /**
     * One stored address reduced over the same slots the incoming record covers.
     * A native reads straight off the element; a custom field is read the way
     * Craft serializes it, so both sides of a leaf meet in the same shape.
     *
     * @param list<string> $handles
     * @param array<string, CraftFieldInterface> $customFields
     * @return array<string, mixed>
     */
    protected function addressPrint(
        FieldContext $context,
        CraftAddressElement $address,
        array $handles,
        array $customFields,
    ): array {
        $print = [];

        foreach ($handles as $handle) {
            if (isset($customFields[$handle])) {
                $stored = $address->getSerializedFieldValues([$handle])[$handle] ?? null;
                $print[$handle] = $this->leafPrint($context, $handle, $stored, $customFields);

                continue;
            }

            // A typed native an Address hasn't initialised yet (countryCode on a
            // half-built element) throws on read rather than yielding null.
            $print[$handle] = Comparable::of(isset($address->$handle) ? $address->$handle : null);
        }

        ksort($print);

        return $print;
    }

    /**
     * One custom leaf's comparable form, through the owning field's own strategy
     * where there is one and the shared normaliser otherwise.
     *
     * @param array<string, CraftFieldInterface> $customFields
     */
    protected function leafPrint(FieldContext $context, string $handle, mixed $value, array $customFields): mixed
    {
        $childField = $customFields[$handle] ?? null;

        if ($childField === null) {
            return Comparable::of($value);
        }

        return $context->strategyFor($childField)->normalize($value);
    }

    /**
     * The stored addresses as a plain list. The field's value is an
     * {@see ElementQueryInterface} normally and an {@see ElementCollection} once
     * something has eager-loaded it; anything else means nothing is nested yet.
     *
     * @return list<CraftAddressElement>
     */
    protected function storedAddresses(mixed $current): array
    {
        if ($current instanceof ElementQueryInterface || $current instanceof ElementCollection) {
            $current = $current->all();
        }

        if (! is_array($current)) {
            return [];
        }

        return array_values(array_filter(
            $current,
            fn(mixed $item): bool => $item instanceof CraftAddressElement,
        ));
    }

    /**
     * Whether a stored sub-mapping handle still names a slot. A handle the layout
     * no longer declares is skipped silently, the way {@see Table} skips a removed
     * column: the mapping outlived the field, which is not a structural error.
     *
     * @param array<string, CraftFieldInterface> $customFields
     */
    protected function isMappableSlot(string $handle, array $customFields): bool
    {
        return in_array($handle, $this->nativeSlots(), true) || isset($customFields[$handle]);
    }

    /**
     * The native properties this site's Address layout exposes, IN LAYOUT ORDER —
     * so the card reads down the same sequence the address editor does, including
     * when a site has rearranged its layout. A layout element the site removed
     * takes its properties with it.
     *
     * @return list<string>
     */
    protected function nativeSlots(): array
    {
        $exposed = [];

        foreach ($this->layoutAttributes() as $attribute) {
            if ($attribute === self::FULL_NAME_ATTRIBUTE) {
                $exposed = array_merge($exposed, $this->nameSlots());

                continue;
            }

            $exposed = array_merge($exposed, self::SLOTS_BY_LAYOUT_ATTRIBUTE[$attribute] ?? []);
        }

        return $exposed;
    }

    /**
     * What a Full Name element stands for: Craft renders it as one input or as
     * a First/Last pair depending on `showFirstAndLastNameFields`, and its own
     * validation branches on the same setting. Offering all three would put two
     * rows in the card that the CP never shows.
     *
     * @return list<string>
     */
    protected function nameSlots(): array
    {
        return Craft::$app->getConfig()->getGeneral()->showFirstAndLastNameFields
            ? ['firstName', 'lastName']
            : ['fullName'];
    }


    protected function isBlank(mixed $value): bool
    {
        return $value === null || (is_scalar($value) && trim((string) $value) === '');
    }

    /**
     * The Address layout's own custom fields. Every Addresses field on a site
     * shares ONE layout — Craft's Addresses service is the layout provider
     * ({@see \craft\fields\Addresses::getFieldLayoutProviders()}) — so unlike a
     * Matrix or ContentBlock layout it doesn't depend on which field is asking.
     *
     * @return list<CraftFieldInterface>
     */
    protected function layoutFields(): array
    {
        $layout = $this->addressLayout();

        return $layout !== null ? array_values($layout->getCustomFields()) : [];
    }

    /**
     * @return array<string, CraftFieldInterface>
     */
    protected function layoutFieldsByHandle(): array
    {
        $byHandle = [];

        foreach ($this->layoutFields() as $childField) {
            if (in_array($childField->handle, self::NATIVE_FIELDS, true)) {
                continue;
            }

            $byHandle[$childField->handle] = $childField;
        }

        return $byHandle;
    }

    /**
     * The native attributes the Address layout carries, in layout order. THE seam
     * the slot list is built on, extracted so a spec can declare a layout without
     * booting Craft — the way {@see ContentBlock::blockLayout()} is.
     *
     * Read off {@see BaseField}, NOT `BaseNativeField`: the element standing for
     * the whole address block extends the former directly, because it isn't one
     * input for one property. Filtering on the narrower class silently dropped
     * every address line, state, city and postal-code row and left a card holding
     * only Label and Country. {@see CustomField} is excluded instead — its
     * `attribute()` is a field handle, which belongs to the other channel.
     *
     * The read is guarded because `attribute()` throws when the field behind an
     * element is gone; Craft's own layout code guards the same call.
     *
     * @return list<string>
     */
    protected function layoutAttributes(): array
    {
        $layout = $this->addressLayout();

        if ($layout === null) {
            return [];
        }

        $attributes = [];

        foreach ($layout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if (! $element instanceof BaseField || $element instanceof CustomField) {
                    continue;
                }

                try {
                    $attributes[] = $element->attribute();
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return $attributes;
    }

    protected function addressLayout(): ?FieldLayout
    {
        return Craft::$app->getAddresses()->getFieldLayout();
    }

    /**
     * The label Craft's own address editor puts on this property, so the mapping
     * card reads exactly like the screen an operator is mapping onto.
     *
     * Most of them come off an Address element, which is where Craft resolves them
     * ({@see \craft\elements\Address::getAttributeLabel()}) and which makes them
     * COUNTRY-AWARE: an `administrativeArea` is a State in the US and a Province
     * in Canada, a `postalCode` a Zip Code or a Postcode. The carrier is a
     * throwaway address on the site's default country — the same country a new
     * address gets, and the only one a per-field mapping form could be built for.
     *
     * `countryCode` is the exception: it isn't an address-format field, so the
     * element would fall back to Yii's generated "Country Code" where the editor
     * shows "Country" ({@see \craft\fieldlayoutelements\addresses\CountryCodeField}).
     */
    protected function nativeLabel(string $handle): string
    {
        if ($handle === self::COUNTRY_CODE) {
            return Craft::t('app', 'Country');
        }

        return $this->labelCarrier()?->getAttributeLabel($handle) ?? $handle;
    }

    /**
     * A throwaway Address to read labels off, memoized for the request: a schema
     * build asks for one label per row, and the answer can't change between them.
     */
    protected function labelCarrier(): ?CraftAddressElement
    {
        if ($this->labelCarrier === null) {
            $this->labelCarrier = new CraftAddressElement();
        }

        return $this->labelCarrier;
    }

    /**
     * @return list<FieldMapping>
     */
    protected function activeSubMappings(FieldMapping $mapping): array
    {
        return $this->filterActive($mapping->subMappings());
    }
}
