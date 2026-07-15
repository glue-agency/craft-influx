<?php

namespace GlueAgency\Influx\sync;

use Cake\Utility\Hash;
use Throwable;

/**
 * One decoded item from a remote feed — the unit the per-item pipeline works
 * on. Wraps the raw associative array so dot-path reads live in one place
 * instead of path-walking code scattered across every consumer.
 *
 * Purely a runtime value object; never persisted.
 */
class RemoteItem
{
    /** The raw decoded item payload. */
    protected array $data = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Build an item from a SINGLE-resource response (a link's itemEndpoint),
     * unwrapping the same envelope the list feed declares via `rootNode` —
     * APIs that wrap their collection (`{"data": [...]}`) almost always wrap
     * the single resource the same way (`{"data": {...}}`), and feeding the
     * enveloped response into the pipeline makes every match path miss.
     *
     * Unwrap rules, most-specific first:
     *   - no rootNode configured → the response IS the item;
     *   - the rootNode holds an object → that object is the item;
     *   - the rootNode holds a list → its first array element (some APIs
     *     return a one-item collection for a single-resource fetch);
     *   - the rootNode is absent/scalar → the response as-is (the item
     *     endpoint returns the bare object even though the list is enveloped).
     */
    public static function fromItemResponse(array $response, ?string $rootNode): self
    {
        if (! $rootNode) {
            return new self($response);
        }

        $value = Hash::get($response, $rootNode);

        if (is_array($value) && $value !== []) {
            if (! array_is_list($value)) {
                return new self($value);
            }

            if (is_array($value[0])) {
                return new self($value[0]);
            }
        }

        return new self($response);
    }

    /**
     * Read a value by dot-path. Returns null for missing paths and for paths
     * the underlying data can't express — a malformed path is "no data",
     * never an exception.
     *
     * List hops are collapsed: a segment that lands on a list applies the
     * remaining path to every element (`directors.full_name` → all director
     * names), and a single-element list yields its value directly, so paths
     * read the same whether the feed ships one object or many. Explicit
     * numeric segments still address one element (`sections.0.name`).
     */
    public function get(string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        try {
            return $this->resolve($this->data, explode('.', $path));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Recursive dot-path walk implementing the collapsed-list semantics
     * documented on {@see get()}.
     */
    protected function resolve(mixed $data, array $segments): mixed
    {
        if ($segments === []) {
            return $data;
        }

        if (! is_array($data)) {
            return null;
        }

        if (array_is_list($data)) {
            // An explicit index wins when given.
            if (ctype_digit($segments[0])) {
                $index = (int) array_shift($segments);

                return $this->resolve($data[$index] ?? null, $segments);
            }

            // Collapsed hop: fan the remaining path over every element, dropping
            // nulls — the result is dense, not positionally aligned to the source
            $values = [];

            foreach ($data as $element) {
                $value = $this->resolve($element, $segments);

                if ($value !== null) {
                    $values[] = $value;
                }
            }

            if ($values === []) {
                return null;
            }

            return count($data) === 1 ? $values[0] : $values;
        }

        $key = array_shift($segments);

        if (! array_key_exists($key, $data)) {
            return null;
        }

        return $this->resolve($data[$key], $segments);
    }

    /**
     * The raw decoded payload — for log rows, event payloads, and debug
     * output, which all store/show the item as-is.
     */
    public function raw(): array
    {
        return $this->data;
    }
}
