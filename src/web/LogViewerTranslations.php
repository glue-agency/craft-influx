<?php

namespace GlueAgency\Influx\web;

/**
 * The catalogue of UI strings the run-log viewer Vue app passes through its
 * `$t` helper. Registered with Craft's View layer by
 * {@see \GlueAgency\Influx\controllers\LogsController::actionView()} — the one
 * screen that mounts the viewer — so `Craft.translations.influx` is populated
 * for any locale that ships a translations file. Without a file, `Craft.t`
 * falls back to the source string.
 *
 * ONLY the viewer's own strings (`src/web/assets/cp/src/logs/**`) live here.
 * The shared components it mounts carry theirs in
 * {@see SharedComponentTranslations}, which every app screen registers
 * alongside its own catalogue. Server-built values — counter labels, run
 * status, trigger and item vocabulary (see {@see Vocabulary} and
 * {@see LogPresenter}) — already run through `Craft::t()` and reach the wire
 * translated.
 *
 * Pinned EXACTLY — in both directions — against the `$t(…)` calls in
 * `src/web/assets/cp/src/logs/**` by
 * {@see \GlueAgency\Influx\Tests\unit\web\CpAppTranslationsTest}: a string
 * added to the app without being added here fails that test, and so does a
 * leftover entry the app no longer uses.
 */
class LogViewerTranslations
{
    /**
     * The flat catalogue, deduplicated with first use winning.
     *
     * @return string[]
     */
    public static function strings(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::byRegion()))));
    }

    /**
     * The same strings grouped by the region of the screen that renders them,
     * top to bottom — the app is a single component, so the region is what
     * locates a string; the grouping is the documentation.
     *
     * @return array<string, string[]>
     */
    protected static function byRegion(): array
    {
        return [
            'live-stream control (page header)' => [
                'Resume live log updates',
                'Pause live log updates — the sync keeps running in the background',
                'Resume updates',
                'Pause updates',
                'connecting…',
                'live updates',
                'updates paused',
                'connection lost',
                'running…',
            ],
            'run summary' => [
                'Endpoints',
                'Resource',
                'site {s}',
                'window {w}',
                'started {d}',
                'ran for {d}',
                'Show only {label} items',
                'Show all items',
            ],
            'item list' => [
                'Items',
                '{n} processed',
                'showing {label}',
                'filter with the counters above',
                'Loading…',
                'In trash',
                'Saved despite {n} field error(s)',
                'No {label} items',
                'No data to process',
                'No items',
                'Page {n} of {total}',
            ],
            'drill-down pane' => [
                'Select an item to inspect it.',
                'No content returned.',
                'Request failed.',
            ],
        ];
    }
}
