<?php

namespace GlueAgency\Influx\schema;

/**
 * One field a link can map onto — the descriptor the builder's Mapping tab
 * renders a row from, and THE owner of that wire contract (which used to live
 * in prose on {@see \GlueAgency\Influx\targets\ElementTargetInterface::getMappableFields()}).
 *
 * Two producers, one shape: {@see SchemaBuilder::group()} declares an element
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

    /**
     * Which default-value editor the row renders: {@see SchemaBuilder::TEXT},
     * {@see SchemaBuilder::SELECT} or {@see SchemaBuilder::ELEMENT}.
     */
    public string $defaultType = SchemaBuilder::TEXT;

    /**
     * For {@see SchemaBuilder::SELECT}: value => label. Null when the field's
     * default editor isn't a select — the key is then absent from the wire shape.
     *
     * @var array<string, string>|null
     */
    public ?array $options = null;

    /**
     * For {@see SchemaBuilder::ELEMENT}: FQCN of the element type to pick from.
     *
     * @var class-string|null
     */
    public ?string $elementType = null;

    /** FQCN of the Craft field class; null (and absent) for natives. */
    public ?string $fieldClass = null;

    /**
     * Opaque per-kind meta the typed-mapping UI / runtime needs (extras schema,
     * sub-fields, `subfieldsOnly`, ...), wrapped by {@see \GlueAgency\Influx\fields\Field::meta()}.
     * Null — and absent from the wire shape — for a native with no extras;
     * custom fields always carry it, even when empty.
     */
    public ?array $fieldMeta = null;

    protected function __construct(
        string $handle,
        string $name,
        bool $native,
        string $group,
        string $defaultType,
        ?array $options,
        ?string $elementType,
        ?string $fieldClass,
        ?array $fieldMeta,
    ) {
        $this->handle = $handle;
        $this->name = $name;
        $this->native = $native;
        $this->group = $group;
        $this->defaultType = $defaultType;
        $this->options = $options;
        $this->elementType = $elementType;
        $this->fieldClass = $fieldClass;
        $this->fieldMeta = $fieldMeta;
    }

    /**
     * A native element attribute (title, slug, enabled, author, ...). Built by
     * {@see SchemaBuilder::group()} from the group's declared nodes, so the
     * nullable arguments mirror the keys a node may or may not carry.
     *
     * @param array<string, string>|null $options
     * @param class-string|null $elementType
     */
    public static function native(
        string $handle,
        string $name,
        string $group,
        string $defaultType = SchemaBuilder::TEXT,
        ?array $options = null,
        ?string $elementType = null,
        ?array $fieldMeta = null,
    ): self {
        return new self(
            handle: $handle,
            name: $name,
            native: true,
            group: $group,
            defaultType: $defaultType,
            options: $options,
            elementType: $elementType,
            fieldClass: null,
            fieldMeta: $fieldMeta,
        );
    }

    /**
     * A custom field on an element's field layout. Its kind is expressed through
     * `fieldClass` + `fieldMeta`; the default editor is a plain text input unless
     * the field's strategy asks for something field-type aware
     * ({@see \GlueAgency\Influx\fields\Field::defaultEditor()}) — an Entries field
     * gets the same element picker a native author does, a Dropdown a select over
     * its own options.
     *
     * @param array<string, string>|null $options
     * @param class-string|null $elementType
     */
    public static function custom(
        string $handle,
        string $name,
        string $group,
        string $fieldClass,
        array $fieldMeta,
        string $defaultType = SchemaBuilder::TEXT,
        ?array $options = null,
        ?string $elementType = null,
    ): self {
        return new self(
            handle: $handle,
            name: $name,
            native: false,
            group: $group,
            defaultType: $defaultType,
            options: $options,
            elementType: $elementType,
            fieldClass: $fieldClass,
            fieldMeta: $fieldMeta,
        );
    }

    /**
     * The JSON wire shape the SPA consumes. The five always-present keys lead,
     * then each optional key — omitted while its property is null, so a native
     * without extras stays as terse on the wire as it reads here.
     */
    public function toArray(): array
    {
        $descriptor = [
            'handle'      => $this->handle,
            'name'        => $this->name,
            'native'      => $this->native,
            'group'       => $this->group,
            'defaultType' => $this->defaultType,
        ];

        if ($this->options !== null) {
            $descriptor['options'] = $this->options;
        }

        if ($this->elementType !== null) {
            $descriptor['elementType'] = $this->elementType;
        }

        if ($this->fieldClass !== null) {
            $descriptor['fieldClass'] = $this->fieldClass;
        }

        if ($this->fieldMeta !== null) {
            $descriptor['fieldMeta'] = $this->fieldMeta;
        }

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
