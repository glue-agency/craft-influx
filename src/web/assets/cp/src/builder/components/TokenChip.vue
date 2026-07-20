<template>
    <span class="influx-tokenized-chip" :data-kind="kind || 'custom'">
        <span class="chip-name">{{ name }}</span>
        <button
            v-if="!disabled"
            type="button"
            class="chip-remove"
            tabindex="-1"
            :aria-label="$t('Remove {name}', { name })"
            @mousedown.prevent
            @click.stop="$emit('remove')"
        >
            <svg width="8" height="8" viewBox="0 0 8 8" aria-hidden="true">
                <path d="M1 1l6 6M7 1l-6 6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
            </svg>
        </button>
    </span>
</template>

<script>
/** One inline token chip with its remove button. */
export default {
    name: 'TokenChip',

    props: {
        name: { type: String, required: true },
        kind: { type: String, default: 'custom' },
        disabled: { type: Boolean, default: false },
    },

    emits: ['remove'],
};
</script>
