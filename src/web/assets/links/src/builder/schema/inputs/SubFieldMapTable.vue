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
                    <option v-for="opt in nodeOptions" :key="opt.value" :value="opt.value">
                        {{ opt.label }}
                    </option>
                </select>
            </div>
            <input
                type="text"
                class="text"
                :value="rowFor(sub.handle).default"
                :placeholder="$t('Default')"
                :disabled="readOnly"
                @input="updateRow(sub.handle, 'default', $event.target.value)"
            >
        </div>
    </div>
</template>

<script>
/**
 * Schema subFieldMapTable node: node + default rows for a related element's
 * native sub-fields (asset alt/title). The wire value is the mapping's
 * recursive `nativeFields` channel — `{handle: {node?, default?}}` — with
 * fully-empty rows dropped.
 */
export default {
    name: 'SubFieldMapTable',

    props: {
        node: { type: Object, required: true },
        modelValue: { type: Object, default: () => ({}) },
        nodeOptions: { type: Array, default: () => [] },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    computed: {
        subFieldList() {
            const subs = this.node.subFields || {};
            return Object.keys(subs).map((handle) => ({ handle, label: subs[handle] }));
        },
    },

    methods: {
        rowFor(handle) {
            const saved = this.modelValue[handle] || {};
            return { node: saved.node || '', default: saved.default || '' };
        },

        updateRow(handle, key, value) {
            const row = { ...this.rowFor(handle), [key]: value };
            const next = { ...this.modelValue };

            if (row.node === '' && row.default === '') {
                delete next[handle];
            } else {
                next[handle] = {};
                if (row.node) next[handle].node = row.node;
                if (row.default) next[handle].default = row.default;
            }

            this.$emit('update:modelValue', next);
        },
    },
};
</script>
