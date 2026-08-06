<template>
    <!-- Two cards, because the operator is answering two different questions:
         what the table looks like, and where each column's values come from.
         The second is derived from the first — adding a column adds its mapping
         row — which is why one node owns both rather than two nodes trying to
         agree about state neither has saved yet. -->
    <v-mapping-group-card :label="node.label" variant="subfields">
        <!-- Action first, then the pills — the order the cards' "clear nodes" link
             already sets, so the header's chrome reads the same wherever it appears.
             `.stop` on all three: the header is itself the collapse toggle, on click
             AND on keydown.enter/.space (both with .prevent), so without it pressing
             this also collapses the card and its .prevent kills the button's native
             Enter → click before it fires. -->
        <template #tags>
            <button
                v-if="! readOnly"
                type="button"
                class="influx-clear-link add-column"
                @click.stop="addColumn"
                @keydown.enter.stop
                @keydown.space.stop
                v-text="node.addLabel || $t('add column')"
            ></button>
            <span class="pill pill-count" v-text="columns.length"></span>
        </template>

        <p v-if="! columns.length" class="influx-mapping-group-empty light"
           v-text="node.emptyHint || $t('Add a column to start mapping this table.')"></p>

        <!-- Headings and rows are siblings on the card's subgrid rather than a
             <table>, so a column's controls land on the very tracks its mapping
             row uses in the Cells card below: heading under Field, type/align
             under Source node, width under Default value. A table would have
             measured its own columns and drifted off them. -->
        <template v-else>
            <div class="influx-tablemaker-headings">
                <div v-text="$t('Heading')"></div>
                <div class="influx-tablemaker-pair">
                    <span v-text="$t('Type')"></span>
                    <span v-if="node.enableAlign" v-text="$t('Align')"></span>
                </div>
                <div v-text="node.enableWidth ? $t('Width') : ''"></div>
            </div>

            <div
                v-for="(column, index) in columns"
                :key="column.id"
                class="influx-tablemaker-row"
                :class="{ 'is-dragging': draggingIndex === index }"
                @dragover.prevent
                @drop.prevent="dropOn(index)"
            >
                <div class="influx-tablemaker-heading-cell">
                    <!-- Craft's own handle and glyph. Reordering is real work here:
                         it decides the written column order, and the cell mappings
                         ride along untouched because they're keyed by column id. -->
                    <a
                        v-if="! readOnly"
                        class="move icon"
                        role="button"
                        :title="$t('Reorder')"
                        :draggable="true"
                        @dragstart="draggingIndex = index"
                        @dragend="draggingIndex = null"
                    ></a>
                    <input type="text" class="text fullwidth" :value="column.heading" :disabled="readOnly"
                           @input="patch(index, 'heading', $event.target.value)" />
                </div>

                <div class="influx-tablemaker-pair">
                    <div class="select">
                        <select :value="column.type" :disabled="readOnly"
                                @change="patch(index, 'type', $event.target.value)">
                            <option v-for="(label, value) in node.columnTypes" :key="value"
                                    :value="value" v-text="label"></option>
                        </select>
                    </div>
                    <div v-if="node.enableAlign" class="select">
                        <select :value="column.align" :disabled="readOnly"
                                @change="patch(index, 'align', $event.target.value)">
                            <option v-for="opt in ALIGNMENTS" :key="opt.value"
                                    :value="opt.value" v-text="$t(opt.label)"></option>
                        </select>
                    </div>
                </div>

                <!-- The delete rides in the last track beside the width rather than
                     outside it, so the track still lines up with Default value. -->
                <div class="influx-tablemaker-width-cell">
                    <input v-if="node.enableWidth" type="text" class="text fullwidth"
                           :value="column.width" :disabled="readOnly"
                           @input="patch(index, 'width', $event.target.value)" />
                    <a v-if="! readOnly" class="delete icon" role="button" :title="$t('Delete')"
                       @click.prevent="removeColumn(index)"></a>
                </div>
            </div>
        </template>
    </v-mapping-group-card>

    <!-- The cells, as the same table every other sub-field card renders — so a
         column row reads exactly like a Table column's or a Matrix child's. -->
    <v-sub-field-rows
        v-if="columns.length"
        :node="cellsNode"
        :rows="channels.fields || {}"
        :node-options="nodeOptions"
        :discovered-nodes="discoveredNodes"
        :read-only="readOnly"
        @update:rows="writeRows"
    />
</template>

<script>
import MappingGroupCard from '@cp/components/MappingGroupCard.vue';
import SubFieldRows from '@cp/builder/schema/inputs/SubFieldRows.vue';
import './tablemaker-columns.css';

/**
 * Schema `tableMakerColumns` node: the column editor for a Table Maker field,
 * plus the per-column source-node rows those columns imply.
 *
 * A Craft Table's columns are field settings, so its card's rows come off the
 * field and PHP can declare them. Table Maker's columns are per-entry CONTENT
 * ({@see \GlueAgency\Influx\integrations\verbb\tablemaker\TableMakerField}), so
 * there is nothing on the server to declare them from — the operator authors them
 * here and they are written to the field on every sync alongside the rows.
 *
 * Which is why this binds TWO channels. The column definitions are configuration
 * and live in the mapping's `options.columns`; the per-column mappings are
 * mappings and live in its `fields`, keyed by column id. Splitting them across two
 * nodes would mean the rows card rendering from state the columns card hasn't
 * saved yet, so one node owns both and emits them together.
 *
 * The id is minted here and never reused within a session, because it is the only
 * thing tying a column to its sub-mapping: Table Maker stores columns as a bare
 * positional list, so keying the mappings on position would silently re-point
 * every cell the moment a column was inserted, removed or reordered. Removing a
 * column takes its mapping with it — leaving an orphan would resurrect the wrong
 * cell if a later column ever minted the same id.
 */
export default {
    name: 'TableMakerColumns',

    inheritAttrs: false,

    emits: ['update:channels'],

    props: {
        node: { type: Object, required: true },
        // `options` (holding `columns`) and `fields`, per its registry entry in
        // lib/slots.js.
        channels: { type: Object, default: () => ({}) },
        nodeOptions: { type: Array, default: () => [] },
        discoveredNodes: { type: Array, default: null },
        readOnly: { type: Boolean, default: false },
    },

    data() {
        return {
            // Which row the handle is currently dragging, or null.
            draggingIndex: null,
            ALIGNMENTS: [
                { value: '', label: 'Default' },
                { value: 'left', label: 'Left' },
                { value: 'center', label: 'Center' },
                { value: 'right', label: 'Right' },
            ],
        };
    },

    computed: {
        /** The declared columns, always a list — stored config is hand-editable. */
        columns() {
            const stored = this.channels.options?.columns;

            return Array.isArray(stored) ? stored.filter((c) => c && c.id) : [];
        },

        /**
         * The cells card's node: one sub-field per declared column, labelled by
         * its heading and handled by its id. A flag column gets a lightswitch
         * default the way its cell will actually be stored; everything else takes
         * the text box, since a default here is one literal value.
         */
        cellsNode() {
            return {
                type: 'subFields',
                handle: 'fields',
                label: this.$t('Cells'),
                subFields: this.columns.map((column) => ({
                    handle: column.id,
                    label: column.heading || this.$t('Untitled column'),
                    type: ['checkbox', 'lightswitch'].includes(column.type) ? 'lightswitch' : 'text',
                })),
            };
        },
    },

    methods: {
        /** Emit both channels, pruning `columns` off `options` when it empties. */
        write(columns, fields) {
            const options = { ...(this.channels.options || {}) };

            if (columns.length) {
                options.columns = columns;
            } else {
                delete options.columns;
            }

            this.$emit('update:channels', { options, fields });
        },

        writeRows(fields) {
            this.write(this.columns, fields);
        },

        /**
         * The next id, past every one in use. Scanning rather than counting: a
         * removed column must not free its id for the next one, or the new
         * column would inherit whatever mapping the old one left behind
         * anywhere the removal didn't reach.
         */
        nextId() {
            const used = this.columns.map((c) => parseInt(String(c.id).replace(/^c/, ''), 10) || 0);

            return `c${Math.max(0, ...used) + 1}`;
        },

        addColumn() {
            this.write(
                [...this.columns, { id: this.nextId(), heading: '', type: 'singleline', align: '', width: '' }],
                this.channels.fields || {},
            );
        },

        removeColumn(index) {
            const removed = this.columns[index];
            const fields = { ...(this.channels.fields || {}) };
            delete fields[removed.id];

            this.write(this.columns.filter((_, i) => i !== index), fields);
        },

        /**
         * Drop the dragged column at this index. The cell mappings are untouched:
         * they're keyed by column id, which is the whole reason the id exists —
         * on a positional key every mapping would follow the slot rather than the
         * column and silently re-point.
         */
        dropOn(index) {
            const from = this.draggingIndex;
            this.draggingIndex = null;

            if (from === null || from === index) {
                return;
            }

            const columns = [...this.columns];
            columns.splice(index, 0, ...columns.splice(from, 1));

            this.write(columns, this.channels.fields || {});
        },

        patch(index, key, value) {
            this.write(
                this.columns.map((column, i) => (i === index ? { ...column, [key]: value } : column)),
                this.channels.fields || {},
            );
        },
    },

    components: { 'v-mapping-group-card': MappingGroupCard, 'v-sub-field-rows': SubFieldRows },
};
</script>
