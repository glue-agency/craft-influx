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
     * @var array<string, list<array>>
     */
    public array $mapping = [];

    protected function __construct(
        string $handle,
        string $name,
        bool $native,
        string $group,
        ?string $fieldClass,
        array $mapping,
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
     * @param array<string, list<array>> $mapping
     */
    public static function native(string $handle, string $name, string $group, array $mapping = []): self
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
     * @param array<string, list<array>> $mapping
     */
    public static function custom(
        string $handle,
        string $name,
        string $group,
        string $fieldClass,
        array $mapping = [],
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

        $descriptor['mapping'] = $this->mapping;

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
