<template>
    <div class="influx-mapping-skeleton" role="status" aria-live="polite">
        <div class="influx-mapping-skeleton__head">
            <div class="spinner"></div>
            <span v-text="heading"></span>
        </div>

        <!-- aria-hidden: the bars carry no information a reader could use —
             the status line above is what announces the wait. -->
        <div
            v-for="(width, index) in widths"
            :key="index"
            class="influx-mapping-skeleton__row"
            aria-hidden="true"
        >
            <div class="influx-mapping-skeleton__meta">
                <span class="influx-skeleton-bar" :style="{ width }"></span>
                <span class="influx-skeleton-bar influx-skeleton-bar--handle"></span>
            </div>
            <span class="influx-skeleton-bar influx-skeleton-bar--control"></span>
            <span class="influx-skeleton-bar influx-skeleton-bar--control"></span>
        </div>
    </div>
</template>

<script>
/**
 * The mapping list's stand-in while its fields are being read: a mapping
 * group card with the same header, grid, padding and control height as the
 * real one ({@see styles/components/mapping-skeleton.css}), so the list
 * lands into the space its skeleton already held.
 *
 * Deliberately dumb — no store, no fetch. The caller decides when a wait is
 * happening and renders this instead of its list.
 */
export default {
    name: 'MappingSkeleton',

    props: {
        // What the status line says, for a caller whose wait isn't the
        // Mapping tab's. Falls back to this component's own wording.
        label: { type: String, default: null },
        // How many rows to stand in with. The count is a guess at the tree to
        // come either way, so it only has to look like a list, not match one.
        rows: { type: Number, default: 5 },
    },

    computed: {
        heading() {
            return this.label || this.$t('Reading mappable fields');
        },

        /**
         * Name-bar widths, uneven on purpose: bars of one length read as a
         * table of data rather than as a placeholder. Cycled so any row count
         * keeps the irregularity.
         */
        widths() {
            const pattern = ['58%', '42%', '66%', '35%', '50%'];

            return Array.from({ length: this.rows }, (_, i) => pattern[i % pattern.length]);
        },
    },
};
</script>
