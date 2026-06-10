<template>
    <div class="influx-mapping-row"
         :class="{ 'has-extras': hasExtras }"
         :data-field-handle="field.handle"
         :data-missing="isMissing ? 'true' : 'false'"
    >
        <div class="meta">
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
                :model-value="mapping.node ?? ''"
                :options="sourceNodeOptions"
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
                    :placeholder="'—'"
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
                       placeholder="—"
                       @input="onDefaultChange" />
            </template>
        </div>

        <mapping-extras
            v-if="hasExtras"
            class="influx-mapping-extras-slot"
            :field="field"
            :saved="extrasSaved"
            :read-only="false"
            @update:options="onOptionsUpdate"
            @update:nativeFields="onNativeFieldsUpdate"
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

        hasExtras() {
            return !!this.field.fieldMeta?.hasExtras;
        },

        // Saved shape MappingExtras consumes for its initial UI state —
        // we never re-pass a different identity after mount, so the
        // emits-only flow stays clean. `nativeFields` rides along so
        // saved asset sub-field mappings re-hydrate on edit.
        extrasSaved() {
            return {
                options: this.mapping.options || {},
                nativeFields: this.mapping.nativeFields || {},
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

        // SearchableSelect wants a flat [{value,label}] list with the
        // empty/clear sentinel up top.
        sourceNodeOptions() {
            return [
                { value: '', label: this.$t('— no mapping —') },
                ...this.nodeOptions,
            ];
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
        onNodeSelect(value) {
            this.writeMapping('node', value);
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
