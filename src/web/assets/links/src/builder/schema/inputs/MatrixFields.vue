<template>
    <!-- Same group chrome as ElementSubFields: the shared MappingGroupCard's
         subfields variant, so SchemaForm's subgrid rules keep matching. One
         card exists per block type; SchemaForm's showIf gating only renders
         the card matching the mapping's selected blockType option. -->
    <mapping-group-card variant="subfields" :label="node.label">
        <template #tags>
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
        </template>

        <p v-if="node.instructions" class="light sub-fields-hint" v-html="node.instructions" />

        <!-- Same column headings as the main mapping list — block sub-field
             rows are mappings too. Joined to the card's shared grid in
             SchemaForm.vue so the labels track the content-sized columns. -->
        <div class="influx-mapping-headings">
            <div>{{ $t('Field') }}</div>
            <div>{{ $t('Source node') }}</div>
            <div>{{ $t('Default value') }}</div>
        </div>

        <div
            class="sub-field-row"
            v-for="sub in subFieldList"
            :key="sub.handle"
            :data-missing="isMissing(sub.handle) ? 'true' : 'false'"
        >
            <!-- No separate handle line here: the PHP-declared label already
                 carries it — "Field name (handle)". -->
            <label>
                {{ sub.label }}
                <span v-if="isMissing(sub.handle)"
                      class="influx-missing-badge"
                      :title="$t('Saved source node is no longer in the fetched sample. Pick a new one or clear the mapping.')">
                    {{ $t('missing mapping') }}
                </span>
            </label>
            <searchable-select
                :model-value="rowFor(sub.handle).node"
                :options="sourceNodeOptions"
                searchable
                :placeholder="$t('— no mapping —')"
                :search-placeholder="$t('Search nodes…')"
                :empty-label="$t('Run “Fetch sample” to discover nodes.')"
                :disabled="readOnly"
                @update:model-value="updateRow(sub.handle, 'node', $event)"
            />
            <!-- The default-value editor renders by the sub-field node's own
                 type — the same primitives the rest of the schema uses. -->
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
                :placeholder="sub.placeholder || null"
                :disabled="readOnly"
                @input="updateRow(sub.handle, 'default', $event.target.value)"
            >
        </div>
    </mapping-group-card>
</template>

<script>
import SearchableSelect from '../../SearchableSelect.vue';
import SelectInput from './SelectInput.vue';
import MappingGroupCard from '../../../components/MappingGroupCard.vue';

/**
 * Schema matrixFields node: source-node + default rows for one Matrix block
 * type's custom fields. Generalizes ElementSubFields — same card chrome,
 * per-row node select, `__default__` sentinel and missing-node detection —
 * but writes the mapping's recursive `fields` channel:
 * `{handle: {node?, default?, useDefault?, ...}}`.
 *
 * Two Matrix-specific rules:
 *   - node paths are ABSOLUTE item paths (`seasons.year`), resolved against
 *     the top-level item — never relative to the block;
 *   - a child row's unknown keys (`options`, nested `fields`, …) round-trip
 *     untouched: only node/default/useDefault are rewritten, and a row is
 *     dropped only when nothing at all is left on it.
 *
 * Each row carries its own missing-mapping state (saved node no longer in
 * the fetched sample) — independent of the parent mapping row's.
 */
export default {
    name: 'MatrixFields',

    components: { SearchableSelect, SelectInput, MappingGroupCard },

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

            // Start from the saved row so unknown keys (a child's `options`,
            // nested `fields`, …) survive the rewrite untouched.
            const saved = { ...(this.modelValue[handle] || {}) };
            delete saved.node;
            delete saved.default;
            delete saved.useDefault;

            const useDefault = row.node === '__default__';
            const node = useDefault ? '' : row.node;

            if (node) saved.node = node;
            if (row.default) saved.default = row.default;
            if (useDefault) saved.useDefault = true;

            const next = { ...this.modelValue };
            if (Object.keys(saved).length === 0) {
                delete next[handle];
            } else {
                next[handle] = saved;
            }

            this.$emit('update:modelValue', next);
        },
    },
};
</script>
