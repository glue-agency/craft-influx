<template>
    <v-sub-field-rows
        :node="node"
        :rows="channels.fields || {}"
        :node-options="nodeOptions"
        :discovered-nodes="discoveredNodes"
        :read-only="readOnly"
        @update:rows="$emit('update:channels', { fields: $event })"
    />
</template>

<script>
import SubFieldRows from './SubFieldRows.vue';

/**
 * Schema subFields node: source-node + default rows for the sub-fields a field
 * owns ITSELF — a Table field's columns, an Addresses field's parts, a Link
 * field's url/label pair.
 *
 * One channel only, which is the whole difference from its two siblings: these
 * rows are the field's own value, not another element's, so they all land in the
 * mapping's flat `fields` channel and there is nothing to partition. That makes
 * this a straight adapter between the `channels` contract every container speaks
 * and the `rows` contract the shared {@see SubFieldRows} table speaks.
 */
export default {
    name: 'SubFields',

    inheritAttrs: false,

    emits: ['update:channels'],

    props: {
        node: { type: Object, required: true },
        // The stored channels this card binds — `fields` alone, per its registry
        // entry (lib/slots.js).
        channels: { type: Object, default: () => ({}) },
        nodeOptions: { type: Array, default: () => [] },
        discoveredNodes: { type: Array, default: null },
        readOnly: { type: Boolean, default: false },
    },

    components: { 'v-sub-field-rows': SubFieldRows },
};
</script>
