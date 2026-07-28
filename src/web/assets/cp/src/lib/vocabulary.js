import generated from './vocabulary.generated.json';

/**
 * The UI vocabulary the server derives from its enums — the action → status
 * colour map (committed actions and dry-run `would-…` labels alike) and the log
 * viewer's counter definitions. Built by `GlueAgency\Influx\web\Vocabulary` and
 * shipped in each app's bootstrap config, so nothing here re-encodes what PHP
 * already owns.
 *
 * Module-scoped rather than injected: the consumers are a plain helper
 * (actionColors.js) and a leaf component (ActionBadge), and every app installs
 * the payload before it mounts. `vocabulary.generated.json` is a generated copy
 * of the same payload — pinned against the enums by the PHP-side
 * VocabularyTest — so a page that never shipped one (and vitest) still renders
 * real colours instead of degrading to grey.
 */
let vocabulary = generated;

/**
 * Adopt a bootstrap payload. A missing or partial one keeps the generated
 * default for whatever it doesn't carry.
 */
export function installVocabulary(payload) {
    vocabulary = {
        actionColors: (payload && payload.actionColors) || generated.actionColors,
        counters: (payload && payload.counters) || generated.counters,
    };
}

/** Action string → Craft status colour ('live' | 'pending' | 'expired'). */
export function actionColorMap() {
    return vocabulary.actionColors;
}

/**
 * The log viewer's counters in display order: `{ key, action, label, tone }`,
 * leading with the run-wide `itemsSeen` total (no action — clicking it clears
 * the filter).
 */
export function counterDefs() {
    return vocabulary.counters;
}
