<template>
    <div class="influx-mapping-row"
         :class="{ 'has-extras': hasExtras }"
         :data-field-handle="field.handle"
         :data-missing="isMissing ? 'true' : 'false'"
         :data-auto="isAuto ? 'true' : 'false'"
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
                :class="{ collapsed: ! extrasExpanded }"
                :aria-expanded="extrasExpanded ? 'true' : 'false'"
                :aria-label="extrasExpanded ? $t('Hide options') : $t('Configure')"
                :title="extrasExpanded ? $t('Hide options') : $t('Configure')"
            >
                <span aria-hidden="true">▼</span>
            </button>

            <span class="name" v-text="field.name"></span>

            <!-- Shown by the row's `data-auto` attribute (mapping-row.css):
                 this node was filled in by Auto-match, not picked. Cleared the
                 moment the user picks one themselves. -->
            <span class="influx-auto-badge"
                  :title="$t('Filled in by Auto-match')"
                  v-text="$t('auto')"></span>

            <span v-if="isMissing"
                  class="influx-missing-badge"
                  :title="$t('Source node isn’t in the fetched sample. Pick a new node or clear the mapping if no longer in use.')"
                  v-text="$t('missing mapping')"></span>

            <code class="handle light" v-text="field.handle"></code>
        </div>

        <!-- The two cells, each rendered from the region its field's strategy
             declared. No branch on field kind lives here: an Icon field gets an
             icon picker because PHP said `icon`, and a field nothing can be mapped
             to gets a note in the cell its node select would have taken.

             A region nobody declared renders nothing — which is how a row whose
             value derives entirely from its sub-mappings (a Matrix) says it has
             neither cell, while keeping the shared grid columns. When only the
             source cell is declared, it spans the default's column too rather than
             sitting squeezed in the middle. -->
        <div :class="{ 'influx-cell-span': ! hasDefaultCell }">
            <v-mapping-cell
                :nodes="sourceNodes"
                region="source"
                :mapping="mapping"
                :options="nodeOptions"
                :read-only="readOnly"
                @update:mapping="onSourceWrite"
            />
        </div>

        <div v-if="hasDefaultCell">
            <v-mapping-cell
                :nodes="defaultNodes"
                region="default"
                :mapping="mapping"
                :picker-handle="pickerHandle"
                :read-only="readOnly"
                @update:mapping="write"
            />
        </div>

        <!-- Per-field options block: the `extra` region, rendered through the same
             registry the two cells use. No field-kind branches live here — adding a
             mapping kind is a single-PHP-file change. The `data-expanded` attribute
             mirrors the toggle state for the row's `:has()` tint selector in
             mapping-row.css. -->
        <div v-if="hasExtras"
             class="influx-mapping-extras influx-mapping-extras-slot"
             :data-expanded="extrasExpanded ? 'true' : 'false'"
        >
            <div v-show="extrasExpanded" class="extras-body">
                <v-mapping-extras
                    :nodes="extraNodes"
                    :mapping="mapping"
                    :node-options="extrasNodeOptions"
                    :discovered-nodes="discoveredNodes"
                    :read-only="readOnly"
                    @update:mapping="write"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Extras span the row's full width (`grid-column: 1 / -1`, via the
   `.influx-mapping-extras-slot` rule in mapping-row.css). The grid that
   aligns extras rows with the row's Field / Source-node / Default-value
   columns lives in schema-form.css. The block starts flush under the row
   controls — collapsed it renders nothing at all. The expanded tint comes
   from the row's `:has()` selector in mapping-row.css, keyed off
   `data-expanded`. */

.influx-mapping-extras {
    margin-top: 0;
    background: transparent;
    border-top: 0;
}

.extras-body { padding: 0 0 4px; }
</style>

<script>
import MappingCell from '../schema/MappingCell.vue';
import MappingExtras from '../schema/MappingExtras.vue';
import { store } from '../store.js';
import { discoveredNodes as reportNodes, isMissingNode, mergeNodeOptions, replaceMapping } from '../lib/mappings.js';

/**
 * One row in the Mapping tab, laid out as the three regions its field's strategy
 * declared: the source-node cell, the default-value cell, and a collapsible extras
 * block. All three render through the same `type => component` registry, so the row
 * itself knows nothing about field kinds — see {@see ../schema/MappingCell} and
 * {@see ../schema/MappingExtras}.
 *
 * Writes land straight in `link.mappings[handle]` on the reactive store; the parent
 * watches it via the dirty flag. The regions are stateless and hand back whole new
 * `mappings` objects, so nothing is cached here either — which is what lets code
 * OUTSIDE the row (a group-level clear) rewrite this handle and have the cells and
 * cards redraw from the store.
 */
export default {
    name: 'MappingRow',

    props: {
        field: { type: Object, required: true },
        // Available source-node candidates from the latest Fetch sample,
        // shape `[{value, label}]`. Empty when no sample has been run yet.
        nodeOptions: { type: Array, default: () => [] },
    },

    data() {
        return {
            // Rows with a saved mapping start with their extras open. Panel
            // state, not value state — the one thing here that IS local, and
            // that a wipe of the mapping deliberately leaves alone.
            extrasExpanded: Object.keys(store.link.mappings?.[this.field.handle] || {}).length > 0,
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

        // An extras block exists exactly when the strategy declared an extras
        // region — no separate flag to keep in sync. A field nothing can be mapped
        // to declares none, so its note renders in the source cell instead, with no
        // toggle and nothing to expand to.
        extraNodes() {
            return this.field.mapping?.extra || [];
        },

        hasExtras() {
            return this.extraNodes.length > 0;
        },

        /**
         * Source-node candidates for the extras' sub-field dropdowns: the latest
         * Fetch-sample nodes straight off the store, merged with saved sub-field
         * paths — the flat `fields` (Table columns) and `nativeFields` rows plus
         * every block type's nested `blocks.*.fields` rows — so the dropdowns render
         * before a sample exists. Distinct from the `nodeOptions` prop, which feeds
         * the row's own source-node cell.
         */
        extrasNodeOptions() {
            const blockRows = Object.values(this.mapping.blocks || {})
                .flatMap((entry) => Object.values(entry?.fields || {}));
            const saved = [
                ...Object.values(this.mapping.fields || {}),
                ...Object.values(this.mapping.nativeFields || {}),
                ...blockRows,
            ]
                .map((row) => row?.node)
                .filter(Boolean);

            return mergeNodeOptions(store.ui.sample?.flatNodes ?? [], saved);
        },

        /**
         * The raw discovered nodes — sub-field rows compare their saved
         * node against these for their own missing-mapping state. Null
         * (no sample, or a partial one) means "can't know", so nothing
         * reads as missing.
         */
        discoveredNodes() {
            return reportNodes(store.ui.sample);
        },

        // This row's node came from Auto-match and hasn't been touched since.
        // Transient UI state off the store, not part of the saved mapping.
        isAuto() {
            return store.ui.autoMatched.includes(this.field.handle) && !!this.mapping.node;
        },

        // Through the shared rule, so this badge and the counts that summarize it
        // (the group's pill, the sidebar's total) can't disagree about which rows
        // they mean.
        isMissing() {
            return isMissingNode(this.field, this.mapping, this.discoveredNodes);
        },

        /**
         * The nodes each cell's region declared. Both may be empty: a field whose
         * value derives entirely from its sub-mappings declares neither, and a
         * field nothing can be mapped to declares a source region holding a note
         * and no default region at all.
         */
        sourceNodes() {
            return this.field.mapping?.source || [];
        },

        defaultNodes() {
            return this.field.mapping?.default || [];
        },

        hasDefaultCell() {
            return this.defaultNodes.length > 0;
        },

        /**
         * The handle a server-rendered picker is shaped after (an element select's
         * sources, an icon picker's Pro gating). Only a CUSTOM field's is sent:
         * `fieldClass` is what makes a descriptor custom, and a native row must
         * send nothing or a real custom field handled `author` would reshape the
         * native author's picker.
         */
        pickerHandle() {
            return this.field.fieldClass ? this.field.handle : null;
        },
    },

    methods: {
        toggleExtras() {
            if (! this.hasExtras) return;
            this.extrasExpanded = ! this.extrasExpanded;
        },

        /**
         * THE write path: the regions hand back a whole new mapping for this field,
         * and the row splices it into the store — dropping the handle when nothing
         * is left on it, so Project Config stays free of empty rows.
         */
        write(mapping) {
            this.link.mappings = replaceMapping(this.link.mappings, this.field.handle, mapping);
        },

        /**
         * A source-node pick is the operator's now, whatever Auto-match had put
         * there. Only this cell clears the badge: it flags the NODE, and editing
         * the default beside it leaves the machine-filled node exactly as it was.
         */
        onSourceWrite(mapping) {
            store.clearAutoMatch(this.field.handle);
            this.write(mapping);
        },

    },

    components: {
        'v-mapping-cell': MappingCell,
        'v-mapping-extras': MappingExtras,
    },
};
</script>
