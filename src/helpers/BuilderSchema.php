<?php

namespace GlueAgency\Influx\helpers;

/**
 * Factory helpers for the declarative form-node vocabulary the LinkBuilder
 * SPA renders generically (see `schema/SchemaForm.vue`). This is the shared
 * contract for EVERY PHP-declared builder form:
 *
 *   - mapping extras, declared by field strategies via
 *     {@see \GlueAgency\Influx\fields\Field::defineExtrasSchema()} (grid layout);
 *   - auth strategy fields, declared via
 *     {@see \GlueAgency\Influx\auth\AuthStrategyInterface::editSchema()} (stacked
 *     layout on the Authentication tab).
 *
 * The Vue side has no per-field-type or per-auth-type branches — it just
 * renders by node type, so adding a kind/strategy is a PHP-only change.
 *
 * Node shape:
 *   type:         one of the TYPE_* constants below
 *   handle:       key inside the form's value object — a mapping's
 *                 `options`, the link's `auth` slice, ... (TYPE_ELEMENT_SUB_FIELDS
 *                 and TYPE_MATRIX_FIELDS are the exceptions: they write the
 *                 mapping's recursive `nativeFields` / `fields` channels)
 *   label:        translated row label
 *   instructions: translated hint shown under the control (optional; may
 *                 contain markup — rendered as HTML)
 *   placeholder:  input placeholder (optional)
 *   default:      display-only initial value when nothing is saved yet
 *                 (optional; never written until the user touches the control)
 *   options:      for select — flat [{value,label}] or grouped
 *                 [{label, options: [{value,label}]}]
 *   localOptions: for valueMapTable — value → label map of the Craft field's
 *                 own options
 *   subFields:    for elementSubFields / matrixFields — one primitive node
 *                 per row
 *   blockType:    for matrixFields — the block-type handle whose custom
 *                 fields the node's rows cover
 *   showIf:       list of {handle, equals?} conditions against the current
 *                 form values; omitted `equals` means truthy. All must match.
 *
 * Loosely modeled on Formie's SchemaHelper, deliberately tiny: ~7 node
 * types instead of 40, one-key conditions instead of an expression language.
 */
class BuilderSchema
{
    /**
     * Node types. The backed strings are the JS contract — SchemaForm.vue
     * dispatches on them — so they must stay stable.
     */
    public const TYPE_TEXT = 'text';
    public const TYPE_CODE = 'code';
    public const TYPE_TOKEN_INPUT = 'tokenInput';
    public const TYPE_SELECT = 'select';
    public const TYPE_LIGHTSWITCH = 'lightswitch';
    public const TYPE_VALUE_MAP_TABLE = 'valueMapTable';
    public const TYPE_ELEMENT_SUB_FIELDS = 'elementSubFields';
    public const TYPE_MATRIX_FIELDS = 'matrixFields';
    public const TYPE_NOTE = 'note';

    /**
     * @param array{instructions?: string, placeholder?: string, default?: mixed, showIf?: array} $config
     */
    public static function text(string $handle, string $label, array $config = []): array
    {
        return self::node(self::TYPE_TEXT, $handle, $label, $config);
    }

    /**
     * Monospace ("code") text input — for tokens, header names, and other
     * machine-y values. Same behavior as {@see text()}, different rendering.
     *
     * @param array{instructions?: string, placeholder?: string, default?: mixed, showIf?: array} $config
     */
    public static function code(string $handle, string $label, array $config = []): array
    {
        return self::node(self::TYPE_CODE, $handle, $label, $config);
    }

    /**
     * Text input with token chips (the SPA's TokenizedInput) — for values
     * that reference `.env` variables (`$VAR`), Craft aliases (`@alias`),
     * or any custom token group the consuming form supplies. PHP consumers
     * of env/alias values must run them through
     * `craft\helpers\App::parseEnv()`.
     *
     * @param array{instructions?: string, placeholder?: string, default?: mixed, showIf?: array} $config
     */
    public static function tokenInput(string $handle, string $label, array $config = []): array
    {
        return self::node(self::TYPE_TOKEN_INPUT, $handle, $label, $config);
    }

    /**
     * @param array $options Flat [{value,label}] or grouped [{label, options}] lists.
     * @param array{instructions?: string, default?: mixed, showIf?: array} $config
     */
    public static function select(string $handle, string $label, array $options, array $config = []): array
    {
        return self::node(self::TYPE_SELECT, $handle, $label, ['options' => $options] + $config);
    }

    /**
     * @param array{instructions?: string, default?: bool, showIf?: array} $config
     */
    public static function lightswitch(string $handle, string $label, array $config = []): array
    {
        return self::node(self::TYPE_LIGHTSWITCH, $handle, $label, $config);
    }

    /**
     * Remote value → local option rewrite table.
     *
     * @param array<string, string> $localOptions value → label of the Craft field's options
     * @param array{instructions?: string, showIf?: array} $config
     */
    public static function valueMapTable(string $handle, string $label, array $localOptions, array $config = []): array
    {
        return self::node(self::TYPE_VALUE_MAP_TABLE, $handle, $label, ['localOptions' => $localOptions] + $config);
    }

    /**
     * Source-node + default rows for a related element's native sub-fields
     * (asset alt/title). Writes the mapping's `nativeFields` channel, not
     * `options`.
     *
     * Each sub-field is itself a primitive node — its handle and label name
     * the row, and its type renders the row's default-value editor (a
     * {@see text()} sub-field gets a text default, {@see select()} a
     * dropdown, ...). The source-node select is provided by the table.
     *
     * @param list<array> $subFields One node per sub-field row.
     * @param array{instructions?: string, showIf?: array} $config
     */
    public static function elementSubFields(string $label, array $subFields, array $config = []): array
    {
        return self::node(self::TYPE_ELEMENT_SUB_FIELDS, 'nativeFields', $label, ['subFields' => $subFields] + $config);
    }

    /**
     * Source-node + default rows for one Matrix block type's custom fields.
     * Writes the mapping's recursive `fields` channel, not `options` — and
     * unlike {@see elementSubFields()}' relative sub-paths, every row's node
     * is an ABSOLUTE item path (see {@see \GlueAgency\Influx\fields\Matrix}).
     *
     * Declared once per block type: `config.blockType` carries the handle so
     * the SPA can match a node against the mapping's selected `blockType`
     * option, and a `showIf` on the same option keeps only the selected
     * block type's card visible.
     *
     * @param list<array> $subFields One node per sub-field row.
     * @param array{blockType?: string, instructions?: string, showIf?: array} $config
     */
    public static function matrixFields(string $label, array $subFields, array $config = []): array
    {
        return self::node(self::TYPE_MATRIX_FIELDS, 'fields', $label, ['subFields' => $subFields] + $config);
    }

    /**
     * Static explanatory text — for placeholders like the Matrix stub.
     */
    public static function note(string $text): array
    {
        return ['type' => self::TYPE_NOTE, 'handle' => '', 'label' => '', 'text' => $text];
    }

    protected static function node(string $type, string $handle, string $label, array $config): array
    {
        return array_filter(
            ['type' => $type, 'handle' => $handle, 'label' => $label] + $config,
            static fn($value) => $value !== null,
        );
    }
}
