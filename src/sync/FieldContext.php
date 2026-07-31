<?php

namespace GlueAgency\Influx\sync;

use Closure;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\exceptions\MappingDepthException;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\item\ChildResultCollector;
use GlueAgency\Influx\sync\item\ElementLookupCache;
use GlueAgency\Influx\sync\item\MappingApplier;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\item\SubMappingOutcome;

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

    /**
     * Strategy-lookup seam: `fn(CraftFieldInterface): Field`. Null = ask the
     * plugin's registry ({@see strategyFor()}).
     */
    public ?Closure $strategyResolver = null;

    /**
     * The applier driving this walk, lent to the field layer for its
     * sub-mappings ({@see applySubMappings()}).
     */
    public ?MappingApplier $applier = null;

    /**
     * The walk's child-result collector, carried down so a strategy that writes
     * nested elements can report them for the inspectors' drill-down. One lives
     * per item walk. Null when a context is built directly (e.g. in tests)
     * without a walk behind it — collection then simply doesn't happen.
     */
    public ?ChildResultCollector $childCollector = null;

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
        ?Closure $strategyResolver = null,
        ?MappingApplier $applier = null,
        ?ChildResultCollector $childCollector = null,
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
        $this->strategyResolver = $strategyResolver;
        $this->applier = $applier;
        $this->childCollector = $childCollector;
    }

    /**
     * The mapping strategy for a Craft field, resolved through the seam this
     * context carries.
     *
     * WHY it rides the context: {@see \GlueAgency\Influx\sync\item\MappingApplier::mapCustomField()}
     * and {@see \GlueAgency\Influx\fields\Matrix::childStrategy()} each reached
     * `Influx::getInstance()->fields->forCraftField()` mid-walk, putting the
     * plugin singleton in the middle of the mapping walk and making the field
     * layer unusable without a booted plugin. The resolver travels with the call
     * instead, and {@see descend()} carries it to every sub-mapping.
     *
     * A null resolver falls back to the plugin's registry, resolved lazily on
     * use only: production code paths that build a context without one keep
     * working, and a hand-built (bootless) context stays boot-free unless it
     * actually resolves a strategy.
     */
    public function strategyFor(CraftFieldInterface $craftField): Field
    {
        if ($this->strategyResolver !== null) {
            return ($this->strategyResolver)($craftField);
        }

        return Influx::getInstance()->fields->forCraftField($craftField);
    }

    /**
     * Run this mapping's sub-mappings against a related element, through the
     * applier that started this walk.
     *
     * WHY it rides the context: {@see \GlueAgency\Influx\fields\RelationalField::persistSubElement()}
     * used to `new MappingApplier()` mid-parse, closing a construction cycle
     * (applier → registry → strategy → applier). The applier lends itself to the
     * contexts it builds, so the field layer borrows it instead of building one
     * — and never names the class at all.
     *
     * A context nobody lent an applier to (a strategy exercised directly) gets a
     * throwaway one, so the call still behaves like the real walk. Not memoized:
     * a context is read-only and an applier holds no per-call state.
     *
     * @return SubMappingOutcome The walk's per-sub-field rows;
     * {@see SubMappingOutcome::changed()} is the "any sub-mapping wrote a
     * differing value" verdict this used to return on its own.
     * @throws MappingDepthException on runaway recursion
     */
    public function applySubMappings(ElementInterface $subElement): SubMappingOutcome
    {
        return ($this->applier ?? new MappingApplier())->applySubMappings($this, $subElement);
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
     * The strategy resolver, the applier and the child collector carry over too
     * — a sub-mapping is part of the same walk, so it must resolve child
     * strategies, nested sub-mappings and nested children through the same seams
     * the top level got.
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
            strategyResolver: $this->strategyResolver,
            applier: $this->applier,
            childCollector: $this->childCollector,
        );
    }
}
