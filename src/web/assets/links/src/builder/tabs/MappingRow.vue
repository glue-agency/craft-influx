<template>
    <div class="influx-mapping-row"
         :class="{ 'has-extras': hasExtras }"
         :data-field-handle="field.handle"
         :data-missing="isMissing ? 'true' : 'false'"
    >
        <!-- The whole meta cell toggles the extras: the chevron button has
             no handler of its own — its (mouse or keyboard) click bubbles
             up to the cell, so there's exactly one toggle path while the
             button keeps carrying focus and aria-expanded. -->
        <div class="meta"
             :class="{ 'is-toggleable': hasExtras }"
             @click="toggleExtras"
        >
            <!-- Disclosure chevron in the row's left gutter — same visual
                 vocabulary as the group headers. Every row reserves the
                 gutter so field names align whether or not a chevron
                 exists. -->
            <button
                v-if="hasExtras"
                type="button"
                class="extras-chevron"
                :class="{ collapsed: !extrasExpanded }"
                :aria-expanded="extrasExpanded ? 'true' : 'false'"
                :aria-label="extrasExpanded ? toggleLabels.hideOptions : toggleLabels.configure"
                :title="extrasExpanded ? toggleLabels.hideOptions : toggleLabels.configure"
            >
                <span aria-hidden="true">▼</span>
            </button>

            <span class="name">{{ field.name }}</span>

            <span v-if="isMissing"
                  class="influx-missing-badge"
                  :title="$t('Saved source node is no longer in the fetched sample. Pick a new one or clear the mapping.')">
                {{ $t('missing mapping') }}
            </span>

            <code class="handle light">{{ field.handle }}</code>
        </div>

        <!-- subfieldsOnly fields (fieldMeta flag, e.g. Matrix) carry no source
             node or default of their own — their value derives entirely from
             the extras below. The cells stay so the row keeps the shared grid
             columns; they just render empty. -->
        <div>
            <searchable-select
                v-if="!subfieldsOnly"
                :model-value="mapping.useDefault ? '__default__' : (mapping.node ?? '')"
                :options="sourceNodeOptions"
                :disabled="readOnly"
                searchable
                :placeholder="$t('— no mapping —')"
                :search-placeholder="$t('Search nodes…')"
                :empty-label="$t('Run “Fetch sample” to discover nodes.')"
                @update:model-value="onNodeSelect"
            />
        </div>

        <div>
            <!-- Default-value editor. Three shapes:
                 - `select` → searchable single-select
                 - `element` → not yet supported in the SPA (followup)
                 - anything else → plain text -->
            <template v-if="subfieldsOnly" />
            <template v-else-if="field.defaultType === 'select'">
                <searchable-select
                    :model-value="mapping.default ?? ''"
                    :options="defaultSelectOptions"
                    :disabled="readOnly"
                    :search-placeholder="$t('Search options…')"
                    @update:model-value="onDefaultSelect"
                />
            </template>
            <template v-else-if="field.defaultType === 'element'">
                <element-picker
                    :model-value="mapping.default"
                    :element-type="field.elementType || 'craft\\elements\\Entry'"
                    @update:model-value="onDefaultElementChange"
                />
            </template>
            <template v-else>
                <input type="text"
                       class="text fullwidth"
                       :value="mapping.default ?? ''"
                       :disabled="readOnly"
                       @input="onDefaultChange" />
            </template>
        </div>

        <!-- Per-field options block: a generic SchemaForm rendering whatever
             node schema the PHP strategy declared via
             Field::schema(). No field-kind branches live here —
             adding a mapping kind is a single-PHP-file change. The
             `data-expanded` attribute mirrors the toggle state for the row's
             `:has()` tint selector in links.css. -->
        <div v-if="hasExtras"
             class="influx-mapping-extras influx-mapping-extras-slot"
             :data-expanded="extrasExpanded ? 'true' : 'false'"
        >
            <div v-show="extrasExpanded" class="extras-body">
                <schema-form
                    :schema="extrasSchema"
                    :options="extrasOptions"
                    :native-fields="extrasNativeFields"
                    :blocks="extrasBlocks"
                    :node-options="extrasNodeOptions"
                    :discovered-nodes="discoveredNodes"
                    :read-only="readOnly"
                    @update:options="onOptionsUpdate"
                    @update:native-fields="onNativeFieldsUpdate"
                    @update:blocks="onBlocksUpdate"
                />
            </div>
        </div>
    </div>
</template>

<script>
import ElementPicker from '../ElementPicker.vue';
import SearchableSelect from '../SearchableSelect.vue';
import SchemaForm from '../schema/SchemaForm.vue';
import { store } from '../store.js';
import { mergeNodeOptions, pruneEmpty, setMappingSlot } from '../lib/mappings.js';

/**
 * One row in the Mapping tab. Renders the field name, source-node select,
 * default-value editor, and optionally an extras subform for complex field
 * types — a SchemaForm driven by the strategy's declared schema. Writes
 * straight back into `link.mappings[handle]` on the reactive store; the
 * parent watches the store via the dirty flag.
 *
 * The row owns the extras' local `extrasOptions` / `extrasNativeFields` /
 * `extrasBlocks` models (seeded from the saved mapping) and writes them
 * pruned via writeMapping(), which is the shape that lands in Project
 * Config.
 */
export default {
    name: 'MappingRow',

    components: { ElementPicker, SearchableSelect, SchemaForm },

    props: {
        field: { type: Object, required: true },
        // Available source-node candidates from the latest Fetch sample,
        // shape `[{value, label}]`. Empty when no sample has been run yet.
        nodeOptions: { type: Array, default: () => [] },
    },

    data() {
        const saved = store.link.mappings?.[this.field.handle] || {};

        return {
            // Rows with a saved mapping start with their extras open.
            extrasExpanded: Object.keys(saved).length > 0,
            // Local extras models, seeded once from the saved mapping —
            // SchemaForm edits land here first, then flow to the store
            // pruned via writeMapping(). `extrasNativeFields` /
            // `extrasBlocks` re-hydrate saved sub-field mappings (asset
            // alt/title, Matrix per-block-type children) on edit.
            extrasOptions: { ...(saved.options || {}) },
            extrasNativeFields: { ...(saved.nativeFields || {}) },
            extrasBlocks: { ...(saved.blocks || {}) },
        };
    },

    computed: {
        // Through the stable getter — load()/save() replace the underlying
        // object, so a data() capture would go stale.
        link() { return store.link; },

        // Expand/collapse stays live in read-only mode — inspecting the
        // saved mapping is the point; only the editors inside disable.
        readOnly() { return !!store.ui.meta?.readOnly; },

        // The mapping row in the reactive store. Reading via a computed
        // lets the row react when other code (e.g. extras emits) writes
        // into the same handle's sub-tree.
        mapping() {
            return this.link.mappings?.[this.field.handle] || {};
        },

        // The node schema the PHP strategy declared for this field type.
        extrasSchema() {
            return this.field.fieldMeta?.schema || [];
        },

        // An extras block exists exactly when the strategy declared a
        // schema — no separate flag to keep in sync.
        hasExtras() {
            return this.extrasSchema.length > 0;
        },

        // The strategy declared its value derives entirely from sub-mappings
        // (Matrix): the row shows no source-node select and no default editor.
        subfieldsOnly() {
            return !!this.field.fieldMeta?.subfieldsOnly;
        },

        // Toggle copy ships translated through fieldMeta.labels (the shared
        // commonExtrasLabels set); fall back to the raw strings for metas
        // that don't carry labels.
        toggleLabels() {
            const labels = this.field.fieldMeta?.labels || {};
            return {
                configure: labels.configure || 'Configure',
                hideOptions: labels.hideOptions || 'Hide options',
            };
        },

        // Rows the user hasn't mapped yet keep their extras collapsed —
        // auto-expanding empty option panels just adds noise.
        hasMappingData() {
            return Object.keys(this.mapping).length > 0;
        },

        /**
         * Source-node candidates for the extras' sub-field dropdowns: the
         * latest Fetch-sample nodes straight off the store, merged with
         * saved sub-field paths — the flat `extrasNativeFields` rows plus
         * every block type's nested `extrasBlocks.*.fields` rows — so the
         * dropdowns render before a sample exists. Distinct from the
         * `nodeOptions` prop, which feeds the row's own source-node select.
         */
        extrasNodeOptions() {
            const blockRows = Object.values(this.extrasBlocks)
                .flatMap((entry) => Object.values(entry?.fields || {}));
            const saved = [...Object.values(this.extrasNativeFields), ...blockRows]
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

        // The saved source node is no longer in the fetched sample. Compares
        // directly against the discovered flatNodes — the merged nodeOptions
        // prop deliberately re-adds saved-but-missing values for dropdown
        // legibility, so we can't use it as the "is the node still live"
        // signal.
        isMissing() {
            const saved = this.mapping.node;
            if (!saved) return false;
            const discovered = store.ui.sample?.flatNodes;
            if (!discovered) return false;
            return !discovered.some(o => o.value === saved);
        },

        // Grouped for SearchableSelect: the sentinels render as plain rows
        // up top, the sample-discovered nodes inside a grey "Nodes" group.
        // `__default__` is a UI-only sentinel: it round-trips to the
        // mapping's `useDefault` flag, never to the wire `node`.
        sourceNodeOptions() {
            const groups = [
                {
                    label: null,
                    kind: null,
                    options: [
                        { value: '', label: this.$t('— no mapping —') },
                        { value: '__default__', label: this.$t('— use default —') },
                    ],
                },
            ];
            if (this.nodeOptions.length) {
                groups.push({ label: this.$t('Nodes'), kind: 'node', options: this.nodeOptions });
            }
            return groups;
        },

        defaultSelectOptions() {
            const opts = this.field.options || {};
            return [
                { value: '', label: '—' },
                ...Object.keys(opts).map(value => ({ value, label: opts[value] })),
            ];
        },
    },

    methods: {
        toggleExtras() {
            if (!this.hasExtras) return;
            this.extrasExpanded = !this.extrasExpanded;
        },

        onNodeSelect(value) {
            const handle = this.field.handle;
            let mappings = this.link.mappings;
            if (value === '__default__') {
                mappings = setMappingSlot(mappings, handle, 'node', '');
                mappings = setMappingSlot(mappings, handle, 'useDefault', true);
            } else {
                mappings = setMappingSlot(mappings, handle, 'useDefault', false);
                mappings = setMappingSlot(mappings, handle, 'node', value);
            }
            this.link.mappings = mappings;
        },

        onDefaultSelect(value) {
            this.writeMapping('default', value);
        },

        onDefaultChange(e) {
            const value = e.target.value;
            this.writeMapping('default', value);
        },

        onDefaultElementChange(elementId) {
            // ElementPicker emits the raw id (or null on clear). Project
            // Config stores it as a string to match the legacy Twig form.
            this.writeMapping('default', elementId == null ? null : String(elementId));
        },

        // SchemaForm emits land here directly: keep the local model in
        // sync, then write the store — pruned for `options`, so Project
        // Config YAML doesn't fill up with noise keys.
        onOptionsUpdate(options) {
            this.extrasOptions = options;
            this.writeMapping('options', pruneEmpty(options));
        },

        onNativeFieldsUpdate(nativeFields) {
            this.extrasNativeFields = nativeFields;
            this.writeMapping('nativeFields', nativeFields);
        },

        onBlocksUpdate(blocks) {
            this.extrasBlocks = blocks;
            this.writeMapping('blocks', blocks);
        },

        /**
         * Write one slot of the mapping row, dropping empty values so the
         * saved Project Config doesn't fill up with noise. The pruning
         * rules live in lib/mappings.js where they're unit-tested.
         */
        writeMapping(key, value) {
            this.link.mappings = setMappingSlot(this.link.mappings, this.field.handle, key, value);
        },
    },
};
</script>

<style scoped>
/* Extras span the row's full width (`grid-column: 1 / -1`, via the
   `.influx-mapping-extras-slot` rule in links.css). The grid that aligns
   extras rows with the row's Field / Source-node / Default-value columns
   lives in SchemaForm.vue. The block starts flush under the row controls —
   collapsed it renders nothing at all. The expanded tint comes from the
   row's `:has()` selector in links.css, keyed off `data-expanded`. */

.influx-mapping-extras {
    margin-top: 0;
    background: transparent;
    border-top: 0;
}

.extras-body { padding: 0 0 4px; }
</style>
