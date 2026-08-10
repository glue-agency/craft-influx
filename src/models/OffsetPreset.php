<?php

namespace GlueAgency\Influx\models;

use Craft;
use craft\web\View;
use GlueAgency\Influx\exceptions\InfluxException;
use Throwable;

/**
 * One named preset behind the link's "Partial import" switch:
 *
 *   offset:
 *     hour: { queryParam: 'modified_since', value: "{{ now|date_modify('-1 hour')|date('c', 'UTC') }}" }
 *     day:  { queryParam: 'modified_since', value: "{{ now|date_modify('-1 day')|date('Y-m-d') }}" }
 *
 * THE VALUE IS AUTHORED, NOT DERIVED. The preset used to hold a `since` string
 * and a date `format` and build the cutoff itself, with
 * `(new DateTime())->modify($since)` — which runs in Craft's system timezone and
 * offered no way to say otherwise. A site on Europe/Brussels therefore sent an
 * ISO 8601 cutoff carrying `+02:00`: the right instant, spelled in local
 * wall-clock. A consumer that parses that and then compares it as wall-clock
 * against a UTC column (Laravel binds a `DateTimeInterface` by calling
 * `format('Y-m-d H:i:s')` on it, in the value's OWN timezone) reads a cutoff two
 * hours in the future and matches nothing — a delta sync returning zero items
 * forever, reporting success as it goes.
 *
 * Which shape and which timezone a given API wants is not something this class
 * can know, so it stops guessing. The value is a Twig template rendered per run,
 * and whoever writes it says what goes on the wire: `date('c', 'UTC')` for a UTC
 * ISO cutoff, `date('U')` for a unix timestamp, `date('Y-m-d')` for a plain date.
 * A template with no tags renders verbatim, so an API that wants a duration
 * rather than a timestamp takes a literal `20 minutes`.
 *
 * Everything that can go wrong throws, and does so on the way INTO a fetch,
 * where {@see \GlueAgency\Influx\services\SynchronizationService} fails that
 * scope's log with the message. The alternative — dropping the param and
 * carrying on — turns a scheduled delta into a full fetch of the whole feed,
 * unswept, with nothing in the log to say it happened.
 *
 * Encoding is not this class's job: {@see \GlueAgency\Influx\services\DataService::get()}
 * hands the params to Guzzle's `query` option, which RFC3986-encodes them, so
 * the rendered value goes back raw rather than being encoded twice.
 */
class OffsetPreset
{
    /** The preset's handle in the link's offset map ('hour', 'day', ...). */
    public string $handle = '';

    /** Query-string parameter the rendered value is sent as. */
    public string $queryParam = '';

    /** Twig template producing the value. */
    public string $value = '';

    public function __construct(
        string $handle,
        string $queryParam,
        string $value,
    ) {
        $this->handle = $handle;
        $this->queryParam = $queryParam;
        $this->value = $value;
    }

    /**
     * Build a preset from a Link's offset map slice.
     *
     * @throws InfluxException when the slice is unfinished. A half-written
     * preset is config to fix, not a run to attempt: skipping it would fetch the
     * feed entire.
     */
    public static function fromConfig(string $handle, array $config): self
    {
        $queryParam = trim((string) ($config['queryParam'] ?? ''));
        $value = trim((string) ($config['value'] ?? ''));

        if ($queryParam === '' || $value === '') {
            throw new InfluxException("Offset preset '{$handle}' needs both a query param and a value.");
        }

        return new self(
            handle: $handle,
            queryParam: $queryParam,
            value: $value,
        );
    }

    /**
     * Pull a named preset off the link. Null ONLY when no offset was asked for —
     * a handle the link doesn't define throws, since the scheduled run that
     * carries the typo would otherwise degrade into a silent full fetch.
     *
     * @throws InfluxException when the link has no such preset, or has one that
     * can't be used.
     */
    public static function forLink(Link $link, ?string $handle): ?self
    {
        $handle = trim((string) $handle);

        if ($handle === '') {
            return null;
        }

        $config = $link->offset[$handle] ?? null;

        if (! is_array($config)) {
            throw new InfluxException("Link '{$link->handle}' has no offset preset '{$handle}'.");
        }

        return self::fromConfig($handle, $config);
    }

    /**
     * Render the value and pair it with the param it rides on.
     *
     * @return array{0: array<string,string>, 1: string} [queryParams, humanLabel]
     *   `queryParams` plugs into Guzzle directly; `humanLabel` is what the debug
     *   UI shows, so the operator can see what the preset resolved to rather
     *   than inferring it from the template.
     * @throws InfluxException when the template doesn't render.
     */
    public function resolve(): array
    {
        $rendered = $this->render();

        return [
            [$this->queryParam => $rendered],
            "{$this->queryParam} = {$rendered}",
        ];
    }

    /**
     * Run the value through Craft's template parser.
     *
     * The template mode is pinned rather than inherited: a queued run started
     * from the CP renders inside a control-panel request, and a preset must
     * resolve to the same string wherever the run was triggered from.
     *
     * The rendered string is trimmed because Twig keeps a template's trailing
     * newline, and a cutoff with `\n` on the end is a query param no API parses.
     *
     * This is the one call in the class that needs a booted Craft, which is what
     * makes it the seam the unit suite overrides.
     *
     * @throws InfluxException when the template is broken, naming the preset —
     * Twig identifies a string template by a hash nobody can act on.
     */
    protected function render(): string
    {
        try {
            return trim(Craft::$app->getView()->renderString($this->value, [], View::TEMPLATE_MODE_SITE));
        } catch (Throwable $e) {
            throw new InfluxException(
                "Offset preset '{$this->handle}' failed to render: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
