<?php

namespace GlueAgency\Influx\schema;

/**
 * One field a link can map onto — the descriptor the builder's Mapping tab
 * renders a row from, and THE owner of that wire contract (which used to live
 * in prose on {@see \GlueAgency\Influx\targets\ElementTargetInterface::getMappableFields()}).
 *
 * Two producers, one shape: {@see MappingSchemaBuilder::group()} declares an element
 * target's natives, {@see \GlueAgency\Influx\targets\AbstractElementTarget::customFieldDescriptors()}
 * walks a field layout's custom fields. Consumers
 * ({@see \GlueAgency\Influx\services\LinkBuilderService},
 * {@see \GlueAgency\Influx\services\LinksService},
 * {@see \GlueAgency\Influx\web\ItemRowPresenter}) read the typed properties;
 * only the wire boundary serializes, via {@see toArray()}.
 *
 * Bundled with {@see SchemaBuilder} rather than filed under models/ or helpers/:
 * the builder is the plugin's form-declaration vocabulary and this is the
 * descriptor it emits — one contract, one namespace.
 *
 * Treat instances as read-only — they describe a field surface, they don't
 * mutate it. The emitted array shape is pinned from both sides against
 * `src/web/assets/cp/tests/fixtures/mappable-field.json`
 * ({@see \GlueAgency\Influx\Tests\unit\schema\MappableFieldPayloadTest} plus the
 * SPA's `builder/__tests__/mappable-field.contract.test.js`).
 */
class MappableField
{
    /** The element field/attribute handle a mapping writes to. */
    public string $handle = '';

    /** Friendly name — the field's label in the mapping row. */
    public string $name = '';

    /** Whether this is a native element attribute rather than a custom field. */
    public bool $native = false;

    /** Group heading: the field-layout tab name, or the target's native group. */
    public string $group = '';

    /** FQCN of the Craft field class; null (and absent) for natives. */
    public ?string $fieldClass = null;

    /**
     * THE row's whole UI, as the regions its field's strategy declared
     * ({@see \GlueAgency\Influx\fields\Field::schema()}): one key per region the
     * row renders, each a list of nodes the SPA dispatches by `type`.
     *
     * An absent region is an absent cell, which is the whole reason this is all
     * there is. It replaced a `defaultType` / `options` / `elementType` /
     * `defaultLazy` descriptor beside a `fieldMeta` envelope carrying
     * `subfieldsOnly` and `unmappable` flags — six keys saying by convention what
     * this says by structure.
     *
     * Held UNRESOLVED wherever the producer can manage it: {@see MappingSchema}
     * builds each region behind a closure, and forcing all three per field is
     * what made a link save pay for the entire layout's schema — nested entry
     * types, a relation's allowed sources, every volume an Assets field lists —
     * when the prune only needs the handles the link stores mappings for.
     *
     * Reach it through {@see getMapping()}, never directly; hence protected while
     * the identity fields stay public. A straggler reading the raw property would
     * see a MappingSchema where an array was expected, and
     * {@see MappingSlots::prune()} reads an unusable shape as "can't judge this
     * row" — silently disabling the slot prune instead of failing.
     *
     * @var array<string, list<array>>|MappingSchema
     */
    protected array|MappingSchema $mapping = [];

    protected function __construct(
        string $handle,
        string $name,
        bool $native,
        string $group,
        ?string $fieldClass,
        array|MappingSchema $mapping,
    ) {
        $this->handle = $handle;
        $this->name = $name;
        $this->native = $native;
        $this->group = $group;
        $this->fieldClass = $fieldClass;
        $this->mapping = $mapping;
    }

    /**
     * A native element attribute (title, slug, enabled, author, ...). Built by
     * {@see MappingSchemaBuilder::group()} from the group's declared nodes.
     *
     * @param array<string, list<array>>|MappingSchema $mapping
     */
    public static function native(string $handle, string $name, string $group, array|MappingSchema $mapping = []): self
    {
        return new self(
            handle: $handle,
            name: $name,
            native: true,
            group: $group,
            fieldClass: null,
            mapping: $mapping,
        );
    }

    /**
     * A custom field on an element's field layout. Its kind reaches the SPA as
     * `fieldClass` — which is also what marks a descriptor custom, and so what
     * decides whether the row sends its handle to the server-rendered pickers —
     * while everything the row RENDERS is in the regions.
     *
     * @param array<string, list<array>>|MappingSchema $mapping
     */
    public static function custom(
        string $handle,
        string $name,
        string $group,
        string $fieldClass,
        array|MappingSchema $mapping = [],
    ): self {
        return new self(
            handle: $handle,
            name: $name,
            native: false,
            group: $group,
            fieldClass: $fieldClass,
            mapping: $mapping,
        );
    }

    /**
     * The row's regions, resolving a declared {@see MappingSchema} on first ask
     * and keeping the result — the same in-place memoization
     * {@see MappingSchema::$resolved} does, and the one thing on an otherwise
     * read-only descriptor that changes after construction.
     *
     * @return array<string, list<array>>
     */
    public function getMapping(): array
    {
        if ($this->mapping instanceof MappingSchema) {
            $this->mapping = $this->mapping->toArray();
        }

        return $this->mapping;
    }

    /**
     * The JSON wire shape the SPA consumes: the four keys every descriptor has,
     * `fieldClass` where there is one, and the row itself last.
     */
    public function toArray(): array
    {
        $descriptor = [
            'handle' => $this->handle,
            'name'   => $this->name,
            'native' => $this->native,
            'group'  => $this->group,
        ];

        if ($this->fieldClass !== null) {
            $descriptor['fieldClass'] = $this->fieldClass;
        }

        $descriptor['mapping'] = $this->getMapping();

        return $descriptor;
    }

    /**
     * Serialize a whole reported field surface — the one call the wire boundary
     * makes.
     *
     * @param list<self> $fields
     * @return list<array>
     */
    public static function toArrays(array $fields): array
    {
        return array_map(static fn(self $field): array => $field->toArray(), $fields);
    }
}
