<template>
    <v-sub-field-rows
        :node="node"
        :rows="rows"
        :node-options="nodeOptions"
        :discovered-nodes="discoveredNodes"
        :read-only="readOnly"
        @update:rows="writeRows"
    />
</template>

<script>
import SubFieldRows from './SubFieldRows.vue';
import { flattenChannels, splitChannels } from '../../lib/channels.js';

/**
 * Schema elementSubFields node: source-node + default rows for the sub-fields
 * of the element a mapping relates — a related entry's title, an asset's alt
 * text, the volumes' or the section's own custom fields. Each sub-field IS a
 * primitive schema node — its handle/label name the row and its type renders
 * the default-value editor — while the shared SubFieldRows table contributes
 * the card chrome, the source-node select and the row rewrites (see its
 * docblock for the preserving rows contract).
 *
 * ONE card over TWO mapping channels, because the element's own attributes and
 * its layout's custom fields are written differently: a row's optional
 * `channel` key says which it lands in — `fields` for a custom field, ABSENT
 * for a native, which means `nativeFields` (the channel this node's rows were
 * stored in before the key existed, and the handle SchemaBuilder forces on it).
 * The split and the join are shared with the Matrix card in
 * {@see ../../lib/channels.js}; only the default channel differs.
 *
 * Both channels are emitted on EVERY write, each a full replacement, because a
 * handle can change channel — a saved `fields.alt` on a volume that later gains
 * a native Alt field moves home, and only writing both deletes the old one.
 * Both partitions are computed before either emit, so the second write can't be
 * built from props the first superseded, and a redundant identical write costs
 * nothing: the dirty flag is a derived JSON signature, not a touch counter.
 */
export default {
    name: 'ElementSubFields',

    emits: ['update:fields', 'update:nativeFields'],

    props: {
        node: { type: Object, required: true },
        // The mapping's two sub-field channels, each `{handle: {node?,
        // default?, useDefault?}}` with fully-empty rows dropped.
        fields: { type: Object, default: () => ({}) },
        nativeFields: { type: Object, default: () => ({}) },
        nodeOptions: { type: Array, default: () => [] },
        // The sample's discovered flatNodes — the "is the node still live"
        // signal. Null when no sample has been fetched. See SubFieldRows.
        discoveredNodes: { type: Array, default: null },
        readOnly: { type: Boolean, default: false },
    },

    computed: {
        /** Both channels as one table — a row is addressed by handle either way. */
        rows() {
            return flattenChannels(this.saved);
        },

        saved() {
            return { fields: this.fields, nativeFields: this.nativeFields };
        },
    },

    methods: {
        writeRows(nextRows) {
            const channels = splitChannels(nextRows, this.node.subFields, this.saved, 'nativeFields');

            this.$emit('update:fields', channels.fields);
            this.$emit('update:nativeFields', channels.nativeFields);
        },
    },

    components: { 'v-sub-field-rows': SubFieldRows },
};
</script>
