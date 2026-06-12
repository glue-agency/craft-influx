<template>
    <!-- Grouped options (relation/author match-by) and opted-in flat lists
         (mapping extras): the shared SearchableSelect — keyboard nav, the
         token picker's group headings and kind-colored chips, search box
         auto-hidden on short lists. -->
    <!-- Schema selects are closed enums: every option — including a
         value='' entry like the date format's "Auto-detect" — is a real,
         labeled choice, never a no-selection placeholder. -->
    <searchable-select
        v-if="grouped || searchable"
        :model-value="modelValue ?? ''"
        :options="node.options || []"
        placeholder="—"
        empty-is-value
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
        // Render flat option lists with SearchableSelect instead of the
        // native select — the mapping-extras rows opt in so every control
        // shares the node select's chrome; the stacked Auth-tab layout
        // keeps native selects.
        searchable: { type: Boolean, default: false },
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
