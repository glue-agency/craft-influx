<template>
    <component :is="tag" :placement="placement" :max-width="maxWidth" :text="text">
        <button
            type="button"
            class="influx-tooltip-trigger"
            :class="triggerClass"
            :aria-label="text"
            :title="native ? text : null"
            @click.stop
            @keydown.stop
        >
            <slot />
        </button>
    </component>
</template>

<script>
/**
 * THE explanatory tooltip: a trigger carrying a sentence the reader needs, in
 * Craft's own tooltip where the CP registers one.
 *
 * Craft 5 registers `<craft-tooltip>` and owns the positioning, flipping and
 * theming; Craft 4 ships nothing of the kind, so the host degrades to a plain
 * inline span and the trigger keeps the sentence in its native `title`. The
 * caller's markup is the same either way.
 *
 * For explanations only. An icon button whose `title` is its NAME (Clear search,
 * Delete row, Reorder, Configure) keeps the native attribute — that is what
 * Craft's own CP does for those, and wrapping them would diverge from the CP
 * rather than match it. This is for the badges and pills whose sentence IS the
 * content: why a row is flagged, what a count counts.
 *
 * And only for a PASSIVE one. The trigger is a button, so this can neither wrap
 * a working control (it swallows the press) nor sit inside one (a button in a
 * button is invalid, and a second focus stop in every row costs more than a
 * styled tooltip returns). Both cases keep their native `title`: the sidebar's
 * Fetch sample / Auto-match buttons, and the log list's per-item error count.
 *
 * Three things it settles that each call site otherwise re-solves:
 *
 *   - The trigger is a BUTTON because Craft's tooltip needs a focusable target,
 *     which is also what keeps the explanation reachable by keyboard.
 *   - The sentence is the trigger's accessible NAME (`aria-label`), not only its
 *     tooltip text: Craft's tooltip sets no `role="tooltip"` and no
 *     `aria-describedby`, so a reader who can't see it would otherwise get the
 *     bare label with no way to reach the why. On Craft 4 the same sentence
 *     rides the native `title`; on Craft 5 that would be a second tooltip over
 *     the first, so it's dropped.
 *   - `.stop` on click AND keydown, because these sit in card headers that are
 *     themselves toggles — on click and on keydown.enter/.space, with `.prevent`.
 *     A trigger without both toggles the card it explains.
 *
 * The template carries no comment of its own: one would make this a fragment,
 * and the host has to BE the component's root for a caller to lay it out where
 * the badge it replaced used to sit.
 *
 * The host element is inline by default and cp.css styles only `.craft-tooltip`
 * (the span it appends to the body), never the host — so a caller that needs the
 * wrapper to lay out like the badge it replaced styles the host itself.
 */
export default {
    // NOT `CraftTooltip`: a component resolves its own name, so a component
    // called CraftTooltip renders `<component :is="'craft-tooltip'">` as ITSELF
    // and recurses until the stack goes. The tag it hosts and the component
    // hosting it can't share a name.
    name: 'InfluxTooltip',

    props: {
        // The sentence. Both the tooltip's text and the trigger's accessible name.
        text: { type: String, required: true },
        // Passed through to Craft's element; inert on the Craft 4 fallback.
        placement: { type: String, default: 'top' },
        maxWidth: { type: String, default: '260px' },
        // Classes for the trigger itself — the caller's badge/pill styling.
        triggerClass: { type: [String, Array, Object], default: '' },
    },

    computed: {
        /**
         * Read per render rather than cached across the app: a custom element
         * can't be registered mid-render, but a test mounting both cases in one
         * file can register one between mounts.
         */
        native() {
            return ! window.customElements?.get?.('craft-tooltip');
        },

        tag() {
            return this.native ? 'span' : 'craft-tooltip';
        },
    },
};
</script>
