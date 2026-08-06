<template>
    <!-- Same group chrome as the main field list (MappingGroup): the shared
         MappingGroupCard, with the subfields variant so the extras' subgrid
         rules keep matching. Sub-field mappings ARE mappings, so they get the
         same furniture (chevron, mapped/missing pills, column headings). -->
    <v-mapping-group-card variant="subfields" :label="node.label" :default-expanded="hasSavedRows">
        <template v-slot:tags>
            <!-- Both .stop modifiers are load-bearing: the card header is its
                 own toggle, on click AND on keydown.enter/.space — and those
                 key handlers carry .prevent, which would swallow the button's
                 native Enter → click before it ever fires. -->
            <button v-if="! readOnly && hasSavedRows"
                    type="button"
                    class="influx-clear-link clear-rows"
                    :title="$t('Clear every source node and default in this group')"
                    v-text="$t('clear nodes')"
                    @click.stop="clearRows"
                    @keydown.stop></button>

            <span class="pill pill-mapped"
                  :data-mapped="mappedCount"
                  :title="$t('Sub-fields with an active source node')">
                <span class="num" v-text="mappedCount"></span>&nbsp;{{ $t('mapped') }}
            </span>

            <span v-if="missingCount > 0"
                  class="pill pill-missing"
                  :data-missing="missingCount"
                  :title="$t('Sub-fields whose source node isn’t in the fetched sample')">
                <span class="num" v-text="missingCount"></span>&nbsp;{{ $t('missing') }}
            </span>

            <span class="pill pill-count" :title="$t('Total sub-fields in this group')" v-text="subFieldList.length"></span>
        </template>

        <template v-slot:default="{ expanded }">
            <p v-if="node.instructions" class="light sub-fields-hint" v-html="node.instructions" />

            <!-- A group without sub-fields still gets its card when the consumer
                 supplies an empty-state hint (MatrixFields: a block type with no
                 custom fields) — the hint says why there are no rows to map. -->
            <p v-if="! subFieldList.length && emptyHint" class="light sub-fields-hint" v-text="emptyHint"></p>

            <!-- Same column headings as the main mapping list — sub-field rows are
                 mappings too. Joined to the card's shared grid in
                 styles/components/schema-form.css, which subgrids down from the
                 parent mapping rows' tracks so the columns align with the row
                 above. -->
            <div v-else class="influx-mapping-headings">
                <div v-text="$t('Field')"></div>
                <div v-text="$t('Source node')"></div>
                <div v-text="$t('Default value')"></div>
            </div>

            <div
                class="sub-field-row"
                v-for="sub in subFieldList"
                :key="sub.handle"
                :data-missing="isMissing(sub.handle) ? 'true' : 'false'"
            >
                <!-- The whole label cell toggles this row's extras, the way the
                     parent row's meta cell toggles its own.

                     `.prevent` is load-bearing: a <label> forwards its own click to
                     the first labelable element inside it, which is the chevron
                     button — so a click on the label text toggled twice (once here,
                     once on the forwarded button click bubbling back up) and looked
                     like nothing happened at all. Only a click that started on the
                     chevron itself ever worked. -->
                <label :class="{ 'is-toggleable': hasExtras(sub) }" @click.prevent="toggleExtras(sub)">
                    <button
                        v-if="hasExtras(sub)"
                        type="button"
                        class="extras-chevron"
                        :class="{ collapsed: ! isExpanded(sub) }"
                        :aria-expanded="isExpanded(sub) ? 'true' : 'false'"
                        :aria-label="isExpanded(sub) ? $t('Hide options') : $t('Configure')"
                        :title="isExpanded(sub) ? $t('Hide options') : $t('Configure')"
                    >
                        <span aria-hidden="true">▼</span>
                    </button>
                    {{ sub.label }}
                    <span v-if="isMissing(sub.handle)"
                          class="influx-missing-badge"
                          :title="$t('Source node isn’t in the fetched sample. Pick a new node or clear the mapping if no longer in use.')"
                          v-text="$t('missing mapping')"></span>
                    <code class="handle light" v-text="sub.handle"></code>
                </label>
                <!-- Each cell renders only where the sub-field's own field declares
                     that region, which is how a nested row ends up with exactly the
                     cells its top-level row has. A nested Table or Link declares
                     neither (its value is its sub-mappings); a nested Table Maker
                     declares a source and no default (one node holds the whole
                     structure). The tracks stay in place either way, so every row's
                     label still shares one edge. -->
                <v-searchable-select
                    v-if="sub.cells?.source !== false"
                    :model-value="rowFor(sub.handle).node"
                    :options="sourceNodeOptions"
                    searchable
                    allow-custom
                    :search-placeholder="$t('Search nodes…')"
                    :empty-label="$t('Run “Fetch sample” to discover nodes.')"
                    :disabled="readOnly"
                    @update:model-value="updateRow(sub.handle, 'node', $event)"
                />
                <!-- The default-value editor renders by the sub-field node's own
                     type, through the same registry a top-level cell uses — the
                     same primitives and the same controls. A relation sub-field
                     gets the picker its own field would give an editor; a text box
                     would ask the operator to retype a reference.

                     A server-rendered control is mounted only once the card is
                     open: it fetches its markup from the server, and a mapping tab
                     renders every card at once, so an eagerly-mounted one would
                     fire a request per relation row on tab load. A card with saved
                     rows starts expanded, so a configured picker still appears at
                     once.

                     Bound exactly as a top-level cell is — no stand-in copy for the
                     empty state. A select's "— no default —" is a sentinel in its
                     own list now, so an unset row reads as an empty cell here too
                     rather than as a labelled "—" a card row alone would show. -->
                <component
                    v-if="sub.cells?.default !== false && (expanded || ! serverRendered(sub))"
                    :is="controlFor(sub)"
                    :node="sub"
                    :model-value="rowFor(sub.handle).default"
                    :field-handle="sub.handle"
                    :read-only="readOnly"
                    @update:model-value="updateRow(sub.handle, 'default', $event)"
                />

                <!-- This row's OWN extras — whatever its field declares at the top
                     level, because a nested field is configured the same way: a
                     nested Assets row's `mode` decides whether a URL is matched or
                     uploaded, a nested Date's format parses its value, a nested
                     relation's match-by resolves it. All honoured at sync time,
                     because a sub-row is a whole mapping the applier descends into.

                     Spans the row's columns below it, mounted only while open so a
                     card's worth of pickers doesn't fetch on tab load. -->
                <div v-if="hasExtras(sub)" class="sub-field-extras" :data-expanded="isExpanded(sub) ? 'true' : 'false'">
                    <v-mapping-extras
                        v-if="isExpanded(sub)"
                        :nodes="sub.extra"
                        :mapping="rows[sub.handle] || {}"
                        :node-options="nodeOptions"
                        :discovered-nodes="discoveredNodes"
                        :read-only="readOnly"
                        @update:mapping="replaceRow(sub.handle, $event)"
                    />
                </div>
            </div>
        </template>
    </v-mapping-group-card>
</template>

<script>
import SearchableSelect from '../../SearchableSelect.vue';
import MappingGroupCard from '../../../components/MappingGroupCard.vue';
import MappingExtras from '../MappingExtras.vue';
import { controlFor } from '../registry.js';

/** Node types whose control fetches its markup from the server on mount. */
const SERVER_RENDERED = ['element', 'icon'];

/**
 * The shared sub-field mapping table: source-node + default rows for one
 * group of sub-fields, inside the MappingGroupCard chrome (chevron,
 * mapped/missing/count pills, column headings, per-row missing badges).
 * Its three consumers all map their own wire shape onto this component's rows
 * contract and stay out of the rendering: ElementSubFields (a related
 * element's natives AND its layout's custom fields, split across the
 * mapping's `nativeFields` / `fields` channels), MatrixFields (one Matrix
 * block type's slice of `blocks`, split the same way) and SubFields for a
 * field's own columns (the flat `fields` map, one channel only).
 *
 * Rows contract: `rows` is the saved map `{handle: {node?, default?,
 * useDefault?, ...}}` for the sub-fields in `node.subFields` — ONE map
 * however many channels the consumer splits it into, since a row is addressed
 * by bare handle. Every edit emits `update:rows` with the fully-rewritten map.
 * Row rewrites are PRESERVING: only node / default / useDefault are rewritten,
 * a row's unknown keys (a Matrix child's `options`, nested `fields`, …)
 * round-trip untouched, and a row is dropped only when nothing at all is left
 * on it.
 *
 * `__default__` is the same UI-only sentinel MappingRow uses: it
 * round-trips to a row's `useDefault` flag, never the wire node. Each row
 * carries its own missing-mapping state (saved node no longer in the
 * fetched sample) — independent of the parent mapping row's.
 */
export default {
    name: 'SubFieldRows',

    emits: ['update:rows'],

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

    data() {
        return {
            // Which rows have their extras open, by handle. Panel state, not value
            // state — a wipe of the rows deliberately leaves it alone, the way the
            // parent row's own toggle survives one.
            expanded: {},
        };
    },

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
        controlFor,

        serverRendered(sub) {
            return SERVER_RENDERED.includes(sub.type);
        },

        /** Whether this row's field declares extras of its own to configure. */
        hasExtras(sub) {
            return (sub.extra || []).length > 0;
        },

        isExpanded(sub) {
            // A row that already has extras saved starts open, so a configured one
            // isn't hidden behind a chevron nobody thought to click.
            return this.expanded[sub.handle]
                ?? Object.keys(this.rows[sub.handle]?.options || {}).length > 0;
        },

        toggleExtras(sub) {
            if (! this.hasExtras(sub)) return;

            this.expanded = { ...this.expanded, [sub.handle]: ! this.isExpanded(sub) };
        },

        /**
         * Put a row's whole rewritten mapping back — what its extras emit. Its
         * node / default / useDefault ride along untouched, and a row left with
         * nothing drops out, which is the same rule updateRow() applies.
         */
        replaceRow(handle, mapping) {
            const next = { ...this.rows };

            if (Object.keys(mapping || {}).length === 0) {
                delete next[handle];
            } else {
                next[handle] = mapping;
            }

            this.$emit('update:rows', next);
        },

        // An empty rows map is a normal emit, so the consumer's own merge
        // does the collapsing — MatrixFields drops the emptied channels and
        // then the block type off `blocks`, ElementSubFields writes both
        // channels empty, and the slot writer prunes from there.
        clearRows() {
            this.$emit('update:rows', {});
        },

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
            if (! saved) return false;
            if (! this.discoveredNodes) return false;
            return ! this.discoveredNodes.some(o => o.value === saved);
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

    components: {
        'v-searchable-select': SearchableSelect,
        'v-mapping-group-card': MappingGroupCard,
        'v-mapping-extras': MappingExtras,
    },
};
</script>
