<?php

namespace GlueAgency\Influx\web;

/**
 * The catalogue of UI strings the components under
 * `src/web/assets/cp/src/components/**` pass through their `$t` helper — the
 * tree every Influx Vue app mounts from (the link builder takes
 * MappingGroupCard, the log viewer and debug inspector take DebugItemDetail,
 * DrillList, SplitResizer, ActionBadge and ErrorPanel, and the builder and the
 * Logs overview's filter toolbar both take SearchableSelect).
 *
 * Its own catalogue rather than a copy inside each app's, for two reasons: a
 * shared component's strings are then maintained in one place, and every
 * catalogue in this namespace stays pinned to exactly one source tree, which is
 * what lets the anti-drift tests assert exact sets in both directions instead of
 * "at least contains". The cost is that a screen registers a handful of strings
 * for a sibling app's component — harmless, since `registerTranslations()` keys
 * each message once and only emits the ones a locale actually translates.
 *
 * Registered on every screen that mounts an app, by
 * {@see \GlueAgency\Influx\controllers\AbstractController::registerAppTranslations()}.
 *
 * Pinned EXACTLY — in both directions — against the `$t(…)` calls in that tree
 * by {@see \GlueAgency\Influx\Tests\unit\web\CpAppTranslationsTest}.
 */
class SharedComponentTranslations
{
    /**
     * The flat catalogue, deduplicated with first use winning.
     *
     * @return string[]
     */
    public static function strings(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::byComponent()))));
    }

    /**
     * The same strings grouped by the component that consumes them — the
     * grouping is the documentation, so a component's strings are found and
     * maintained together. ActionBadge, ErrorPanel and MappingGroupCard render
     * only server-supplied values, so they contribute none.
     *
     * DebugItemDetail and DrillList both summarise a drill-down's child count,
     * so they list the same count nouns; {@see self::strings()} deduplicates.
     *
     * @return array<string, string[]>
     */
    protected static function byComponent(): array
    {
        return [
            'DebugItemDetail.vue' => [
                'Parsed',
                'Raw JSON',
                'Field',
                'Incoming',
                'Current',
                'match by',
                'the unique identifier used by this Element Link',
                'missing node',
                'the mapped node does not exist for this Element Link',
                'use default',
                'the mapped node pushed a default value for this Element Link',
                'not managed by element',
                "This value isn't written during the element save — Influx reconciles it separately after each item is imported.",
                'No mapped fields.',
                // A drill row's count summary, one noun per children type.
                '{n} blocks',
                '{n} rows',
                '{n} assets',
                '{n} entries',
                '{n} users',
                '{n} categories',
                '{n} tags',
                '{n} elements',
                // …and its state label: the worst state inside, counted.
                '1 error',
                '{n} errors',
                '1 missing node',
                '{n} missing nodes',
                '1 change',
                '{n} changes',
                'No changes',
            ],
            'DrillList.vue' => [
                'Back to parent',
                // Its sub-strip's count summary, same nouns as the drill row's.
                '{n} blocks',
                '{n} rows',
                '{n} assets',
                '{n} entries',
                '{n} users',
                '{n} categories',
                '{n} tags',
                '{n} elements',
            ],
            'SplitResizer.vue' => [
                // The drag handle's accessible name — it renders no text.
                'Resize the item list',
            ],
            'SearchableSelect.vue' => [
                'Search…',
                'Clear search',
                'not in the sample',
                'No matches for',
                'No options available.',
                'Custom node',
                '{n} selected',
            ],
        ];
    }
}
