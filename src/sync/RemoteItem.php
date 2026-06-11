<?php

namespace GlueAgency\Influx\sync;

use Cake\Utility\Hash;

/**
 * One decoded item from a remote feed — the unit the per-item pipeline works
 * on. Wraps the raw associative array so dot-path reads live in one place
 * instead of `Hash::get` calls scattered across every consumer.
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
     * Read a value by Hash dot-path. Returns null for missing paths and for
     * paths the underlying data can't express (e.g. matchers against scalar
     * branches) — a malformed path is "no data", never an exception.
     */
    public function get(string $path): mixed
    {
        if ($path === '') {
            return null;
        }
        try {
            return Hash::get($this->data, $path);
        } catch (\Throwable) {
            return null;
        }
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
