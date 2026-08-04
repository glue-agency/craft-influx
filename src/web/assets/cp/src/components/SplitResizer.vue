<template>
    <div
        class="influx-split-resizer"
        :class="{ 'is-dragging': dragging }"
        role="separator"
        aria-orientation="vertical"
        tabindex="0"
        :aria-label="$t('Resize the item list')"
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

/* Hover only thickens the seam, in grey: the saturated blue read as a divider
   the page owns rather than a handle the reader can move, and it stayed put as
   long as the pointer rested there. Blue is kept for the two states where the
   seam IS being moved — a live drag, and a keyboard focus whose arrow keys
   will. */
.influx-split-resizer:hover::before {
    background: var(--gray-300);
}

.influx-split-resizer:focus-visible::before,
.influx-split-resizer.is-dragging::before {
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
 * The host is `$el.parentElement` (the split container) and the pane it sizes is
 * `$el.previousElementSibling`, which holds because the handle is only ever
 * rendered as a direct child of `.influx-split`, right after the list.
 *
 * Widths persist to localStorage under `storageKey`. Every access is guarded:
 * in private mode the read and the write both throw, and a handle that can't
 * remember its width still resizes.
 */
export default {
    name: 'SplitResizer',

    props: {
        /** Where the width lands on the host, for the consuming CSS to read. */
        cssVar: { type: String, default: '--influx-split-list-width' },
        /**
         * One shared key per visual element. Both inspectors' lists share the
         * default: a reader who widens it on the log view expects the debug
         * view to have widened too.
         */
        storageKey: { type: String, default: 'influx:splitListWidth' },
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
    },

    mounted() {
        this.container = this.$el.parentElement;
        this.defaultWidth = Math.round(this.measure(this.$el.previousElementSibling)) || MIN_WIDTH;
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

            this.apply(this.clamp(this.originWidth + (event.clientX - this.originX)));
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

        // Arrow-key resize, one step per press, same clamp as the drag.
        nudge(direction) {
            this.sampleContainer();
            this.apply(this.clamp(this.currentWidth + (direction * STEP_WIDTH)));
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
            this.maxWidth = Math.round(this.measure(this.container) / 2) || MIN_WIDTH;
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
