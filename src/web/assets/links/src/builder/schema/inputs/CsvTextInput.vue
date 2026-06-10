<template>
    <input
        type="text"
        class="text"
        :value="text"
        :placeholder="node.placeholder || ''"
        :disabled="readOnly"
        @input="onInput($event.target.value)"
    >
</template>

<script>
/**
 * Schema csvText node: a comma-separated text input that round-trips a
 * string-list option (e.g. Lightswitch truthy values). Emits undefined
 * when emptied so the option prunes away instead of saving [].
 */
export default {
    name: 'CsvTextInput',

    props: {
        node: { type: Object, required: true },
        modelValue: { type: Array, default: undefined },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    computed: {
        text() {
            return Array.isArray(this.modelValue) ? this.modelValue.join(', ') : '';
        },
    },

    methods: {
        onInput(raw) {
            const parsed = raw
                .split(',')
                .map((s) => s.trim())
                .filter((s) => s.length > 0);
            this.$emit('update:modelValue', parsed.length ? parsed : undefined);
        },
    },
};
</script>
