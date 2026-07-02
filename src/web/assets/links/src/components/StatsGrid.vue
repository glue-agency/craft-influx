<template>
    <component
        :is="tag"
        class="influx-stats-grid"
        :class="{
            'influx-stats-grid--divided': divided,
            'influx-stats-grid--align-top': alignTop,
        }"
    >
        <slot />
    </component>
</template>

<script>
/**
 * The quarter-width facts grid the log viewer and the debug inspector share:
 * four equal columns (two below 740px), holding StatCell children. Cells
 * bottom-align by default so a controls row's inputs share a baseline
 * (DebugApp's site/window/limit form); fact rows pass `align-top` so labels
 * share a baseline instead. `tag` lets a consumer render the grid as a
 * `<form>` (DebugApp's controls row submits on Enter).
 */
export default {
    name: 'StatsGrid',

    props: {
        // Hairline top border — the counters row splitting off from the run
        // info inside the same panel.
        divided: { type: Boolean, default: false },
        // Top-align cells (facts); the default bottom-aligns them (controls).
        alignTop: { type: Boolean, default: false },
        tag: { type: String, default: 'div' },
    },
};
</script>

<style scoped>
.influx-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px 18px;
    align-items: end;
    padding: 16px 18px;
}

.influx-stats-grid--align-top { align-items: start; }

.influx-stats-grid--divided { border-top: 1px solid var(--hairline-color); }

@media (max-width: 740px) {
    .influx-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
