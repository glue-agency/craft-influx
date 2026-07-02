<template>
    <div class="influx-stat-cell">
        <span v-if="label" class="influx-stat-eyebrow">{{ label }}</span>
        <span v-if="hasValue" class="influx-stat-value">{{ value }}</span>
        <slot v-else />
    </div>
</template>

<script>
/**
 * One labelled cell in a StatsGrid: an uppercase eyebrow over a value. Plain
 * facts pass `value`; cells with markup (selects, tokens, tag pills) fill the
 * default slot instead and keep their own value styling. Owns the cell layout
 * and eyebrow/value type treatment the log viewer (influx-log-cell/-eyebrow/
 * -cell-v) and debug inspector (influx-feed-cell/-eyebrow/-stat-v) used to
 * duplicate.
 */
export default {
    name: 'StatCell',

    props: {
        label: { type: String, default: '' },
        value: { type: [String, Number], default: null },
    },

    computed: {
        // 0 is a real value (the counters), so only null/undefined defer to
        // the slot.
        hasValue() {
            return this.value !== null && this.value !== undefined;
        },
    },
};
</script>

<style scoped>
.influx-stat-cell {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}

.influx-stat-eyebrow {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--medium-text-color);
}

.influx-stat-value {
    font-size: 13px;
    color: var(--text-color);
    word-break: break-word;
}
</style>
