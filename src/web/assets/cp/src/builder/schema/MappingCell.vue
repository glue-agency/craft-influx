<template>
    <component
        :is="controlFor(node)"
        v-for="(node, idx) in nodes"
        :key="node.handle || idx"
        :node="node"
        :model-value="valueFor(node)"
        :options="optionsFor(node)"
        :field-handle="pickerHandle"
        :read-only="readOnly"
        @update:model-value="$emit('update:mapping', writeNode(mapping, region, node, $event))"
    />
</template>

<script>
import { controlFor } from './registry.js';
import { readNode, writeNode } from '../lib/slots.js';


/**
 * One cell of a mapping row — the source-node cell or the default-value cell —
 * rendered from the region its field's strategy declared
 * (schema/MappingSchema.php).
 *
 * This replaces the branch chain each cell used to be. A cell doesn't know what
 * kind of field it belongs to, or which control it holds: it looks the node's
 * `type` up in the registry, asks `lib/slots.js` what the region reads and
 * writes, and binds the two together. So an Icon field gets an icon picker
 * because PHP said `icon`, not because anything here asks.
 *
 * Stateless. The row above owns the store, and this emits the whole new mapping —
 * so a cell can't half-apply a change that touches two slots, which the source
 * cell's sentinel does.
 */
export default {
    name: 'MappingCell',

    emits: ['update:mapping'],

    props: {
        // The declared nodes for this region. Empty renders nothing, which is
        // how a row with no cell of its own says so.
        nodes: { type: Array, default: () => [] },
        region: { type: String, required: true },
        // This field's stored mapping.
        mapping: { type: Object, default: () => ({}) },
        // Handle to shape a server-rendered picker after, or null. Only a CUSTOM
        // field's is meaningful — see ElementField.
        pickerHandle: { type: String, default: null },
        // Option rows for a select whose list is client state rather than the
        // node's own: the source cell's discovered sample nodes.
        options: { type: Array, default: null },
        readOnly: { type: Boolean, default: false },
    },

    methods: {
        controlFor,
        writeNode,

        valueFor(node) {
            return readNode(this.mapping, this.region, node);
        },

        /** A node carrying its own option list keeps it. */
        optionsFor(node) {
            return node.options ? null : this.options;
        },
    },
};
</script>
