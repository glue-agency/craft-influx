<template>
    <!-- Same group chrome as the main field list (MappingGroup): white
         card, clickable header with chevron + mapped/missing pills. Sub-
         field mappings ARE mappings, so they get the same furniture. -->
    <div class="influx-mapping-group influx-subfields-group" :class="{ collapsed: !expanded }">
        <div class="influx-mapping-group-header"
             role="button"
             tabindex="0"
             :aria-expanded="expanded ? 'true' : 'false'"
             @click="expanded = !expanded"
             @keydown.enter.prevent="expanded = !expanded"
             @keydown.space.prevent="expanded = !expanded">
            <span class="chevron" aria-hidden="true">▼</span>
            <span class="label">{{ node.label }}</span>

            <span class="pill pill-mapped"
                  :data-mapped="mappedCount"
                  :title="$t('Sub-fields with an active source node')">
                <span class="num">{{ mappedCount }}</span>&nbsp;{{ $t('mapped') }}
            </span>

            <span v-if="missingCount > 0"
                  class="pill pill-missing"
                  :data-missing="missingCount"
                  :title="$t('Sub-fields whose saved source node is no longer in the fetched sample')">
                <span class="num">{{ missingCount }}</span>&nbsp;{{ $t('missing') }}
            </span>

            <span class="pill pill-count" :title="$t('Total sub-fields in this group')">{{ subFieldList.length }}</span>
        </div>

        <div class="influx-mapping-group-body">
            <p v-if="node.instructions" class="light sub-fields-hint" v-html="node.instructions" />

            <div
                class="sub-field-row"
                v-for="sub in subFieldList"
                :key="sub.handle"
                :data-missing="isMissing(sub.handle) ? 'true' : 'false'"
            >
                <label>
                    {{ sub.label }}
                    <span v-if="isMissing(sub.handle)"
                          class="influx-missing-badge"
                          :title="$t('Saved source node is no longer in the fetched sample. Pick a new one or clear the mapping.')">
                        {{ $t('missing mapping') }}
                    </span>
                    <code class="handle light">{{ sub.handle }}</code>
                </label>
                <searchable-select
                    :model-value="rowFor(sub.handle).node"
                    :options="sourceNodeOptions"
                    :placeholder="$t('— no mapping —')"
                    :search-placeholder="$t('Search nodes…')"
                    :empty-label="$t('Run “Fetch sample” to discover nodes.')"
                    :disabled="readOnly"
                    @update:model-value="updateRow(sub.handle, 'node', $event)"
                />
                <!-- The default-value editor renders by the sub-field node's
                     own type — the same primitives the rest of the schema
                     uses. -->
                <select-input
                    v-if="sub.type === 'select'"
                    :node="sub"
                    :model-value="rowFor(sub.handle).default"
                    searchable
                    :read-only="readOnly"
                    @update:model-value="updateRow(sub.handle, 'default', $event)"
                />
                <input
                    v-else
                    type="text"
                    :class="['text', sub.type === 'code' ? 'code' : null]"
                    :value="rowFor(sub.handle).default"
                    :placeholder="sub.placeholder || '—'"
                    :disabled="readOnly"
                    @input="updateRow(sub.handle, 'default', $event.target.value)"
                >
            </div>
        </div>
    </div>
</template>

<script>
import SearchableSelect from '../../SearchableSelect.vue';
import SelectInput from './SelectInput.vue';

/**
 * Schema elementSubFields node: source-node + default rows for a related
 * element's native sub-fields (asset alt/title). Each sub-field IS a
 * primitive schema node — its handle/label name the row and its type
 * renders the default-value editor — while the table contributes the
 * source-node select and writes the mapping's recursive `nativeFields`
 * channel: `{handle: {node?, default?, useDefault?}}`, fully-empty rows
 * dropped.
 *
 * Each row carries its own missing-mapping state (saved node no longer in
 * the fetched sample) — independent of the parent mapping row's.
 */
export default {
    name: 'ElementSubFields',

    components: { SearchableSelect, SelectInput },

    props: {
        node: { type: Object, required: true },
        modelValue: { type: Object, default: () => ({}) },
        nodeOptions: { type: Array, default: () => [] },
        // The sample's discovered flatNodes — the "is the node still live"
        // signal. Null when no sample has been fetched (nothing is missing
        // then). Distinct from nodeOptions, which re-adds saved-but-missing
        // values for dropdown legibility.
        discoveredNodes: { type: Array, default: null },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            expanded: true,
        };
    },

    computed: {
        /** @returns the sub-field nodes (BuilderSchema primitives). */
        subFieldList() {
            return this.node.subFields || [];
        },

        /** Sub-fields with an active source node — the header's pill. */
        mappedCount() {
            return this.subFieldList.reduce((count, sub) => {
                return count + (this.modelValue[sub.handle]?.node ? 1 : 0);
            }, 0);
        },

        /** Saved sub-field nodes no longer present in the latest sample. */
        missingCount() {
            return this.subFieldList.reduce((count, sub) => {
                return count + (this.isMissing(sub.handle) ? 1 : 0);
            }, 0);
        },

        // Same grouped shape as MappingRow's source-node select: sentinels
        // as plain rows up top, sample nodes inside a grey "Nodes" group.
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
    },

    methods: {
        // `__default__` is the same UI-only sentinel MappingRow uses: it
        // round-trips to the row's `useDefault` flag, never the wire node.
        rowFor(handle) {
            const saved = this.modelValue[handle] || {};
            return {
                node: saved.useDefault ? '__default__' : (saved.node || ''),
                default: saved.default || '',
            };
        },

        isMissing(handle) {
            const saved = this.modelValue[handle]?.node;
            if (!saved) return false;
            if (!this.discoveredNodes) return false;
            return !this.discoveredNodes.some(o => o.value === saved);
        },

        updateRow(handle, key, value) {
            const row = { ...this.rowFor(handle), [key]: value };
            const next = { ...this.modelValue };

            const useDefault = row.node === '__default__';
            const node = useDefault ? '' : row.node;

            if (node === '' && row.default === '' && !useDefault) {
                delete next[handle];
            } else {
                next[handle] = {};
                if (node) next[handle].node = node;
                if (row.default) next[handle].default = row.default;
                if (useDefault) next[handle].useDefault = true;
            }

            this.$emit('update:modelValue', next);
        },
    },
};
</script>
