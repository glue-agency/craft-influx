<?php

namespace TDM\Influx\models;

/**
 * Sliding-window preset for incremental syncs:
 *
 *   ago:
 *     hour: { since: '-1 hour', queryParam: 'modified_since' }
 *     day:  { since: '-1 day',  queryParam: 'modified_since', format: 'Y-m-d' }
 *
 * Wraps the raw config slice so the "since string → DateTime → query param"
 * pipeline lives in one place. {@see SynchronizationService} and
 * {@see DebugService} both consume this — they used to duplicate the parsing
 * with subtly different error handling.
 */
class AgoPreset
{
    public function __construct(
        public readonly string $key,
        public readonly string $since,
        public readonly string $queryParam,
        public readonly string $format = \DateTimeInterface::ATOM,
    ) {
    }

    /**
     * Build a preset from a Link's ago map slice. Returns null when the
     * config is missing the mandatory keys — the caller treats null as
     * "no filter", same way the sync service used to.
     */
    public static function fromConfig(string $key, array $config): ?self
    {
        if (!isset($config['since'], $config['queryParam'])) {
            return null;
        }
        return new self(
            key: $key,
            since: (string)$config['since'],
            queryParam: (string)$config['queryParam'],
            format: (string)($config['format'] ?? \DateTimeInterface::ATOM),
        );
    }

    /**
     * Pull a named preset off the link, or null if the key isn't configured.
     */
    public static function forLink(Link $link, ?string $key): ?self
    {
        if (!$key || !isset($link->ago[$key]) || !is_array($link->ago[$key])) {
            return null;
        }
        return self::fromConfig($key, $link->ago[$key]);
    }

    /**
     * Compute the query-string fragment to send to the remote API.
     *
     * Mirrors the original "new DateTime()->modify(...)" idiom — which uses
     * PHP's default timezone — so non-ATOM formats (e.g. `Y-m-d`) don't shift
     * across midnight just because we tried to be clever with `@timestamp`.
     *
     * @return array{0: array<string,string>, 1: ?string} [queryParams, humanLabel]
     *   `queryParams` plugs into Guzzle directly; `humanLabel` is what the
     *   debug UI shows so the user can see which cutoff the preset resolved to.
     */
    public function resolve(): array
    {
        try {
            $since = (new \DateTime())->modify($this->since);
        } catch (\Throwable) {
            return [[], "invalid 'since' on preset '{$this->key}'"];
        }
        if ($since === false) {
            return [[], "invalid 'since' on preset '{$this->key}'"];
        }

        $formatted = $since->format($this->format);

        return [
            [$this->queryParam => $formatted],
            "{$this->queryParam} = {$formatted}",
        ];
    }
}
