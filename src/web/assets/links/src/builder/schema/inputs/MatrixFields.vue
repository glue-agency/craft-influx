<template>
    <!-- Same group chrome as ElementSubFields: the shared MappingGroupCard's
         subfields variant, so SchemaForm's subgrid rules keep matching. One
         card exists per block type and ALL of them render at once (Feed
         Me-style) — each card reads and writes only its own type's slice. -->
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

        <!-- A block type without custom fields still gets its card so the
             full type list stays visible — the empty state says why there
             are no rows to map. -->
        <p v-if="!subFieldList.length" class="light sub-fields-hint">
            {{ $t('This block type has no mappable sub-fields.') }}
        </p>

        <!-- Same column headings as the main mapping list — block sub-field
             rows are mappings too. Joined to the card's shared grid in
             SchemaForm.vue, which subgrids down from the parent mapping rows'
             tracks so the columns align with the row above. -->
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
 * Schema matrixFields node: source-node + default rows for ONE Matrix block
 * type's custom fields, Feed Me-style — every block type's card renders at
 * once, each independently mappable.
 *
 * Contract: `modelValue` is the mapping's WHOLE `blocks` object
 * (`{<typeHandle>: {fields: {...}, ...}}`). The card renders only its own
 * `node.blockType` slice's `fields` map and emits full `blocks` replacements
 * that leave every other type's slice — and any unknown keys on its own
 * type's entry (`nativeFields`, …) — untouched. Taking the whole object
 * keeps the merge and its pruning next to the rewrite instead of splitting
 * them across SchemaForm.
 *
 * Generalizes ElementSubFields — same card chrome, per-row node select,
 * `__default__` sentinel and missing-node detection — but writes the
 * mapping's recursive `blocks.<blockType>.fields` channel:
 * `{handle: {node?, default?, useDefault?, ...}}`.
 *
 * Matrix-specific rules:
 *   - node paths are ABSOLUTE item paths (`seasons.year`), resolved against
 *     the top-level item — never relative to the block;
 *   - a child row's unknown keys (`options`, nested `fields`, …) round-trip
 *     untouched: only node/default/useDefault are rewritten, and a row is
 *     dropped only when nothing at all is left on it;
 *   - emptied slices collapse away: a `fields` map with no rows drops off
 *     its type entry, and an entry left with nothing drops the type out of
 *     `blocks` (an all-empty `blocks` then prunes off the mapping in
 *     MappingRow.writeMapping()).
 *
 * Each row carries its own missing-mapping state (saved node no longer in
 * the fetched sample) — independent of the parent mapping row's.
 */
export default {
    name: 'MatrixFields',

    components: { SearchableSelect, SelectInput, MappingGroupCard },

    props: {
        node: { type: Object, required: true },
        // The mapping's whole `blocks` object — see the contract above.
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

        /** This card's own slice: its block type's child `fields` map. */
        typeFields() {
            return this.modelValue[this.node.blockType]?.fields || {};
        },

        // Cards with saved rows start open; untouched block types start
        // collapsed so a many-type Matrix doesn't wall the mapping tab.
        // Seeds the card's initial state only — toggling stays free.
        hasSavedRows() {
            return Object.keys(this.typeFields).length > 0;
        },

        /** Sub-fields with an active source node — the header's pill. */
        mappedCount() {
            return this.subFieldList.reduce((count, sub) => {
                return count + (this.typeFields[sub.handle]?.node ? 1 : 0);
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
            const saved = this.typeFields[handle] || {};
            return {
                node: saved.useDefault ? '__default__' : (saved.node || ''),
                default: saved.default || '',
            };
        },

        isMissing(handle) {
            const saved = this.typeFields[handle]?.node;
            if (!saved) return false;
            if (!this.discoveredNodes) return false;
            return !this.discoveredNodes.some(o => o.value === saved);
        },

        updateRow(handle, key, value) {
            const row = { ...this.rowFor(handle), [key]: value };

            // Start from the saved row so unknown keys (a child's `options`,
            // nested `fields`, …) survive the rewrite untouched.
            const saved = { ...(this.typeFields[handle] || {}) };
            delete saved.node;
            delete saved.default;
            delete saved.useDefault;

            const useDefault = row.node === '__default__';
            const node = useDefault ? '' : row.node;

            if (node) saved.node = node;
            if (row.default) saved.default = row.default;
            if (useDefault) saved.useDefault = true;

            const nextFields = { ...this.typeFields };
            if (Object.keys(saved).length === 0) {
                delete nextFields[handle];
            } else {
                nextFields[handle] = saved;
            }

            this.$emit('update:modelValue', this.mergeTypeFields(nextFields));
        },

        /**
         * Merge this card's rewritten `fields` map back into the whole
         * `blocks` object: other types' slices pass through untouched,
         * unknown keys on this type's entry (`nativeFields`, …) survive,
         * an emptied `fields` map collapses off the entry, and an entry
         * left with nothing collapses the type out of `blocks`.
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
            return next;
        },
    },
};
</script>
