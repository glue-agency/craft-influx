<template>
    <div class="influx-mapping-extras" v-if="hasSchema" :data-expanded="expanded ? 'true' : 'false'">
        <div v-show="expanded" class="extras-body">
            <schema-form
                :schema="schema"
                :options="options"
                :native-fields="nativeFields"
                :fields="fields"
                :node-options="nodeOptions"
                :discovered-nodes="discoveredNodes"
                :read-only="readOnly"
                @update:options="onOptions"
                @update:native-fields="onNativeFields"
                @update:fields="onFields"
            />
        </div>
    </div>
</template>

<script>
import { store } from '../builder/store.js';
import { mergeNodeOptions, pruneEmpty } from '../builder/lib/mappings.js';
import SchemaForm from '../builder/schema/SchemaForm.vue';

/**
 * Per-field options block on a mapping row: a generic SchemaForm rendering
 * whatever node schema the PHP strategy declared via
 * Field::defineExtrasSchema(). No field-kind branches live here — adding a
 * mapping kind is a single-PHP-file change.
 *
 * Expansion is owned by MappingRow (the toggle rides on the row's handle
 * line); this component only mirrors it into `data-expanded`, which the
 * row's `:has()` tint selector in links.css keys off.
 *
 * Owns the local `options` / `nativeFields` / `fields` models (seeded from
 * the saved mapping) and re-emits them pruned, which is the shape that lands
 * in Project Config via MappingRow.writeMapping().
 */
export default {
    name: 'MappingExtras',

    components: { SchemaForm },

    props: {
        field: { type: Object, required: true },
        saved: { type: Object, required: true },
        expanded: { type: Boolean, default: false },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:options', 'update:nativeFields', 'update:fields'],

    data() {
        return {
            options: { ...(this.saved?.options || {}) },
            nativeFields: { ...(this.saved?.nativeFields || {}) },
            fields: { ...(this.saved?.fields || {}) },
        };
    },

    computed: {
        schema() {
            return this.field.fieldMeta?.schema || [];
        },

        hasSchema() {
            return this.schema.length > 0;
        },

        /**
         * Source-node candidates for sub-field dropdowns: the latest
         * Fetch-sample nodes straight off the store, merged with saved
         * sub-field paths so the dropdowns render before a sample exists.
         */
        nodeOptions() {
            const saved = [...Object.values(this.nativeFields), ...Object.values(this.fields)]
                .map((row) => row?.node)
                .filter(Boolean);
            return mergeNodeOptions(store.ui.sample?.flatNodes ?? [], saved);
        },

        /**
         * The raw discovered nodes — sub-field rows compare their saved
         * node against these for their own missing-mapping state. Null
         * (no sample yet) means "can't know", so nothing reads as missing.
         */
        discoveredNodes() {
            return store.ui.sample?.flatNodes ?? null;
        },
    },

    methods: {
        onOptions(next) {
            this.options = next;
            this.$emit('update:options', pruneEmpty(next));
        },

        onNativeFields(next) {
            this.nativeFields = next;
            this.$emit('update:nativeFields', next);
        },

        onFields(next) {
            this.fields = next;
            this.$emit('update:fields', next);
        },
    },
};
</script>

<style scoped>
/* Extras live below a `.influx-mapping-row` and span its full width
   (`grid-column: 1 / -1`). The grid that aligns rows with the parent's
   Field / Source-node / Default-value columns lives in SchemaForm.vue.
   The toggle moved up to the row's handle line (MappingRow), so this
   block starts flush under the row controls — collapsed it renders
   nothing at all. The expanded tint comes from the parent row's `:has()`
   selector in links.css. */

.influx-mapping-extras {
    margin-top: 0;
    background: transparent;
    border-top: 0;
}

.extras-body { padding: 0 0 4px; }
</style>
