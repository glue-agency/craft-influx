<?php

namespace GlueAgency\Influx\models;

/**
 * Typed view over a link's `mappings` config: field handle → {@see FieldMapping}.
 * Non-array entries are skipped on hydration (the same defensive guard the
 * old raw-array walkers carried inline).
 *
 * @implements \IteratorAggregate<string, FieldMapping>
 */
class MappingCollection implements \IteratorAggregate, \Countable
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
            if (!is_string($handle) || !is_array($config)) {
                continue;
            }
            $built[$handle] = FieldMapping::fromConfig($handle, $config);
        }
        return new self($built);
    }

    public function get(string $handle): ?FieldMapping
    {
        return $this->mappings[$handle] ?? null;
    }

    public function has(string $handle): bool
    {
        return isset($this->mappings[$handle]);
    }

    /** @return \Traversable<string, FieldMapping> */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->mappings);
    }

    public function count(): int
    {
        return count($this->mappings);
    }

    /** Rebuild the raw config shape, losslessly. */
    public function toConfig(): array
    {
        return array_map(
            static fn(FieldMapping $mapping): array => $mapping->toConfig(),
            $this->mappings,
        );
    }
}
