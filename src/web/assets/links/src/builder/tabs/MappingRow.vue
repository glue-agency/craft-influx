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
            <div class="select fullwidth">
                <select :value="mapping.node ?? ''" @change="onNodeChange">
                    <option value="">{{ $t('— no mapping —') }}</option>
                    <option v-for="opt in nodeOptions"
                            :key="opt.value"
                            :value="opt.value">{{ opt.label }}</option>
                </select>
            </div>
        </div>

        <div>
            <!-- Default-value editor. Three shapes:
                 - `select` → native <select>
                 - `element` → not yet supported in the SPA (followup)
                 - anything else → plain text -->
            <template v-if="field.defaultType === 'select'">
                <div class="select fullwidth">
                    <select :value="mapping.default ?? ''" @change="onDefaultChange">
                        <option value="">—</option>
                        <option v-for="(label, value) in (field.options || {})"
                                :key="value"
                                :value="value">{{ label }}</option>
                    </select>
                </div>
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
import { store } from '../store.js';

/**
 * One row in the Mapping tab. Renders the field name, source-node select,
 * default-value editor, and optionally the MappingExtras subform for
 * complex field types. Writes straight back into `link.mappings[handle]`
 * on the reactive store; the parent watches the store via the dirty flag.
 */
export default {
    name: 'MappingRow',

    components: { MappingExtras, ElementPicker },

    props: {
        field: { type: Object, required: true },
        // Available source-node candidates from the latest Fetch sample,
        // shape `[{value, label}]`. Empty when no sample has been run yet.
        nodeOptions: { type: Array, default: () => [] },
    },

    data() {
        return {
            link: store.raw.link,
        };
    },

    computed: {
        // The mapping row in the reactive store. Reading via a computed
        // lets the row react when other code (e.g. extras emits) writes
        // into the same handle's sub-tree.
        mapping() {
            return this.link.mappings?.[this.field.handle] || {};
        },

        hasExtras() {
            return !!this.field.fieldMeta?.hasExtras;
        },

        // Saved shape MappingExtras consumes — its `created()` hook reads
        // saved.options for the initial UI state and we never re-pass a
        // different identity after mount, so emits-only flow stays clean.
        extrasSaved() {
            return { options: this.mapping.options || {} };
        },

        // Flag the row when the saved source node isn't in the latest
        // sample — same rule the Twig form's missing-badge uses.
        isMissing() {
            const saved = this.mapping.node;
            if (!saved) return false;
            if (!store.state.sample) return false;
            return !this.nodeOptions.some(o => o.value === saved);
        },
    },

    methods: {
        onNodeChange(e) {
            const value = e.target.value;
            this.writeMapping('node', value);
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
         * saved Project Config doesn't fill up with noise.
         */
        writeMapping(key, value) {
            const handle = this.field.handle;
            const current = { ...(this.link.mappings?.[handle] || {}) };

            const isEmpty = value === '' || value === null || value === undefined
                || (typeof value === 'object' && value !== null && Object.keys(value).length === 0);

            if (isEmpty) {
                delete current[key];
            } else {
                current[key] = value;
            }

            const mappings = { ...this.link.mappings };
            if (Object.keys(current).length === 0) {
                delete mappings[handle];
            } else {
                mappings[handle] = current;
            }
            this.link.mappings = mappings;
        },
    },
};
</script>
