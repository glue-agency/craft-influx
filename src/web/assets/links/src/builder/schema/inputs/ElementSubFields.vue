<template>
    <sub-field-rows
        :node="node"
        :rows="modelValue"
        :node-options="nodeOptions"
        :discovered-nodes="discoveredNodes"
        :read-only="readOnly"
        @update:rows="$emit('update:modelValue', $event)"
    />
</template>

<script>
import SubFieldRows from './SubFieldRows.vue';

/**
 * Schema elementSubFields node: source-node + default rows for a related
 * element's native sub-fields (asset alt/title). Each sub-field IS a
 * primitive schema node — its handle/label name the row and its type
 * renders the default-value editor — while the shared SubFieldRows table
 * contributes the card chrome, the source-node select and the row
 * rewrites (see its docblock for the preserving rows contract).
 *
 * The wire shape is the thinnest possible: `modelValue` IS the rows map —
 * the mapping's recursive `nativeFields` channel, `{handle: {node?,
 * default?, useDefault?}}`, fully-empty rows dropped — so edits pass
 * straight through in both directions.
 */
export default {
    name: 'ElementSubFields',

    components: { SubFieldRows },

    props: {
        node: { type: Object, required: true },
        modelValue: { type: Object, default: () => ({}) },
        nodeOptions: { type: Array, default: () => [] },
        // The sample's discovered flatNodes — the "is the node still live"
        // signal. Null when no sample has been fetched. See SubFieldRows.
        discoveredNodes: { type: Array, default: null },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],
};
</script>
