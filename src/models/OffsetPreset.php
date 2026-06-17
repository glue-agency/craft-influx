<?php

namespace GlueAgency\Influx\models;

use DateTime;
use DateTimeInterface;
use Throwable;

/**
 * Sliding-window preset for incremental syncs:
 *
 *   offset:
 *     hour: { since: '-1 hour', queryParam: 'modified_since' }
 *     day:  { since: '-1 day',  queryParam: 'modified_since', format: 'Y-m-d' }
 *
 * Wraps the raw config slice so the "since string → DateTime → query param"
 * pipeline lives in one place. {@see SynchronizationService} and
 * {@see DebugService} both consume this — they used to duplicate the parsing
 * with subtly different error handling.
 */
class OffsetPreset
{
    /** The preset's handle in the link's offset map ('hour', 'day', ...). */
    public string $handle = '';

    /** Relative date string fed to DateTime::modify ('-1 hour'). */
    public string $since = '';

    /** Query-string parameter the cutoff is sent as. */
    public string $queryParam = '';

    /** PHP date format for the cutoff value. */
    public string $format = DateTimeInterface::ATOM;

    public function __construct(
        string $handle,
        string $since,
        string $queryParam,
        string $format = DateTimeInterface::ATOM,
    ) {
        $this->handle = $handle;
        $this->since = $since;
        $this->queryParam = $queryParam;
        $this->format = $format;
    }

    /**
     * Build a preset from a Link's offset map slice. Returns null when the
     * config is missing the mandatory keys — the caller treats null as
     * "no filter", same way the sync service used to.
     */
    public static function fromConfig(string $handle, array $config): ?self
    {
        if (! isset($config['since'], $config['queryParam'])) {
            return null;
        }

        return new self(
            handle: $handle,
            since: (string) $config['since'],
            queryParam: (string) $config['queryParam'],
            format: (string) ($config['format'] ?? DateTimeInterface::ATOM),
        );
    }

    /**
     * Pull a named preset off the link, or null if the handle isn't configured.
     */
    public static function forLink(Link $link, ?string $handle): ?self
    {
        if (! $handle || ! isset($link->offset[$handle]) || ! is_array($link->offset[$handle])) {
            return null;
        }

        return self::fromConfig($handle, $link->offset[$handle]);
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
            $since = (new DateTime())->modify($this->since);
        } catch (Throwable) {
            return [[], "invalid 'since' on preset '{$this->handle}'"];
        }

        if ($since === false) {
            return [[], "invalid 'since' on preset '{$this->handle}'"];
        }

        $formatted = $since->format($this->format);

        return [
            [$this->queryParam => $formatted],
            "{$this->queryParam} = {$formatted}",
        ];
    }
}
