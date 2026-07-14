<?php

namespace GlueAgency\Influx\sync;

/**
 * Outcome of applying one mapping to one element. Produced by
 * {@see MappingApplier} for the real run and the dry-run alike — the debug
 * view's per-mapping rows are a presentation of exactly these objects, so
 * what the inspector shows is by construction what the sync would do.
 *
 * Values are raw (un-truncated, un-stringified); presentation belongs to
 * the consumer. Treat as read-only.
 */
class MappingResult
{
    public string $handle = '';

    /** The mapping's source node, for display. */
    public ?string $node = null;

    /** The mapping's configured default, for display. */
    public mixed $default = null;

    /** Whether this row was handled as a native attribute. */
    public bool $native = false;

    /** The node value as read from the item (no default fallback). */
    public mixed $rawValue = null;

    /** The strategy's parse output; null = field left untouched. */
    public mixed $parsedValue = null;

    /** The element's value before this mapping was applied. */
    public mixed $currentValue = null;

    /** Whether this mapping wrote a differing value (null = not evaluated). */
    public ?bool $changed = null;

    /**
     * The feed didn't address this mapping — no node value and no default — so
     * the field/attribute was deliberately left untouched. Surfaced as a pill
     * in the inspector.
     */
    public bool $unaddressed = false;

    /**
     * The applied value came from the mapping's configured default rather than
     * the feed ({@see \GlueAgency\Influx\models\FieldMapping::usesDefault()}).
     * Mutually exclusive with {@see $unaddressed}. Surfaced as a pill.
     */
    public bool $usedDefault = false;

    /**
     * The target owns this attribute and reconciles it itself (e.g. a User
     * link's `groups`), so the sync doesn't write it during the element save.
     * Surfaced as a pill; the row carries the feed value for reference only.
     */
    public bool $managedByTarget = false;

    public ?string $error = null;

    public function __construct(
        string $handle,
        ?string $node,
        mixed $default,
        bool $native,
        mixed $rawValue,
        mixed $parsedValue = null,
        mixed $currentValue = null,
        ?bool $changed = null,
        bool $unaddressed = false,
        bool $usedDefault = false,
        bool $managedByTarget = false,
        ?string $error = null,
    ) {
        $this->handle = $handle;
        $this->node = $node;
        $this->default = $default;
        $this->native = $native;
        $this->rawValue = $rawValue;
        $this->parsedValue = $parsedValue;
        $this->currentValue = $currentValue;
        $this->changed = $changed;
        $this->unaddressed = $unaddressed;
        $this->usedDefault = $usedDefault;
        $this->managedByTarget = $managedByTarget;
        $this->error = $error;
    }
}
