<template>
    <div class="sub-field-rows">
        <div class="sub-field-row" v-for="sub in subFieldList" :key="sub.handle">
            <label>{{ sub.label }}</label>
            <div class="select">
                <select
                    :value="rowFor(sub.handle).node"
                    :disabled="readOnly"
                    @change="updateRow(sub.handle, 'node', $event.target.value)"
                >
                    <option value="">{{ $t('— no mapping —') }}</option>
                    <option value="__default__">{{ $t('— use default —') }}</option>
                    <option v-for="opt in nodeOptions" :key="opt.value" :value="opt.value">
                        {{ opt.label }}
                    </option>
                </select>
            </div>
            <!-- The default-value editor renders by the sub-field node's own
                 type — the same primitives the rest of the schema uses. -->
            <select-input
                v-if="sub.type === 'select'"
                :node="sub"
                :model-value="rowFor(sub.handle).default"
                :read-only="readOnly"
                @update:model-value="updateRow(sub.handle, 'default', $event)"
            />
            <input
                v-else
                type="text"
                :class="['text', sub.type === 'code' ? 'code' : null]"
                :value="rowFor(sub.handle).default"
                :placeholder="sub.placeholder || $t('Default')"
                :disabled="readOnly"
                @input="updateRow(sub.handle, 'default', $event.target.value)"
            >
        </div>
    </div>
</template>

<script>
import SelectInput from './SelectInput.vue';

/**
 * Schema elementSubFields node: source-node + default rows for a related
 * element's native sub-fields (asset alt/title). Each sub-field IS a
 * primitive schema node — its handle/label name the row and its type
 * renders the default-value editor — while the table contributes the
 * source-node select and writes the mapping's recursive `nativeFields`
 * channel: `{handle: {node?, default?, useDefault?}}`, fully-empty rows
 * dropped.
 */
export default {
    name: 'ElementSubFields',

    components: { SelectInput },

    props: {
        node: { type: Object, required: true },
        modelValue: { type: Object, default: () => ({}) },
        nodeOptions: { type: Array, default: () => [] },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    computed: {
        /** @returns the sub-field nodes (BuilderSchema primitives). */
        subFieldList() {
            return this.node.subFields || [];
        },
    },

    methods: {
        // `__default__` is the same UI-only sentinel MappingRow uses: it
        // round-trips to the row's `useDefault` flag, never the wire node.
        rowFor(handle) {
            const saved = this.modelValue[handle] || {};
            return {
                node: saved.useDefault ? '__default__' : (saved.node || ''),
                default: saved.default || '',
            };
        },

        updateRow(handle, key, value) {
            const row = { ...this.rowFor(handle), [key]: value };
            const next = { ...this.modelValue };

            const useDefault = row.node === '__default__';
            const node = useDefault ? '' : row.node;

            if (node === '' && row.default === '' && !useDefault) {
                delete next[handle];
            } else {
                next[handle] = {};
                if (node) next[handle].node = node;
                if (row.default) next[handle].default = row.default;
                if (useDefault) next[handle].useDefault = true;
            }

            this.$emit('update:modelValue', next);
        },
    },
};
</script>
