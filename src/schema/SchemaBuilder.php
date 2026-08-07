<?php

namespace GlueAgency\Influx\schema;

/**
 * The generic form vocabulary: a flat list of controls, and nothing that knows
 * what a mapping is.
 *
 * That is exactly what an auth strategy's form needs
 * ({@see \GlueAgency\Influx\auth\AuthStrategyInterface::schema()}) — single fields
 * rendered stacked, with no source node, no stored mapping slot and no nested
 * rows. Everything that DOES need those concepts lives in
 * {@see MappingSchemaBuilder}, which extends this: the mapping regions, the
 * source-node preset, the option presets, the sub-mapping containers, and the
 * native-descriptor `group()` an element target declares with.
 *
 * Chain onto an instance and terminate with {@see toArray()}:
 *
 *   return SchemaBuilder::make()
 *       ->text(['handle' => 'username', 'label' => 'Username'])
 *       ->code(['handle' => 'token', 'label' => 'Token'])
 *       ->toArray();
 *
 * EVERY field + helper takes the SAME signature — `(array $config = [])` — so
 * there's one signature to learn, and any default a shorthand supplies (handle,
 * label, default value, instructions) can be overridden by passing that key in
 * `$config`. Base fields fix only `type`; shorthands fold their defaults *under*
 * the caller's config (`$config + [defaults]`), so `$config` wins.
 *
 * The Vue side renders generically by node `type`, so adding a kind is a PHP-only
 * change. Recognised config keys: `handle`, `label`, `instructions` (HTML),
 * `placeholder`, `default`, `options` (select — flat [{value,label}] or grouped
 * [{label, options}]), `showIf`. A type outside the consts below goes through
 * {@see node()} and renders as a labeled text input rather than vanishing.
 *
 * `showIf` is a list of conditions, ALL of which must pass for the node to
 * render: `['handle' => 'mode', 'equals' => 'url']` (exactly that value),
 * `['handle' => 'mode', 'in' => ['a', 'b']]` (any of them), or a bare
 * `['handle' => 'upload']` (truthy). Each resolves against the saved value
 * falling back to the node's declared `default`, so a condition on an untouched
 * control tests what the operator actually sees. The grammar lives in the SPA's
 * `builder/lib/conditions.js`; both renderers share it.
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

    /**
     * A select that accumulates picks into a list. Its own type rather than a flag
     * on {@see SELECT}, so the arity travels the way every other control kind does
     * — one type, one control.
     */
    public const MULTI_SELECT = 'multiSelect';
    public const LIGHTSWITCH = 'lightswitch';
    public const NOTE = 'note';
    public const ELEMENT = 'element';

    /**
     * Craft's own icon picker, mounted the way {@see ELEMENT} mounts its element
     * select: the icon set is 3,800-odd entries with search terms and Pro gating,
     * which Craft already searches server-side, so there is nothing to
     * reimplement and nothing worth shipping to the client.
     */
    public const ICON = 'icon';

    /**
     * Accumulated fields in call order: form nodes from the field methods, or
     * {@see MappableField} descriptors from {@see group()} — a builder only ever
     * collects one of the two.
     *
     * @var list<array|MappableField>
     */
    protected array $fields = [];

    public function __construct()
    {
        $this->fields = [];
    }

    public static function make(): static
    {
        return new static();
    }

    /**
     * Escape hatch for a node type this builder doesn't ship — the seam for a
     * third-party kind, since the type consts above are a closed set the SPA
     * dispatches on. Same `(array $config = [])` convention as the built-in
     * field methods; `$type` is fixed, everything else comes from `$config`.
     *
     * SchemaForm renders a type it doesn't know as a labeled text input on the
     * node's `handle` (honouring `default`), so a node declared this way
     * degrades gracefully instead of vanishing.
     */
    public function node(string $type, array $config = []): static
    {
        return $this->push(['type' => $type] + $config);
    }

    public function text(array $config = []): static
    {
        return $this->push(['type' => self::TEXT] + $config);
    }

    /**
     * Monospace ("code") text input — for tokens, header names, and other
     * machine-y values. Same behaviour as {@see text()}, different rendering.
     */
    public function code(array $config = []): static
    {
        return $this->push(['type' => self::CODE] + $config);
    }

    /**
     * Text input with token chips (the SPA's TokenizedInput) — for values that
     * reference `.env` variables (`$VAR`), Craft aliases (`@alias`), or any
     * custom token group. PHP consumers must run values through
     * `craft\helpers\App::parseEnv()`.
     */
    public function tokenInput(array $config = []): static
    {
        return $this->push(['type' => self::TOKEN_INPUT] + $config);
    }

    public function select(array $config = []): static
    {
        return $this->push(['type' => self::SELECT] + $config);
    }

    /**
     * A mappable field whose default-value editor is an element picker (e.g. an
     * entry's Author, or a relation sub-field row). `elementType` is the FQCN to
     * pick from.
     */
    public function element(array $config = []): static
    {
        return $this->push(['type' => self::ELEMENT] + $config);
    }

    public function lightswitch(array $config = []): static
    {
        return $this->push(['type' => self::LIGHTSWITCH] + $config);
    }

    /**
     * Static explanatory text — for placeholders like the Matrix stub.
     *
     * An optional `example` renders below the text as a preformatted block, for
     * the cases where the shortest true answer is a worked feed snippet rather
     * than a sentence. Escaped and whitespace-preserving, so it carries JSON
     * without markup and without a formatter.
     *
     * Unlike its siblings this builds its node key by key rather than folding
     * `$config` in whole, because `text` and `url` decide what renders and a
     * stray key would read as markup. Everything else in `$config` still rides
     * along — `showIf` above all, which is what lets several notes describe one
     * setting's alternatives and only the matching one appear.
     */
    public function note(array $config = []): static
    {
        $node = ['type' => self::NOTE, 'text' => $config['text'] ?? ''];

        if (($config['example'] ?? '') !== '') {
            $node['example'] = (string) $config['example'];
        }

        // An optional trailing link, for a note whose real answer is elsewhere —
        // a feed format an integration documents in its own README, say. Kept as
        // a key of its own rather than markup inside `text`, which the renderer
        // escapes: a note's text is server-authored today, but one interpolating
        // a field name would be the day that stopped being safe to relax.
        if (($config['url'] ?? '') !== '') {
            $node['url'] = (string) $config['url'];
            $node['linkText'] = (string) ($config['linkText'] ?? $config['url']);
        }

        return $this->push($node + array_diff_key($config, array_flip(['text', 'example', 'url', 'linkText'])));
    }

    /**
     * Run $callback (passed $this) only when $condition is truthy — for the
     * common "add this field only if there's something to map" branch, kept
     * inline so a builder stays a single fluent expression.
     */
    public function when(mixed $condition, callable $callback): static
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

    /** @return list<array|MappableField> The accumulated fields. */
    public function toArray(): array
    {
        return $this->fields;
    }

    protected function push(array|MappableField $config): static
    {
        $this->fields[] = $config;

        return $this;
    }
}
