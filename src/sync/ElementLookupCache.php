<?php

namespace GlueAgency\Influx\sync;

use craft\base\ElementInterface;

/**
 * Per-run memo of element lookups keyed by (element type, match strategy,
 * scope, value). Feeds routinely repeat the same relation/author values across
 * hundreds of items; without a cache each repeat re-runs the same element
 * query. One cache lives on the {@see SyncContext} for the duration of a run,
 * so it's automatically isolated per run and never leaks between links or sites.
 *
 * Both HITS and confirmed MISSES are cached: a null resolution is stored
 * deliberately, because feeds repeat unknown values just as often as known
 * ones and re-querying a value that resolves to nothing is pure waste. When an
 * element is created for a value that previously missed, {@see put()} flips the
 * cached miss to a hit — without that, every later item carrying the same value
 * would re-create the element (duplicates).
 *
 * Plain value object apart from the ElementInterface it stores. Bounded to
 * {@see MAX_ENTRIES}; the oldest entry is evicted past that ceiling.
 */
class ElementLookupCache
{
    /**
     * Ceiling on distinct cached lookups — bounds memory on a run that sees a
     * very large number of unique values. Past this the oldest entry is evicted.
     */
    protected const MAX_ENTRIES = 500;

    /**
     * Cached lookups keyed by {@see key()}. A null value is a CONFIRMED MISS
     * (the resolver ran and found nothing), not "not yet looked up" — absence
     * of the key is the "cold" state.
     *
     * @var array<string, ElementInterface|null>
     */
    protected array $entries = [];

    /**
     * Return the cached element (or cached null miss) for the given key,
     * running $resolve exactly once on a cold key and caching whatever it
     * returns — hit OR miss. $resolve is the expensive element query.
     *
     * @param callable(): ?ElementInterface $resolve
     */
    public function remember(string $type, string $match, string $scope, mixed $value, callable $resolve): ?ElementInterface
    {
        $key = $this->key($type, $match, $scope, $value);

        if (array_key_exists($key, $this->entries)) {
            return $this->entries[$key];
        }

        $element = $resolve();
        $this->store($key, $element);

        return $element;
    }

    /**
     * Insert or overwrite the cached entry for a key. Lets element creation
     * flip a cached miss into a hit so subsequent {@see remember()} calls for
     * the same value return the freshly created element instead of re-creating it.
     */
    public function put(string $type, string $match, string $scope, mixed $value, ?ElementInterface $element): void
    {
        $this->store($this->key($type, $match, $scope, $value), $element);
    }

    /**
     * Write a key, evicting the oldest entry first when at capacity. Rewriting
     * an existing key doesn't grow the map, so the eviction check only fires
     * for genuinely new keys.
     */
    protected function store(string $key, ?ElementInterface $element): void
    {
        if (! array_key_exists($key, $this->entries) && count($this->entries) >= self::MAX_ENTRIES) {
            unset($this->entries[array_key_first($this->entries)]);
        }

        $this->entries[$key] = $element;
    }

    /**
     * Build the cache key. Scope matters: the same value can resolve to
     * different elements per field (a relation field narrows by its own
     * sources), so the scope is part of the identity. Joined with a NUL byte —
     * a delimiter that can't occur in the FQCN, match key, scope, or a
     * stringified value.
     */
    protected function key(string $type, string $match, string $scope, mixed $value): string
    {
        return implode("\x00", [$type, $match, $scope, (string) $value]);
    }
}
