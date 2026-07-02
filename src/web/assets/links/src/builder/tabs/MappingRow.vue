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

        <div>
            <searchable-select
                :model-value="mapping.useDefault ? '__default__' : (mapping.node ?? '')"
                :options="sourceNodeOptions"
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
            <template v-if="field.defaultType === 'select'">
                <searchable-select
                    :model-value="mapping.default ?? ''"
                    :options="defaultSelectOptions"
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
                       @input="onDefaultChange" />
            </template>
        </div>

        <mapping-extras
            v-if="hasExtras"
            class="influx-mapping-extras-slot"
            :field="field"
            :saved="extrasSaved"
            :expanded="extrasExpanded"
            :read-only="false"
            @update:options="onOptionsUpdate"
            @update:nativeFields="onNativeFieldsUpdate"
            @update:blocks="onBlocksUpdate"
        />
    </div>
</template>

<script>
import MappingExtras from '../../components/MappingExtras.vue';
import ElementPicker from '../ElementPicker.vue';
import SearchableSelect from '../SearchableSelect.vue';
import { store } from '../store.js';
import { setMappingSlot } from '../lib/mappings.js';

/**
 * One row in the Mapping tab. Renders the field name, source-node select,
 * default-value editor, and optionally the MappingExtras subform for
 * complex field types. Writes straight back into `link.mappings[handle]`
 * on the reactive store; the parent watches the store via the dirty flag.
 */
export default {
    name: 'MappingRow',

    components: { MappingExtras, ElementPicker, SearchableSelect },

    props: {
        field: { type: Object, required: true },
        // Available source-node candidates from the latest Fetch sample,
        // shape `[{value, label}]`. Empty when no sample has been run yet.
        nodeOptions: { type: Array, default: () => [] },
    },

    data() {
        return {
            // Rows with a saved mapping start with their extras open —
            // same seed the extras component used when it owned the toggle.
            extrasExpanded: Object.keys(store.link.mappings?.[this.field.handle] || {}).length > 0,
        };
    },

    computed: {
        // Through the stable getter — load()/save() replace the underlying
        // object, so a data() capture would go stale.
        link() { return store.link; },

        // The mapping row in the reactive store. Reading via a computed
        // lets the row react when other code (e.g. extras emits) writes
        // into the same handle's sub-tree.
        mapping() {
            return this.link.mappings?.[this.field.handle] || {};
        },

        // An extras block exists exactly when the strategy declared a
        // schema — no separate flag to keep in sync.
        hasExtras() {
            return (this.field.fieldMeta?.schema || []).length > 0;
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

        // Saved shape MappingExtras consumes for its initial UI state —
        // we never re-pass a different identity after mount, so the
        // emits-only flow stays clean. `nativeFields` / `blocks` ride along
        // so saved sub-field mappings (asset alt/title, Matrix per-block-type
        // children) re-hydrate on edit.
        extrasSaved() {
            return {
                options: this.mapping.options || {},
                nativeFields: this.mapping.nativeFields || {},
                blocks: this.mapping.blocks || {},
            };
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

        onOptionsUpdate(options) {
            this.writeMapping('options', options);
        },

        onNativeFieldsUpdate(nativeFields) {
            this.writeMapping('nativeFields', nativeFields);
        },

        onBlocksUpdate(blocks) {
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
