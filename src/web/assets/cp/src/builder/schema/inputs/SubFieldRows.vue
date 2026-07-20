<template>
    <!-- Same group chrome as the main field list (MappingGroup): the shared
         MappingGroupCard, with the subfields variant so SchemaForm's subgrid
         rules keep matching. Sub-field mappings ARE mappings, so they get the
         same furniture (chevron, mapped/missing pills, column headings). -->
    <mapping-group-card variant="subfields" :label="node.label" :default-expanded="hasSavedRows">
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

        <!-- A group without sub-fields still gets its card when the consumer
             supplies an empty-state hint (MatrixFields: a block type with no
             custom fields) — the hint says why there are no rows to map. -->
        <p v-if="!subFieldList.length && emptyHint" class="light sub-fields-hint">
            {{ emptyHint }}
        </p>

        <!-- Same column headings as the main mapping list — sub-field rows are
             mappings too. Joined to the card's shared grid in SchemaForm.vue,
             which subgrids down from the parent mapping rows' tracks so the
             columns align with the row above. -->
        <div v-else class="influx-mapping-headings">
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
 * The shared sub-field mapping table: source-node + default rows for one
 * group of sub-fields, inside the MappingGroupCard chrome (chevron,
 * mapped/missing/count pills, column headings, per-row missing badges).
 * Its two consumers are the schema-node wrappers ElementSubFields (a
 * related element's native sub-fields → the mapping's flat `nativeFields`
 * map) and MatrixFields (one Matrix block type's custom fields → that
 * type's `fields` slice of the `blocks` object) — each maps its own wire
 * shape onto this component's rows contract and stays out of the rendering.
 *
 * Rows contract: `rows` is the saved map `{handle: {node?, default?,
 * useDefault?, ...}}` for the sub-fields in `node.subFields`. Every edit
 * emits `update:rows` with the fully-rewritten map; the consumer merges it
 * back into its own channel. Row rewrites are PRESERVING: only node /
 * default / useDefault are rewritten, a row's unknown keys (a Matrix
 * child's `options`, nested `fields`, …) round-trip untouched, and a row
 * is dropped only when nothing at all is left on it. (ElementSubFields
 * rows never carry unknown keys today, but the one shared writer keeps
 * both channels on the same rules.)
 *
 * `__default__` is the same UI-only sentinel MappingRow uses: it
 * round-trips to a row's `useDefault` flag, never the wire node. Each row
 * carries its own missing-mapping state (saved node no longer in the
 * fetched sample) — independent of the parent mapping row's.
 */
export default {
    name: 'SubFieldRows',

    components: { SearchableSelect, SelectInput, MappingGroupCard },

    props: {
        // The schema node: label heads the card, instructions render as the
        // hint, subFields (BuilderSchema primitives) become the rows.
        node: { type: Object, required: true },
        // The saved rows map — see the contract above.
        rows: { type: Object, default: () => ({}) },
        nodeOptions: { type: Array, default: () => [] },
        // The sample's discovered flatNodes — the "is the node still live"
        // signal. Null when no sample has been fetched (nothing is missing
        // then). Distinct from nodeOptions, which re-adds saved-but-missing
        // values for dropdown legibility.
        discoveredNodes: { type: Array, default: null },
        readOnly: { type: Boolean, default: false },
        // Rendered instead of the column headings when node.subFields is
        // empty; without it the (row-less) headings still render.
        emptyHint: { type: String, default: null },
    },

    emits: ['update:rows'],

    computed: {
        /** @returns the sub-field nodes (BuilderSchema primitives). */
        subFieldList() {
            return this.node.subFields || [];
        },

        // Cards with saved rows start open; untouched ones start collapsed
        // (so e.g. a many-type Matrix doesn't wall the mapping tab). Seeds
        // the card's initial state only — toggling stays free.
        hasSavedRows() {
            return Object.keys(this.rows).length > 0;
        },

        /** Sub-fields with an active source node — the header's pill. */
        mappedCount() {
            return this.subFieldList.reduce((count, sub) => {
                return count + (this.rows[sub.handle]?.node ? 1 : 0);
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
        // `__default__` round-trips to the row's `useDefault` flag — see
        // the sentinel note in the component docblock.
        rowFor(handle) {
            const saved = this.rows[handle] || {};
            return {
                node: saved.useDefault ? '__default__' : (saved.node || ''),
                default: saved.default || '',
            };
        },

        isMissing(handle) {
            const saved = this.rows[handle]?.node;
            if (!saved) return false;
            if (!this.discoveredNodes) return false;
            return !this.discoveredNodes.some(o => o.value === saved);
        },

        updateRow(handle, key, value) {
            const row = { ...this.rowFor(handle), [key]: value };

            // Start from the saved row so unknown keys survive the rewrite
            // untouched — the preserving contract in the docblock.
            const saved = { ...(this.rows[handle] || {}) };
            delete saved.node;
            delete saved.default;
            delete saved.useDefault;

            const useDefault = row.node === '__default__';
            const node = useDefault ? '' : row.node;

            if (node) saved.node = node;
            if (row.default) saved.default = row.default;
            if (useDefault) saved.useDefault = true;

            const next = { ...this.rows };
            if (Object.keys(saved).length === 0) {
                delete next[handle];
            } else {
                next[handle] = saved;
            }

            this.$emit('update:rows', next);
        },
    },
};
</script>
