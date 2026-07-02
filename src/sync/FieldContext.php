<?php

namespace GlueAgency\Influx\sync;

use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\exceptions\MappingDepthException;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;

/**
 * Everything a field strategy needs to parse one mapping for one remote item.
 * Replaces the old setContext()/parseField() temporal coupling: strategy
 * instances are shared singletons (see
 * {@see \GlueAgency\Influx\services\FieldsService}), so per-call state must travel
 * with the call, not live on the instance — the sub-mapping recursion
 * (relation → sub-fields → relation) re-enters the same instances. Treat a
 * context as read-only; derive a new one via {@see descend()}.
 */
class FieldContext
{
    /**
     * How deep sub-mapping recursion may go. Cycles in feed data would
     * otherwise recurse without bound.
     */
    public const MAX_DEPTH = 3;

    /** The Craft field being mapped, or null for native attributes / tests. */
    public ?CraftFieldInterface $craftField = null;

    /** The element field/attribute handle being written. */
    public string $handle = '';

    /** The mapping config slice for this handle. */
    public FieldMapping $mapping;

    /** The remote item being processed. */
    public RemoteItem $item;

    public Link $link;

    /** The element the parsed value will be applied to. */
    public ElementInterface $element;

    /**
     * When true the strategy must be side-effect free: no element saves, no
     * asset uploads, no created-when-missing relations. The debug inspector
     * runs the exact same code path with this flag set.
     */
    public bool $dryRun = false;

    /** Current sub-mapping recursion depth (0 = top-level mapping). */
    public int $depth = 0;

    /**
     * The run's element-lookup cache, carried down from the {@see SyncContext}
     * so relation strategies can memo their lookups. Null when a context is
     * built directly (e.g. in tests) without a run behind it — strategies fall
     * back to querying uncached in that case.
     */
    public ?ElementLookupCache $lookups = null;

    public function __construct(
        ?CraftFieldInterface $craftField,
        string $handle,
        FieldMapping $mapping,
        RemoteItem $item,
        Link $link,
        ElementInterface $element,
        bool $dryRun = false,
        int $depth = 0,
        ?ElementLookupCache $lookups = null,
    ) {
        $this->craftField = $craftField;
        $this->handle = $handle;
        $this->mapping = $mapping;
        $this->item = $item;
        $this->link = $link;
        $this->element = $element;
        $this->dryRun = $dryRun;
        $this->depth = $depth;
        $this->lookups = $lookups;
    }

    /**
     * Derive the context for one of this mapping's sub-mappings, applied to
     * a related element. Item, link and dry-run flag carry over; depth is
     * incremented and capped.
     *
     * `$item` overrides the remote item the sub-mapping resolves against.
     * Relational sub-mappings pass nothing and inherit the parent's item;
     * {@see \GlueAgency\Influx\fields\Matrix} descends with a synthetic
     * single-value item so a child strategy's own resolve() yields exactly one
     * block's value.
     *
     * @throws MappingDepthException past MAX_DEPTH
     */
    public function descend(
        ElementInterface $subElement,
        FieldMapping $subMapping,
        ?CraftFieldInterface $craftField = null,
        ?RemoteItem $item = null,
    ): self {
        if ($this->depth + 1 > self::MAX_DEPTH) {
            throw new MappingDepthException(
                'Sub-mapping recursion exceeded ' . self::MAX_DEPTH
                . " levels at '{$subMapping->handle}' — check for circular fields/nativeFields config.",
            );
        }

        return new self(
            craftField: $craftField,
            handle: $subMapping->handle,
            mapping: $subMapping,
            item: $item ?? $this->item,
            link: $this->link,
            element: $subElement,
            dryRun: $this->dryRun,
            depth: $this->depth + 1,
            lookups: $this->lookups,
        );
    }
}
