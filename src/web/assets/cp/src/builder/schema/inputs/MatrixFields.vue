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
        :read-only="readOnly || lockedOut"
        :notice="lockedOut ? lockedOutHint : null"
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
 *   - node paths are RELATIVE to one item of the list the Matrix row names
 *     (`image`), never absolute against the whole feed item. Discovery only
 *     ever produced absolute paths, so these are typed rather than picked —
 *     the select allows custom values, and gets handed no discovered nodes to
 *     check them against (MappingExtras.discoveredNodesFor);
 *   - a single-type list locks every card but the one already mapped
 *     ({@see lockedOut}), which is why this card reads the row's options;
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
        // The Matrix row's own options — read for `blockSource`, which decides
        // whether more than one block type may be mapped at all.
        mappingOptions: { type: Object, default: () => ({}) },
        readOnly: { type: Boolean, default: false },
    },

    computed: {
        blocks() {
            return this.channels.blocks || {};
        },

        /** The handles of the OTHER block types that already carry rows. */
        otherMappedTypes() {
            return Object.keys(this.blocks).filter((type) => (
                type !== this.node.blockType && Object.keys(flattenChannels(this.blocks[type])).length > 0
            ));
        },

        /**
         * Whether this card is closed for business: a single-type list has one
         * block type by definition, so once another card carries rows an EMPTY
         * one can't start a second. Locked rather than hidden — switching
         * sources shouldn't make an operator's existing work disappear, and
         * clearing the other card's rows re-opens the choice.
         *
         * A card carrying rows of its own is never locked, which is the part
         * that matters: switching an already-two-type mapping to a single-type
         * list would otherwise lock BOTH cards, and "clear nodes" is itself
         * gated on the card being editable — no way out but changing the
         * setting back. Leaving populated cards open means the conflict is
         * always resolvable where it is. The strategy still throws on two mapped
         * types at sync time, which is the backstop for config written outside
         * the builder.
         */
        lockedOut() {
            return this.mappingOptions.blockSource === 'listSingle'
                && Object.keys(this.typeRows).length === 0
                && this.otherMappedTypes.length > 0;
        },

        lockedOutHint() {
            return this.$t('A single-type list maps one block type, and “{type}” is already mapped.', {
                type: this.otherMappedTypes[0],
            });
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
