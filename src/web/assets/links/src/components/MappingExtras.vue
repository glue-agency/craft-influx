<template>
    <div class="influx-mapping-extras" v-if="hasSchema" :data-expanded="expanded ? 'true' : 'false'">
        <div class="extras-header">
            <button
                type="button"
                class="extras-toggle"
                :aria-expanded="expanded ? 'true' : 'false'"
                @click="expanded = !expanded"
            >
                <span class="chevron">{{ expanded ? '▼' : '▶' }}</span>
                {{ expanded ? toggleLabels.hideOptions : toggleLabels.configure }}
            </button>
        </div>

        <div v-show="expanded" class="extras-body">
            <schema-form
                :schema="schema"
                :options="options"
                :native-fields="nativeFields"
                :node-options="nodeOptions"
                :read-only="readOnly"
                @update:options="onOptions"
                @update:native-fields="onNativeFields"
            />
        </div>
    </div>
</template>

<script>
import { store } from '../builder/store.js';
import { mergeNodeOptions, pruneEmpty } from '../builder/lib/mappings.js';
import SchemaForm from '../builder/schema/SchemaForm.vue';

/**
 * Per-field options block on a mapping row. Pure chrome: the expand/collapse
 * toggle plus a generic SchemaForm rendering whatever node schema the PHP
 * strategy declared via Field::defineExtrasSchema(). No field-kind branches
 * live here — adding a mapping kind is a single-PHP-file change.
 *
 * Owns the local `options` / `nativeFields` models (seeded from the saved
 * mapping) and re-emits them pruned, which is the shape that lands in
 * Project Config via MappingRow.writeMapping().
 */
export default {
    name: 'MappingExtras',

    components: { SchemaForm },

    props: {
        field: { type: Object, required: true },
        saved: { type: Object, required: true },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:options', 'update:nativeFields'],

    data() {
        return {
            expanded: true,
            options: { ...(this.saved?.options || {}) },
            nativeFields: { ...(this.saved?.nativeFields || {}) },
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
         * Toggle copy ships translated through fieldMeta.labels (the shared
         * commonExtrasLabels set); fall back to the raw strings for metas
         * that don't carry labels.
         */
        toggleLabels() {
            const labels = this.field.fieldMeta?.labels || {};
            return {
                configure: labels.configure || 'Configure',
                hideOptions: labels.hideOptions || 'Hide options',
            };
        },

        /**
         * Source-node candidates for sub-field dropdowns: the latest
         * Fetch-sample nodes straight off the store, merged with saved
         * sub-field paths so the dropdowns render before a sample exists.
         */
        nodeOptions() {
            const saved = Object.values(this.nativeFields)
                .map((row) => row?.node)
                .filter(Boolean);
            return mergeNodeOptions(store.state.sample?.flatNodes ?? [], saved);
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
    },
};
</script>

<style scoped>
/* Extras live below a `.influx-mapping-row` and span its full width
   (`grid-column: 1 / -1`). The grid that aligns rows with the parent's
   Field / Source-node / Default-value columns lives in SchemaForm.vue —
   this block only styles the toggle chrome.
   Collapsed: render as a plain "▶ Configure" toggle, nothing else.
   Expanded: the parent row is tinted via `:has()` in links.css; the
   body sits flush below it inheriting that same tint. */

.influx-mapping-extras {
    margin-top: 0;
    background: transparent;
    border-top: 0;
}

.extras-header {
    padding: 4px 0;
}

.extras-toggle {
    background: none;
    border: 0;
    padding: 0;
    cursor: pointer;
    color: #364d6b;
    font-size: 12px;
}

.extras-toggle:hover { color: #1f3454; }

.extras-toggle .chevron { display: inline-block; margin-right: 4px; }

.extras-body { padding: 4px 0; }
</style>
