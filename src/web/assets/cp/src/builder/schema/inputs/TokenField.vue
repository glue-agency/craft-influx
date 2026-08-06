<template>
    <v-tokenized-input
        :model-value="modelValue ?? ''"
        :token-groups="tokenGroups"
        :placeholder="node.placeholder || ''"
        :disabled="readOnly"
        @update:model-value="$emit('update:modelValue', $event)"
    />
</template>

<script>
import TokenizedInput from '../../TokenizedInput.vue';

/**
 * A text value that may reference an `.env` variable (`$VAR`), a Craft alias
 * (`@alias`) or any custom token group — an auth strategy's token or header
 * value, chiefly.
 *
 * The suggestion groups are client state (they ride the bootstrap meta, not the
 * schema), so they arrive as a prop rather than on the node. PHP consumers must
 * still run the stored value through `craft\helpers\App::parseEnv()`.
 */
export default {
    name: 'TokenField',

    inheritAttrs: false,

    emits: ['update:modelValue'],

    props: {
        node: { type: Object, required: true },
        modelValue: { type: [String, Number, null], default: null },
        tokenGroups: { type: Array, default: () => [] },
        readOnly: { type: Boolean, default: false },
    },

    components: { 'v-tokenized-input': TokenizedInput },
};
</script>
