<?php

namespace GlueAgency\Influx\models;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Typed view over a link's `mappings` config: field handle → {@see FieldMapping}.
 * Non-array entries are skipped on hydration (the same defensive guard the
 * old raw-array walkers carried inline).
 *
 * @implements IteratorAggregate<string, FieldMapping>
 */
class MappingCollection implements IteratorAggregate, Countable
{
    /** @var array<string, FieldMapping> */
    protected array $mappings = [];

    /** @param array<string, FieldMapping> $mappings */
    protected function __construct(array $mappings)
    {
        $this->mappings = $mappings;
    }

    public static function fromConfig(array $mappings): self
    {
        $built = [];

        foreach ($mappings as $handle => $config) {
            if (! is_string($handle) || ! is_array($config)) {
                continue;
            }
            $built[$handle] = FieldMapping::fromConfig($handle, $config);
        }

        return new self($built);
    }

    /**
     * A collection over already-built mappings — for writers that produce
     * {@see FieldMapping}s (the Feed Me import) rather than parse them.
     *
     * @param array<string, FieldMapping> $mappings
     */
    public static function of(array $mappings): self
    {
        return new self($mappings);
    }

    /**
     * The whole map as stored config: handle => {@see FieldMapping::toConfig()},
     * in insertion order. A mapping that emits nothing is dropped — an empty
     * config array is exactly the "this handle isn't mapped" state, and the
     * stored shape carries no such entry.
     *
     * @return array<string, array>
     */
    public function toConfig(): array
    {
        $config = [];

        foreach ($this->mappings as $handle => $mapping) {
            $mappingConfig = $mapping->toConfig();

            if ($mappingConfig !== []) {
                $config[$handle] = $mappingConfig;
            }
        }

        return $config;
    }

    public function get(string $handle): ?FieldMapping
    {
        return $this->mappings[$handle] ?? null;
    }

    /** @return Traversable<string, FieldMapping> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->mappings);
    }

    public function count(): int
    {
        return count($this->mappings);
    }
}
