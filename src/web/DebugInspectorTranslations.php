<?php

namespace GlueAgency\Influx\web;

/**
 * The catalogue of UI strings the debug inspector Vue app passes through its
 * `$t` helper. Registered with Craft's View layer by
 * {@see \GlueAgency\Influx\controllers\LinksController::actionDebug()} — the one
 * screen that mounts the inspector — so `Craft.translations.influx` is populated
 * for any locale that ships a translations file. Without a file, `Craft.t` falls
 * back to the source string.
 *
 * ONLY the inspector's own strings (`src/web/assets/cp/src/debug/**`) live here.
 * The shared components it mounts carry theirs in
 * {@see SharedComponentTranslations}, which every app screen registers
 * alongside its own catalogue. The toolbar's option labels and the action
 * vocabulary are server-built ({@see LinkPresenter::debugOptions()},
 * {@see Vocabulary}) and reach the wire already translated.
 *
 * Pinned EXACTLY — in both directions — against the `$t(…)` calls in
 * `src/web/assets/cp/src/debug/**` by
 * {@see \GlueAgency\Influx\Tests\unit\web\CpAppTranslationsTest}: a string added
 * to the app without being added here fails that test, and so does a leftover
 * entry the app no longer uses.
 */
class DebugInspectorTranslations
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
            'Inspect button (page header)' => [
                'Inspecting…',
                'Inspect',
            ],
            'toolbar' => [
                'Link',
                'Site',
                'Default endpoint',
                'Partial import',
                'Whole feed',
                'Limit',
                'of {n}',
            ],
            'item list' => [
                'Items',
                '{n} fetched',
                'Fetching feed…',
                'No items on this page.',
                'New element',
                'Skipped',
                'No changes',
                '1 change',
                '{n} changes',
            ],
            'drill-down pane' => [
                'Select an item to inspect it.',
                'Inspection failed.',
            ],
        ];
    }
}
