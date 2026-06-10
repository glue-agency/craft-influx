<template>
    <div class="select">
        <select :value="modelValue ?? ''" :disabled="readOnly" @change="$emit('update:modelValue', $event.target.value)">
            <template v-for="(group, gi) in groups" :key="gi">
                <optgroup v-if="group.label" :label="group.label">
                    <option v-for="opt in group.options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </optgroup>
                <template v-else>
                    <option v-for="opt in group.options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </template>
            </template>
        </select>
    </div>
</template>

<script>
/**
 * Schema select node. Accepts both option shapes PHP ships: a flat
 * [{value, label}] list, or grouped [{label, options: [...]}] rendered as
 * optgroups (relation match-by).
 */
export default {
    name: 'SelectInput',

    props: {
        node: { type: Object, required: true },
        modelValue: { type: [String, Number], default: '' },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    computed: {
        groups() {
            const options = this.node.options || [];
            const grouped = options.length > 0 && Array.isArray(options[0]?.options);
            return grouped ? options : [{ label: null, options }];
        },
    },
};
</script>
