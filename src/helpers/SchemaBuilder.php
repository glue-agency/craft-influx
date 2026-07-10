<?php

namespace GlueAgency\Influx\helpers;

use Craft;
use GlueAgency\Influx\fields\Field;

/**
 * The one builder for every PHP-declared form in the plugin — the fields a
 * developer configures on any of the three extension points:
 *
 *   - auth strategy fields ({@see \GlueAgency\Influx\auth\AuthStrategyInterface::schema()});
 *   - mapping extras for a field strategy ({@see \GlueAgency\Influx\fields\Field::schema()});
 *   - an element target's native mappable fields (via {@see group()},
 *     {@see \GlueAgency\Influx\targets\ElementTargetInterface::getMappableFields()}).
 *
 * Chain onto an instance and terminate with {@see toArray()}:
 *
 *   return SchemaBuilder::make()
 *       ->matchBy(['options' => $options])
 *       ->createWhenMissing()
 *       ->toArray();
 *
 * EVERY field + helper takes the SAME signature — `(array $config = [])` — so
 * there's one signature to learn, and any default a shorthand supplies (handle,
 * label, default value, instructions) can be overridden by passing that key in
 * `$config`. Base fields fix only `type`; shorthands fold their defaults *under*
 * the caller's config (`$config + [defaults]`), so `$config` wins.
 *
 * The Vue side ({@see SchemaForm.vue}) renders generically by node `type`, so
 * adding a kind is a PHP-only change. Recognised config keys: `handle`, `label`,
 * `instructions` (HTML), `placeholder`, `default`, `options` (select — flat
 * [{value,label}] or grouped [{label, options}]), `showIf`.
 *
 * Loosely modeled on Formie's SchemaHelper, deliberately tiny.
 */
class SchemaBuilder
{
    /**
     * Node types. The backed strings are the JS contract — SchemaForm.vue
     * dispatches on them — so they must stay stable.
     */
    public const TEXT = 'text';
    public const CODE = 'code';
    public const TOKEN_INPUT = 'tokenInput';
    public const SELECT = 'select';
    public const LIGHTSWITCH = 'lightswitch';
    public const ELEMENT_SUB_FIELDS = 'elementSubFields';
    public const MATRIX_FIELDS = 'matrixFields';
    public const NOTE = 'note';

    /** @var list<array> Accumulated fields (nodes or descriptors), in call order. */
    protected array $fields = [];

    public function __construct()
    {
        $this->fields = [];
    }

    public static function make(): self
    {
        return new self();
    }

    // -- base fields -------------------------------------------------------

    public function text(array $config = []): self
    {
        return $this->push(['type' => self::TEXT] + $config);
    }

    /**
     * Monospace ("code") text input — for tokens, header names, and other
     * machine-y values. Same behaviour as {@see text()}, different rendering.
     */
    public function code(array $config = []): self
    {
        return $this->push(['type' => self::CODE] + $config);
    }

    /**
     * Text input with token chips (the SPA's TokenizedInput) — for values that
     * reference `.env` variables (`$VAR`), Craft aliases (`@alias`), or any
     * custom token group. PHP consumers must run values through
     * `craft\helpers\App::parseEnv()`.
     */
    public function tokenInput(array $config = []): self
    {
        return $this->push(['type' => self::TOKEN_INPUT] + $config);
    }

    public function select(array $config = []): self
    {
        return $this->push(['type' => self::SELECT] + $config);
    }

    public function lightswitch(array $config = []): self
    {
        return $this->push(['type' => self::LIGHTSWITCH] + $config);
    }

    /** Static explanatory text — for placeholders like the Matrix stub. */
    public function note(array $config = []): self
    {
        return $this->push(['type' => self::NOTE, 'text' => $config['text'] ?? '']);
    }

    /**
     * Source-node + default rows for a related element's native sub-fields
     * (asset alt/title, entry title/slug). Writes the mapping's `nativeFields`
     * channel. `$config` supplies `label` + `subFields` (a list of primitive
     * nodes, e.g. `SchemaBuilder::make()->text([...])->toArray()`).
     */
    public function elementSubFields(array $config = []): self
    {
        return $this->push(['type' => self::ELEMENT_SUB_FIELDS, 'handle' => 'nativeFields'] + $config);
    }

    /**
     * One Matrix block type's card: source-node + default rows for its custom
     * fields, writing the block type's slice of the mapping's `blocks` channel
     * (`blocks.<blockType>.fields`). `$config` supplies `label`, `subFields`
     * and `blockType`.
     */
    public function matrixFields(array $config = []): self
    {
        return $this->push(['type' => self::MATRIX_FIELDS, 'handle' => 'blocks'] + $config);
    }

    // -- shorthands (reused fields) ----------------------------------------

    /**
     * The reused "Match by" control: a select on the mapping's `match` option.
     * Pass `options` (and optionally override `handle` / `label` / `default`).
     */
    public function matchBy(array $config = []): self
    {
        return $this->select($config + [
            'handle'  => 'match',
            'label'   => Craft::t('influx', 'Match by'),
            'default' => 'id',
        ]);
    }

    /**
     * The reused date-format select on the mapping's `format` option. Pass
     * `options` ({@see \GlueAgency\Influx\fields\Date::formatOptions()}); the
     * label, instructions and "auto-detect" default are supplied here.
     */
    public function dateFormat(array $config = []): self
    {
        return $this->select($config + [
            'handle'       => 'format',
            'label'        => Craft::t('influx', 'Date format'),
            'instructions' => Craft::t('influx', 'Used by DateTime::createFromFormat. "Unix timestamp" parses integer seconds; "Auto-detect" uses the Craft DateTimeHelper.'),
            'default'      => '',
        ]);
    }

    /**
     * The reused "create the related element when no match is found" toggle,
     * on the mapping's `create` option.
     */
    public function createWhenMissing(array $config = []): self
    {
        return $this->lightswitch($config + [
            'handle' => 'create',
            'label'  => Craft::t('influx', 'Create when not found'),
        ]);
    }

    // -- element-target mappable fields ------------------------------------

    /**
     * Native mappable-field descriptors for an element target's
     * {@see \GlueAgency\Influx\targets\ElementTargetInterface::getMappableFields()},
     * grouped under $label. A batch helper (it emits many descriptors), so it
     * takes the label + a list of field specs rather than a single config:
     *
     *   ['handle' => 'author', 'name' => 'Author',
     *    'type' => 'element',                                 // → defaultType (default 'text')
     *    'elementType' => User::class,                        // element only
     *    'options' => ['live' => 'Live', ...],                // select only (value => label map)
     *    'extras' => fn (SchemaBuilder $b) => $b->matchBy([...]),  // builds fieldMeta.schema
     *    'meta' => ['subfieldsOnly' => true]]                 // extra fieldMeta keys
     *
     * Each spec is stamped `native => true` + `group => $label`; `extras` / `meta`
     * are wrapped through {@see Field::meta()} into the `fieldMeta` envelope.
     *
     * @param list<array> $fields Field specs.
     */
    public function group(string $label, array $fields): self
    {
        foreach ($fields as $spec) {
            $descriptor = [
                'handle'      => $spec['handle'],
                'name'        => $spec['name'],
                'native'      => true,
                'group'       => $label,
                'defaultType' => $spec['type'] ?? self::TEXT,
            ];

            if (isset($spec['options'])) {
                $descriptor['options'] = $spec['options'];
            }

            if (isset($spec['elementType'])) {
                $descriptor['elementType'] = $spec['elementType'];
            }

            if (isset($spec['extras']) || isset($spec['meta'])) {
                $extras = self::make();

                if (isset($spec['extras'])) {
                    ($spec['extras'])($extras);
                }
                $descriptor['fieldMeta'] = Field::meta($extras->toArray(), $spec['meta'] ?? []);
            }

            $this->push($descriptor);
        }

        return $this;
    }

    // -- assembly ----------------------------------------------------------

    /**
     * Run $callback (passed $this) only when $condition is truthy — for the
     * common "add this field only if there's something to map" branch, kept
     * inline so a builder stays a single fluent expression.
     */
    public function when(mixed $condition, callable $callback): self
    {
        if ($condition) {
            $callback($this);
        }

        return $this;
    }

    /** Whether nothing has been added yet. */
    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** @return list<array> The accumulated fields. */
    public function toArray(): array
    {
        return $this->fields;
    }

    protected function push(array $config): self
    {
        $this->fields[] = $config;

        return $this;
    }
}
