<?php

namespace GlueAgency\Influx\schema;

use Closure;

/**
 * One mapping row's whole UI, as three regions of {@see SchemaBuilder} nodes.
 *
 * A mapping row is a three-column grid — label, source node, default value —
 * plus an extras block. Before this, each region was described by a different
 * mechanism: the default cell by a loose `defaultEditor()` descriptor, the extras
 * by real schema nodes, the "no cells at all" cases by boolean flags, and the
 * source cell by nothing at all (it was hardcoded in Vue for every row). Which
 * left three branch chains on control type and two vocabularies for the same
 * thing — `defaultType: 'select'` and a node's `type: 'select'`.
 *
 * Now one declaration covers all of it, and the SPA renders every region through
 * the same `type => component` map:
 *
 *   return MappingSchemaBuilder::make()->mapping([
 *       'source'  => true,                                        // the preset
 *       'default' => fn(MappingSchemaBuilder $b) => $b->select([...]),   // any control
 *       'extra'   => fn(MappingSchemaBuilder $b) => $b->matchBy([...]),
 *   ]);
 *
 * Three ways to declare a region, and the first is the point of the thing:
 *
 *   - `true` — the region's default control ({@see MappingSchemaBuilder::sourceNode()}
 *     for the source cell, a text input for the default). Sugar for the callback
 *     below, never a second code path, so a field wanting one tweak writes the
 *     callback and inherits everything else.
 *   - `false` / absent — the region isn't rendered. This is what replaced the
 *     `subfieldsOnly` and `unmappable` flags: a Matrix declares no source and no
 *     default, and a Preparse field declares a source region holding nothing but
 *     a note. Absence needs nothing kept in sync.
 *   - a callback — receives a fresh builder, declares whatever controls it wants.
 *
 * A DISTINCT type rather than more {@see MappingSchemaBuilder} surface, because a
 * builder is a flat list of nodes and that contract is load-bearing elsewhere:
 * {@see \GlueAgency\Influx\auth\AuthStrategyInterface::schema()} returns one and
 * the SPA renders it as a stacked form. Regions are a different shape, so they
 * get a different type instead of giving `toArray()` two output modes.
 *
 * Treat as read-only.
 */
class MappingSchema
{
    /** The row's three regions, in render order. */
    public const REGIONS = ['source', 'default', 'extra'];

    /**
     * The declarations as the strategy wrote them — `true`, `false` or a callback.
     *
     * @var array<string, bool|Closure>
     */
    protected array $declarations = [];

    /**
     * Resolved nodes per region, filled on first ask. Building a region can be
     * expensive — an `extra` region walks the field layouts a relation's sources
     * allow, and each sub-field row asks ANOTHER strategy for its default cell —
     * so a caller that only wants the default cell must not pay for the rest.
     * That's not just cost: a cyclic relation graph would otherwise recurse
     * without bound, since asking a relation for its default cell would build its
     * sub-field rows, each asking their own field for a cell in turn.
     *
     * @var array<string, list<array>>
     */
    protected array $resolved = [];

    /**
     * @param array<string, bool|Closure> $regions
     */
    public function __construct(array $regions = [])
    {
        $this->declarations = $regions;
    }

    /**
     * @param array<string, bool|Closure> $regions
     */
    public static function make(array $regions = []): self
    {
        return new self($regions);
    }

    /**
     * One region's nodes, or `[]` when the row doesn't render it.
     *
     * @return list<array>
     */
    public function region(string $region): array
    {
        return $this->resolved[$region] ??= $this->resolve($region, $this->declarations[$region] ?? false);
    }

    /**
     * The wire shape: one key per DECLARED region, so an absent key is an absent
     * cell and the SPA needs no flag to tell it so.
     *
     * @return array<string, list<array>>
     */
    public function toArray(): array
    {
        $regions = [];

        foreach (self::REGIONS as $region) {
            $nodes = $this->region($region);

            if ($nodes !== []) {
                $regions[$region] = $nodes;
            }
        }

        return $regions;
    }

    public function has(string $region): bool
    {
        return $this->region($region) !== [];
    }

    /**
     * One region's declaration into nodes. `true` resolves through the same
     * callback path its preset would take, so there is exactly one implementation
     * of each default control.
     *
     * @return list<array>
     */
    protected function resolve(string $region, mixed $declaration): array
    {
        if ($declaration === false || $declaration === null) {
            return [];
        }

        // A region callback gets the MAPPING builder: sub-field containers, the
        // source-node preset and the option presets all need mapping concepts a
        // plain SchemaBuilder has no business knowing about.
        $builder = MappingSchemaBuilder::make();

        if ($declaration === true) {
            $this->preset($region, $builder);
        } elseif ($declaration instanceof Closure) {
            $declaration($builder);
        }

        return $builder->toArray();
    }

    /**
     * What `true` means for each region. `extra` has no default control — there's
     * no such thing as a generic extras block, so `'extra' => true` declares
     * nothing rather than guessing.
     */
    protected function preset(string $region, MappingSchemaBuilder $builder): void
    {
        match ($region) {
            'source'  => $builder->sourceNode(),
            'default' => $builder->text(),
            default   => null,
        };
    }
}
