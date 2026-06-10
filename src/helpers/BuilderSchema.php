<?php

namespace TDM\Influx\helpers;

/**
 * Factory helpers for the declarative form-node vocabulary the LinkBuilder
 * SPA renders generically (see `schema/SchemaForm.vue`). A field strategy
 * declares its mapping-extras UI as a list of these nodes from
 * {@see \TDM\Influx\fields\Field::defineExtrasSchema()} — the Vue side has
 * no per-field-type branches, it just renders by node type.
 *
 * Node shape:
 *   type:         text | select | lightswitch | csvText | valueMapTable |
 *                 subFieldMapTable | note
 *   handle:       key inside the mapping's `options` (or `nativeFields` for
 *                 subFieldMapTable, which writes the recursive channel)
 *   label:        translated row label
 *   instructions: translated hint shown under the control (optional)
 *   placeholder:  input placeholder (optional)
 *   default:      initial value when nothing is saved yet (optional)
 *   options:      for select — flat [{value,label}] or grouped
 *                 [{label, options: [{value,label}]}]
 *   localOptions: for valueMapTable — value → label map of the Craft field's
 *                 own options
 *   subFields:    for subFieldMapTable — handle → label rows
 *   showIf:       list of {handle, equals?} conditions against the current
 *                 options; omitted `equals` means truthy. All must match.
 *
 * Loosely modeled on Formie's SchemaHelper, deliberately tiny: ~7 node
 * types instead of 40, one-key conditions instead of an expression language.
 */
class BuilderSchema
{
    /**
     * @param array{instructions?: string, placeholder?: string, default?: mixed, showIf?: array} $config
     */
    public static function text(string $handle, string $label, array $config = []): array
    {
        return self::node('text', $handle, $label, $config);
    }

    /**
     * @param array $options Flat [{value,label}] or grouped [{label, options}] lists.
     * @param array{instructions?: string, default?: mixed, showIf?: array} $config
     */
    public static function select(string $handle, string $label, array $options, array $config = []): array
    {
        return self::node('select', $handle, $label, ['options' => $options] + $config);
    }

    /**
     * @param array{instructions?: string, default?: bool, showIf?: array} $config
     */
    public static function lightswitch(string $handle, string $label, array $config = []): array
    {
        return self::node('lightswitch', $handle, $label, $config);
    }

    /**
     * Comma-separated text input that round-trips a string list option.
     *
     * @param array{instructions?: string, placeholder?: string, default?: array, showIf?: array} $config
     */
    public static function csvText(string $handle, string $label, array $config = []): array
    {
        return self::node('csvText', $handle, $label, $config);
    }

    /**
     * Remote value → local option rewrite table.
     *
     * @param array<string, string> $localOptions value → label of the Craft field's options
     * @param array{instructions?: string, showIf?: array} $config
     */
    public static function valueMapTable(string $handle, string $label, array $localOptions, array $config = []): array
    {
        return self::node('valueMapTable', $handle, $label, ['localOptions' => $localOptions] + $config);
    }

    /**
     * Node + default rows for a related element's native sub-fields
     * (asset alt/title). Writes the mapping's `nativeFields` channel, not
     * `options`.
     *
     * @param array<string, string> $subFields handle → label
     * @param array{instructions?: string, showIf?: array} $config
     */
    public static function subFieldMapTable(string $label, array $subFields, array $config = []): array
    {
        return self::node('subFieldMapTable', 'nativeFields', $label, ['subFields' => $subFields] + $config);
    }

    /**
     * Static explanatory text — for placeholders like the Matrix stub.
     */
    public static function note(string $text): array
    {
        return ['type' => 'note', 'handle' => '', 'label' => '', 'text' => $text];
    }

    protected static function node(string $type, string $handle, string $label, array $config): array
    {
        return array_filter(
            ['type' => $type, 'handle' => $handle, 'label' => $label] + $config,
            static fn($value) => $value !== null,
        );
    }
}
