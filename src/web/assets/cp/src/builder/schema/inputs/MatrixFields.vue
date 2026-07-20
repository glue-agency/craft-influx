<template>
    <!-- One card exists per block type and ALL of them render at once (Feed
         Me-style) — each card reads and writes only its own type's slice. A
         block type without custom fields still gets its card so the full
         type list stays visible — the empty hint says why there are no rows
         to map. -->
    <sub-field-rows
        :node="node"
        :rows="typeFields"
        :node-options="nodeOptions"
        :discovered-nodes="discoveredNodes"
        :read-only="readOnly"
        :empty-hint="$t('This block type has no mappable sub-fields.')"
        @update:rows="mergeTypeFields"
    />
</template>

<script>
import SubFieldRows from './SubFieldRows.vue';

/**
 * Schema matrixFields node: source-node + default rows for ONE Matrix block
 * type's custom fields, Feed Me-style — every block type's card renders at
 * once, each independently mappable. The shared SubFieldRows table owns the
 * card chrome and the row rewrites (see its docblock for the preserving
 * rows contract: a child row's unknown keys — `options`, nested `fields`,
 * … — round-trip untouched, and a row drops only when nothing is left).
 *
 * Contract: `modelValue` is the mapping's WHOLE `blocks` object
 * (`{<typeHandle>: {fields: {...}, ...}}`). The card renders only its own
 * `node.blockType` slice's `fields` map and emits full `blocks` replacements
 * that leave every other type's slice — and any unknown keys on its own
 * type's entry (`nativeFields`, …) — untouched. Taking the whole object
 * keeps the merge and its pruning next to the rewrite instead of splitting
 * them across SchemaForm.
 *
 * Matrix-specific rules:
 *   - node paths are ABSOLUTE item paths (`seasons.year`), resolved against
 *     the top-level item — never relative to the block;
 *   - emptied slices collapse away: a `fields` map with no rows drops off
 *     its type entry, and an entry left with nothing drops the type out of
 *     `blocks` (an all-empty `blocks` then prunes off the mapping in
 *     MappingRow.writeMapping()).
 */
export default {
    name: 'MatrixFields',

    components: { SubFieldRows },

    props: {
        node: { type: Object, required: true },
        // The mapping's whole `blocks` object — see the contract above.
        modelValue: { type: Object, default: () => ({}) },
        nodeOptions: { type: Array, default: () => [] },
        // The sample's discovered flatNodes — the "is the node still live"
        // signal. Null when no sample has been fetched. See SubFieldRows.
        discoveredNodes: { type: Array, default: null },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    computed: {
        /** This card's own slice: its block type's child `fields` map. */
        typeFields() {
            return this.modelValue[this.node.blockType]?.fields || {};
        },
    },

    methods: {
        /**
         * Merge the rewritten `fields` map back into the whole `blocks`
         * object: other types' slices pass through untouched, unknown keys
         * on this type's entry (`nativeFields`, …) survive, an emptied
         * `fields` map collapses off the entry, and an entry left with
         * nothing collapses the type out of `blocks`.
         */
        mergeTypeFields(nextFields) {
            const type = this.node.blockType;
            const entry = { ...(this.modelValue[type] || {}) };

            if (Object.keys(nextFields).length === 0) {
                delete entry.fields;
            } else {
                entry.fields = nextFields;
            }

            const next = { ...this.modelValue };
            if (Object.keys(entry).length === 0) {
                delete next[type];
            } else {
                next[type] = entry;
            }

            this.$emit('update:modelValue', next);
        },
    },
};
</script>
