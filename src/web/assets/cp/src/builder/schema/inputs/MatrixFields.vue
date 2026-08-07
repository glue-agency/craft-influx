<template>
    <!-- One card exists per block type and ALL of them render at once (Feed
         Me-style) — each card reads and writes only its own type's slice. A
         block type without mappable sub-fields still gets its card so the full
         type list stays visible — the empty hint says why there are no rows
         to map. -->
    <v-sub-field-rows
        :node="node"
        :rows="typeRows"
        :node-options="nodeOptions"
        :discovered-nodes="discoveredNodes"
        :read-only="readOnly"
        :empty-hint="$t('This block type has no mappable sub-fields.')"
        @update:rows="mergeTypeRows"
    />

</template>

<script>
import SubFieldRows from './SubFieldRows.vue';
import { flattenChannels, splitChannels } from '../../lib/channels.js';

/**
 * Schema matrixFields node: source-node + default rows for ONE Matrix block
 * type's mappable sub-fields — its custom fields, plus the block's native
 * Title where the type has one — Feed Me-style: every block type's card
 * renders at once, each independently mappable. The shared SubFieldRows
 * table owns the card chrome and the row rewrites (see its docblock for the
 * preserving rows contract: a child row's unknown keys — `options`, nested
 * `fields`, … — round-trip untouched, and a row drops only when nothing is
 * left).
 *
 * Contract: the `blocks` channel this card binds is the mapping's WHOLE `blocks`
 * object (`{<typeHandle>: {fields: {...}, nativeFields: {...}}}`). The card renders
 * its own `node.blockType` slice as ONE row table and emits full `blocks`
 * replacements that leave every other type's slice — and any unknown keys on its
 * own type's entry — untouched. Taking the whole channel keeps the merge and its
 * pruning next to the rewrite instead of splitting them across the renderer.
 *
 * Channels: a sub-field node carries an optional `channel` key saying which
 * half of its type's entry the row is stored in — `nativeFields` for the
 * block's native Title, ABSENT for a custom field, which means `fields` (the
 * stored shape that predates the key). The two channels are one table to the
 * editor and are split apart again on every write; the arithmetic is shared
 * with the other two-channel card in {@see ../../lib/channels.js}, which this
 * calls with `fields` as the default. A handle can't collide across them in
 * practice (`title` is a reserved Craft field handle), but if one ever did
 * `nativeFields` would win deterministically — on the render and the write
 * alike.
 *
 * Matrix-specific rules:
 *   - what a node path is RELATIVE TO depends on the row's `blockSource`: under
 *     `grouped` it's an absolute item path (`seasons.year`), under the three
 *     list sources it's a path within one list item (`image`). The select
 *     offering the paths only ever discovered absolute ones, so a list source's
 *     paths are typed rather than picked — the select allows custom values;
 *   - emptied slices collapse away: a channel map with no rows drops off its
 *     type entry, and an entry left with nothing drops the type out of
 *     `blocks` (an all-empty `blocks` then prunes off the mapping in
 *     MappingRow.writeMapping()).
 */
export default {
    name: 'MatrixFields',

    inheritAttrs: false,

    emits: ['update:channels'],

    props: {
        node: { type: Object, required: true },
        // The stored channels this card binds — `blocks` alone, per its registry
        // entry (lib/slots.js). See the contract above.
        channels: { type: Object, default: () => ({}) },
        nodeOptions: { type: Array, default: () => [] },
        // The sample's discovered flatNodes — the "is the node still live"
        // signal. Null when no sample has been fetched. See SubFieldRows.
        discoveredNodes: { type: Array, default: null },
        readOnly: { type: Boolean, default: false },
    },

    computed: {
        blocks() {
            return this.channels.blocks || {};
        },

        /** This card's own type entry — both channels, or nothing saved yet. */
        typeEntry() {
            return this.blocks[this.node.blockType] || {};
        },

        /**
         * The rows the table renders: both of this type's channels flattened
         * into one map, since a row is addressed by handle either way (the
         * row ORDER comes from node.subFields, not from this map).
         */
        typeRows() {
            return flattenChannels(this.typeEntry);
        },
    },

    methods: {
        /**
         * Merge the rewritten rows back into the whole `blocks` object: the
         * rows are partitioned by channel onto this type's entry, other
         * types' slices pass through untouched, unknown keys on this type's
         * entry survive, an emptied channel collapses off the entry, and an
         * entry left with nothing collapses the type out of `blocks`.
         */
        mergeTypeRows(nextRows) {
            const type = this.node.blockType;
            const entry = { ...this.typeEntry };
            const channels = splitChannels(nextRows, this.node.subFields, this.typeEntry, 'fields');

            Object.entries(channels).forEach(([channel, rows]) => {
                if (Object.keys(rows).length === 0) {
                    delete entry[channel];
                } else {
                    entry[channel] = rows;
                }
            });

            const next = { ...this.blocks };
            if (Object.keys(entry).length === 0) {
                delete next[type];
            } else {
                next[type] = entry;
            }

            this.$emit('update:channels', { blocks: next });
        },
    },

    components: { 'v-sub-field-rows': SubFieldRows },
};
</script>
