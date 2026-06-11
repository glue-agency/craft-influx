<?php

namespace GlueAgency\Influx\models;

use Cake\Utility\Hash;
use GlueAgency\Influx\sync\RemoteItem;

/**
 * One entry of a link's `mappings` config, typed. The persisted shape stays
 * the plain array on {@see Link::$mappings} (Project Config round-trips it);
 * this is the runtime view every consumer reads instead of `??`-chaining
 * into nested arrays. Treat instances as read-only — they're rebuilt from
 * config whenever the raw array changes.
 *
 * Owns THE canonical node → default resolution rule ({@see resolve()}),
 * which used to exist three times with subtly different semantics across
 * the field strategies, the element targets, and the sub-element walker.
 */
class FieldMapping
{
    /** The element field/attribute handle this mapping writes to. */
    public string $handle = '';

    /** Hash dot-path into the remote item, or null when only a default is set. */
    public ?string $node = null;

    /** Fallback value when the node is missing or empty. */
    public mixed $default = null;

    /**
     * Apply the default even with no node mapped (the "— use default —"
     * choice in the builder). Without it, a node-less mapping resolves to
     * null — a typed-but-unactivated default must not write anything.
     */
    public bool $useDefault = false;

    /** Per-field-type options (`match`, `valueMap`, `truthy`, ...). */
    public array $options = [];

    /** Recursive sub-mappings for a related element's custom fields. */
    protected array $fields = [];

    /** Recursive sub-mappings for a related element's native attributes. */
    protected array $nativeFields = [];

    /** The exact config array this mapping was built from. */
    protected array $config = [];

    protected function __construct(
        string $handle,
        ?string $node,
        mixed $default,
        bool $useDefault,
        array $options,
        array $fields,
        array $nativeFields,
        array $config,
    ) {
        $this->handle = $handle;
        $this->node = $node;
        $this->default = $default;
        $this->useDefault = $useDefault;
        $this->options = $options;
        $this->fields = $fields;
        $this->nativeFields = $nativeFields;
        $this->config = $config;
    }

    public static function fromConfig(string $handle, array $config): self
    {
        $node = $config['node'] ?? null;

        return new self(
            handle: $handle,
            node: is_string($node) && $node !== '' ? $node : null,
            default: $config['default'] ?? null,
            useDefault: !empty($config['useDefault']),
            options: is_array($config['options'] ?? null) ? $config['options'] : [],
            fields: is_array($config['fields'] ?? null) ? $config['fields'] : [],
            nativeFields: is_array($config['nativeFields'] ?? null) ? $config['nativeFields'] : [],
            config: $config,
        );
    }

    /**
     * Resolve the value this mapping yields for one remote item:
     *
     *   - node mapped:    read it, falling back to `default` when the node
     *                     is missing or empty;
     *   - no node:        `default` applies only when the user explicitly
     *                     chose "— use default —" ({@see $useDefault}) —
     *                     otherwise the mapping yields nothing;
     *   - empty results normalize to null ("no data — leave the field
     *     untouched").
     *
     * Note the deliberate semantic: an empty-string default resolves to null,
     * not '' (the old fetchSimpleValue let '' through; the target-side
     * resolveValue didn't — this rule standardizes on the latter).
     */
    public function resolve(RemoteItem $item): mixed
    {
        if ($this->node !== null) {
            $value = $item->get($this->node);
            if ($value === null || $value === '') {
                $value = $this->default;
            }
            return ($value === null || $value === '') ? null : $value;
        }

        if (!$this->useDefault) {
            return null;
        }

        return ($this->default === null || $this->default === '') ? null : $this->default;
    }

    /**
     * Read the node value with NO default fallback. Match values must come
     * from the feed itself — falling back to a configured default would make
     * every item match the same element.
     */
    public function rawValue(RemoteItem $item): mixed
    {
        return $this->node !== null ? $item->get($this->node) : null;
    }

    /**
     * Per-field-type option, supporting dot paths (`group.sectionId`).
     * Returns `$default` when the option is absent or null.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return Hash::get($this->options, $key) ?? $default;
    }

    public function hasSubMappings(): bool
    {
        return !empty($this->fields) || !empty($this->nativeFields);
    }

    /** Sub-mappings for the related element's custom fields (`fields`). */
    public function subMappings(): MappingCollection
    {
        return MappingCollection::fromConfig($this->fields);
    }

    /** Sub-mappings for the related element's native attributes (`nativeFields`). */
    public function nativeSubMappings(): MappingCollection
    {
        return MappingCollection::fromConfig($this->nativeFields);
    }

    /**
     * The exact config array this mapping was built from — lossless, so
     * consumers that still need the raw shape (Project Config, the builder
     * payload) keep round-tripping byte-identically.
     */
    public function toConfig(): array
    {
        return $this->config;
    }
}
