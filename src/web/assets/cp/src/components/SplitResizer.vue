<template>
    <div
        :class="[variant === 'rail' ? 'influx-rail-resizer' : 'influx-split-resizer', { 'is-dragging': dragging }]"
        role="separator"
        aria-orientation="vertical"
        tabindex="0"
        :aria-label="label || $t('Resize the item list')"
        :aria-valuenow="currentWidth"
        :aria-valuemin="minWidth"
        :aria-valuemax="maxWidth"
        @pointerdown="startDrag"
        @pointermove="drag"
        @pointerup="endDrag"
        @pointercancel="endDrag"
        @lostpointercapture="endDrag"
        @keydown.left.prevent="nudge(-1)"
        @keydown.right.prevent="nudge(1)"
        @dblclick="reset"
    ></div>
</template>

<style scoped>
/* Negative margins cancel the width, so the handle sits on the seam the list
   pane's border draws without costing any layout: the panes render exactly as
   they did before it was there. Raised above them so the hit area wins the click
   over what it overlaps — which is deliberately almost all detail pane, because
   1px into the list is as much as it can take without swallowing the last few
   pixels of that pane's scrollbar. */
.influx-split-resizer {
    position: relative;
    z-index: 1;
    flex: none;
    width: 6px;
    margin-inline-start: -1px;
    margin-inline-end: -5px;
    background: transparent;
    cursor: col-resize;
    /* Without this a touch drag scrolls the page instead of resizing. */
    touch-action: none;
}

/* The visible affordance: nothing at rest (the pane's hairline is already
   there), a tinted bar over that hairline once the handle is live. */
.influx-split-resizer::before {
    content: '';
    position: absolute;
    inset-block: 0;
    inset-inline-start: 0;
    width: 2px;
    background: transparent;
    transition: background-color .12s;
}

.influx-split-resizer:hover::before,
.influx-split-resizer:focus-visible::before,
.influx-split-resizer.is-dragging::before {
    background: hsl(208, 100%, 42%);
}

/* The `rail` variant sizes a fixed-width side rail instead of a flex seam, so
   it can't sit in the layout: the rail's inline-start edge is already taken by
   Craft's collapse toggle. It floats over the rail's own leading padding
   (its containing block is the sticky #details-container). */
.influx-rail-resizer {
    position: absolute;
    z-index: 1;
    inset-block: 0;
    inset-inline-start: 0;
    width: 7px;
    cursor: col-resize;
    touch-action: none;
}

.influx-rail-resizer::before {
    content: '';
    position: absolute;
    inset-block: 0;
    inset-inline-start: 2px;
    width: 2px;
    border-radius: 1px;
    background: transparent;
    transition: background-color .12s;
}

.influx-rail-resizer:hover::before,
.influx-rail-resizer:focus-visible::before,
.influx-rail-resizer.is-dragging::before {
    background: hsl(208, 100%, 42%);
}
</style>

<script>
/** Never narrower than this, however wide the split gets. */
const MIN_WIDTH = 240;

/** Each arrow-key press moves the seam this far. */
const STEP_WIDTH = 16;

/**
 * The drag handle that sizes a pane: pointer drag, arrow keys (a focusable
 * `separator`, the ARIA window-splitter pattern) and a double-click back to the
 * stylesheet's default.
 *
 * It owns the width outright and emits nothing: the number goes onto a HOST
 * element as a custom property, which some CSS rule reads. Living on a host
 * rather than on the pane is what makes the width survive the pane's contents
 * being swapped out (DrillList replaces the item list inside the same wrapper
 * while a reader is drilled in).
 *
 * Two wirings, per `variant`:
 *   - `split` (default) — the seam between two flex panes. The host is
 *     `$el.parentElement` (the split container) and the pane it sizes is
 *     `$el.previousElementSibling`, which holds because the handle is only ever
 *     rendered as a direct child of `.influx-split`, right after the list.
 *     Dragging toward the inline-end widens.
 *   - `rail` — a fixed-width side rail named by `target`, which is BOTH the
 *     host and the pane; the ceiling is measured against its parent. Dragging
 *     toward the inline-start widens, since the rail sits at the inline-end.
 *
 * Widths persist to localStorage under `storageKey`. Every access is guarded:
 * in private mode the read and the write both throw, and a handle that can't
 * remember its width still resizes.
 */
export default {
    name: 'SplitResizer',

    props: {
        /** `split` (a seam between panes) or `rail` (a fixed-width side rail). */
        variant: { type: String, default: 'split' },
        // The rail to size — required by, and only read for, variant="rail".
        // An element rather than a selector so the caller resolves it however
        // it likes (the details sidebar walks up from its teleport slot).
        target: { type: Object, default: null },
        /** Where the width lands on the host, for the consuming CSS to read. */
        cssVar: { type: String, default: '--influx-split-list-width' },
        /**
         * One shared key per visual element. Both inspectors' lists share the
         * default: a reader who widens it on the log view expects the debug
         * view to have widened too.
         */
        storageKey: { type: String, default: 'influx:splitListWidth' },
        /** Overrides the default separator label. */
        label: { type: String, default: '' },
    },

    data() {
        return {
            // The applied width in px, or null while the stylesheet's default
            // is still in force (nothing written to the container).
            width: null,
            // What that default measures, sampled once before anything is
            // applied — only so the ARIA value and the first drag have a real
            // number to start from.
            defaultWidth: MIN_WIDTH,
            // Half the container: sampled at mount, at each drag start and at
            // each key step, never per pointermove — a ceiling that shifted
            // mid-drag would move under the pointer.
            maxWidth: MIN_WIDTH,
            dragging: false,
            // Where the drag began, so a move applies a delta from there
            // rather than an absolute (the handle is not the seam it moves).
            originX: 0,
            originWidth: 0,
            minWidth: MIN_WIDTH,
            container: null,
        };
    },

    computed: {
        // What the seam sits at right now: the applied width, or the
        // stylesheet's default while nothing has been applied.
        currentWidth() {
            return this.width !== null ? this.width : this.defaultWidth;
        },

        isRail() {
            return this.variant === 'rail';
        },
    },

    mounted() {
        this.container = this.isRail ? this.target : this.$el.parentElement;
        this.defaultWidth = Math.round(this.measure(this.pane())) || MIN_WIDTH;
        this.sampleContainer();

        const stored = this.readStored();

        if (stored !== null) {
            this.apply(this.clamp(stored));
        }
    },

    methods: {
        startDrag(event) {
            this.sampleContainer();
            this.dragging = true;
            this.originX = event.clientX;
            this.originWidth = this.currentWidth;

            // Capture keeps the move/up events coming to the handle even once
            // the pointer has left it, so no document-level listeners are
            // needed (and none can outlive the component).
            if (this.$el.setPointerCapture) {
                this.$el.setPointerCapture(event.pointerId);
            }

            if (this.container) {
                this.container.classList.add('influx-is-resizing');
            }
        },

        drag(event) {
            if (! this.dragging) {
                return;
            }

            // A rail lives at the inline-end, so dragging its handle toward the
            // inline-start is what widens it.
            const travelled = (event.clientX - this.originX) * (this.isRail ? -1 : 1);

            this.apply(this.clamp(this.originWidth + travelled));
        },

        endDrag() {
            if (! this.dragging) {
                return;
            }

            this.dragging = false;

            if (this.container) {
                this.container.classList.remove('influx-is-resizing');
            }

            this.store();
        },

        // Arrow-key resize, one step per press, same clamp — and the same
        // inverted travel — as the drag.
        nudge(direction) {
            this.sampleContainer();
            const step = direction * STEP_WIDTH * (this.isRail ? -1 : 1);
            this.apply(this.clamp(this.currentWidth + step));
            this.store();
        },

        // Double-click: hand the width back to the stylesheet, and forget it
        // so the next visit starts there too.
        reset() {
            this.width = null;

            if (this.container) {
                this.container.style.removeProperty(this.cssVar);
            }

            try {
                window.localStorage.removeItem(this.storageKey);
            } catch (e) {
                // Private mode: nothing was stored to forget.
            }
        },

        apply(width) {
            this.width = width;

            if (this.container) {
                this.container.style.setProperty(this.cssVar, `${width}px`);
            }
        },

        // Between the floor and the ceiling, the floor winning if the split is
        // ever too narrow to grant it — a legible list beats a legible detail.
        clamp(width) {
            return Math.round(Math.min(Math.max(width, MIN_WIDTH), Math.max(this.maxWidth, MIN_WIDTH)));
        },

        // Half of whatever the pane sits inside, so it can never crowd the rest
        // of the screen out.
        sampleContainer() {
            this.maxWidth = Math.round(this.measure(this.bounds()) / 2) || MIN_WIDTH;
        },

        /** The element being sized — the rail itself, or the pane before the seam. */
        pane() {
            return this.isRail ? this.container : this.$el.previousElementSibling;
        },

        /** What the pane's ceiling is measured against. */
        bounds() {
            return this.isRail ? this.container?.parentElement : this.container;
        },

        measure(el) {
            return el ? el.getBoundingClientRect().width : 0;
        },

        readStored() {
            try {
                const stored = parseInt(window.localStorage.getItem(this.storageKey), 10);

                return Number.isFinite(stored) ? stored : null;
            } catch (e) {
                return null;
            }
        },

        store() {
            if (this.width === null) {
                return;
            }

            try {
                window.localStorage.setItem(this.storageKey, String(this.width));
            } catch (e) {
                // Private mode: the width holds for this page, just not beyond.
            }
        },
    },
};
</script>
