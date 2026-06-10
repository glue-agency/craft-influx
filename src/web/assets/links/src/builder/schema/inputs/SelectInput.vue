<template>
    <!-- Grouped options (relation/author match-by): the shared
         SearchableSelect — search + keyboard nav, rendered with the token
         picker's group headings and kind-colored chips. -->
    <searchable-select
        v-if="grouped"
        :model-value="modelValue ?? ''"
        :options="node.options || []"
        placeholder="—"
        :disabled="readOnly"
        @update:model-value="$emit('update:modelValue', $event)"
    />

    <!-- Flat options: the plain native select. -->
    <div v-else class="select">
        <select :value="modelValue ?? ''" :disabled="readOnly" @change="$emit('update:modelValue', $event.target.value)">
            <option v-for="opt in (node.options || [])" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
    </div>
</template>

<script>
import SearchableSelect from '../../SearchableSelect.vue';

/**
 * Schema select node. Accepts both option shapes PHP ships: a flat
 * [{value, label}] list rendered as a native select, or grouped
 * [{label, kind?, options: [...]}] delegated to SearchableSelect's
 * grouped mode (searchable, token-picker group/chip visuals).
 */
export default {
    name: 'SelectInput',

    components: { SearchableSelect },

    props: {
        node: { type: Object, required: true },
        modelValue: { type: [String, Number], default: '' },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    computed: {
        grouped() {
            const options = this.node.options || [];
            return options.length > 0 && Array.isArray(options[0]?.options);
        },
    },
};
</script>
