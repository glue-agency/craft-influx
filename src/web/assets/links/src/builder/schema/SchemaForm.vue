<template>
    <!-- Stacked layout: Craft-style .field blocks (heading / instructions /
         input), used by full-width forms like the Auth tab. -->
    <div v-if="layout === 'stacked'" class="influx-schema-form is-stacked">
        <div v-for="(node, idx) in visibleNodes" :key="node.handle || idx" class="field">
            <div class="heading"><label :for="fieldId(node)">{{ node.label }}</label></div>
            <div v-if="node.instructions" class="instructions"><p v-html="node.instructions" /></div>
            <div class="input ltr">
                <select-input
                    v-if="node.type === 'select'"
                    :node="node"
                    :model-value="valueFor(node)"
                    :read-only="readOnly"
                    @update:model-value="setOption(node, $event)"
                />
                <label v-else-if="node.type === 'lightswitch'" class="inline-toggle">
                    <input
                        type="checkbox"
                        :checked="!!valueFor(node)"
                        :disabled="readOnly"
                        @change="setOption(node, $event.target.checked)"
                    >
                </label>
                <tokenized-input
                    v-else-if="node.type === 'tokenInput'"
                    :model-value="valueFor(node) ?? ''"
                    :token-groups="tokenGroups"
                    :placeholder="node.placeholder || ''"
                    :disabled="readOnly"
                    @update:model-value="setOption(node, $event)"
                />
                <input
                    v-else
                    :id="fieldId(node)"
                    type="text"
                    :class="['text', 'fullwidth', node.type === 'code' ? 'code' : null]"
                    :value="valueFor(node) ?? ''"
                    :placeholder="node.placeholder || ''"
                    :disabled="readOnly"
                    @input="setOption(node, $event.target.value)"
                >
            </div>
        </div>
    </div>

    <!-- Grid layout (default): the extras share the mapping rows' column
         grid, with everything placed on the source-node column onward.
         Two dedicated structures: the field's own options (Match by, …)
         grouped in one bordered fieldset card, then element sub-field
         mappings as nested collapsible groups reusing the main list's
         group chrome. -->
    <div v-else class="influx-schema-form">
        <div v-if="optionNodes.length" class="extras-options" role="group">
            <template v-for="(node, idx) in optionNodes" :key="node.handle || idx">
                <!-- Static explanatory text (e.g. the Matrix stub) -->
                <p v-if="node.type === 'note'" class="light">{{ node.text }}</p>

                <label v-else-if="node.type === 'lightswitch'" class="inline-toggle">
                    <input
                        type="checkbox"
                        :checked="!!valueFor(node)"
                        :disabled="readOnly"
                        @change="setOption(node, $event.target.checked)"
                    >
                    {{ node.label }}
                </label>

                <!-- Instructions render as HTML in both layouts: they're
                     server-authored BuilderSchema strings (may contain
                     <code>), never user input. -->
                <div v-else-if="node.type === 'valueMapTable'" class="value-map-node">
                    <p v-if="node.instructions" class="light hint" v-html="node.instructions" />
                    <value-map-table
                        :node="node"
                        :model-value="valueFor(node) || {}"
                        :read-only="readOnly"
                        @update:model-value="setOption(node, $event)"
                    />
                </div>

                <div v-else class="option">
                    <label>{{ node.label }}</label>
                    <select-input
                        v-if="node.type === 'select'"
                        :node="node"
                        :model-value="valueFor(node)"
                        searchable
                        :read-only="readOnly"
                        @update:model-value="setOption(node, $event)"
                    />
                    <tokenized-input
                        v-else-if="node.type === 'tokenInput'"
                        :model-value="valueFor(node) ?? ''"
                        :token-groups="tokenGroups"
                        :placeholder="node.placeholder || ''"
                        :disabled="readOnly"
                        @update:model-value="setOption(node, $event)"
                    />
                    <input
                        v-else
                        type="text"
                        :class="['text', node.type === 'code' ? 'code' : null]"
                        :value="valueFor(node) ?? ''"
                        :placeholder="node.placeholder || ''"
                        :disabled="readOnly"
                        @input="setOption(node, $event.target.value)"
                    >
                    <p v-if="node.instructions" class="light hint" v-html="node.instructions" />
                </div>
            </template>
        </div>

        <!-- Recursive native sub-fields (asset alt/title) — writes the
             mapping's nativeFields channel, not options. Rendered after
             the options fieldset as their own group cards. -->
        <element-sub-fields
            v-for="(node, idx) in subFieldNodes"
            :key="'subfields-' + (node.handle || idx)"
            :node="node"
            :model-value="nativeFields"
            :node-options="nodeOptions"
            :discovered-nodes="discoveredNodes"
            :read-only="readOnly"
            @update:model-value="$emit('update:nativeFields', $event)"
        />
    </div>
</template>

<script>
import SelectInput from './inputs/SelectInput.vue';
import ElementSubFields from './inputs/ElementSubFields.vue';
import ValueMapTable from './inputs/ValueMapTable.vue';
import TokenizedInput from '../TokenizedInput.vue';

/**
 * Generic renderer for the declarative form-node schema PHP strategies
 * declare via Field::defineExtrasSchema() (see helpers/BuilderSchema.php).
 * Dispatches purely on node *type* — it knows nothing about field kinds,
 * which is exactly what keeps "add a mapping kind" a single-PHP-file change.
 *
 * Stateless: values come from the `options` / `nativeFields` props, edits
 * emit fully-merged replacements upward. The parent owns the models.
 */
export default {
    name: 'SchemaForm',

    components: { SelectInput, ElementSubFields, ValueMapTable, TokenizedInput },

    props: {
        schema: { type: Array, required: true },
        options: { type: Object, required: true },
        nativeFields: { type: Object, default: () => ({}) },
        // Source-node candidates for elementSubFields selects.
        nodeOptions: { type: Array, default: () => [] },
        // The sample's discovered flatNodes, for per-sub-field missing
        // detection. Null until a sample has been fetched.
        discoveredNodes: { type: Array, default: null },
        // Suggestion groups for tokenInput nodes (env vars / aliases / custom tokens).
        tokenGroups: { type: Array, default: () => [] },
        // 'grid' (mapping-extras rows) or 'stacked' (Craft .field blocks).
        layout: { type: String, default: 'grid' },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:options', 'update:nativeFields'],

    computed: {
        /**
         * Nodes whose showIf conditions all pass against the current
         * options. A condition without `equals` means "truthy".
         */
        visibleNodes() {
            return this.schema.filter((node) =>
                (node.showIf || []).every((cond) =>
                    'equals' in cond
                        ? this.resolvedValue(cond.handle) === cond.equals
                        : !!this.options[cond.handle],
                ),
            );
        },

        /** The field's own options (Match by, conflict, …) — everything
         *  except sub-field mapping groups. */
        optionNodes() {
            return this.visibleNodes.filter((node) => node.type !== 'elementSubFields');
        },

        /** Sub-field mapping nodes, rendered as nested group cards. */
        subFieldNodes() {
            return this.visibleNodes.filter((node) => node.type === 'elementSubFields');
        },
    },

    methods: {
        /**
         * Display value: the saved option, falling back to the node's
         * declared default. Defaults are display-only — they're never
         * written into the saved options unless the user touches the
         * control, so untouched mappings stay free of noise keys.
         */
        valueFor(node) {
            const saved = this.options[node.handle];
            return saved !== undefined ? saved : node.default;
        },

        /** showIf conditions resolve against the same default fallback. */
        resolvedValue(handle) {
            const saved = this.options[handle];
            if (saved !== undefined) return saved;
            const node = this.schema.find((n) => n.handle === handle);
            return node?.default;
        },

        setOption(node, value) {
            this.$emit('update:options', { ...this.options, [node.handle]: value });
        },

        fieldId(node) {
            return `schema-field-${node.handle}`;
        },
    },
};
</script>

<style>
/* Deliberately unscoped (prefixed by .influx-schema-form) so the grid rules
   reach the child input components too — scoped styles wouldn't. Mirrors
   the parent mapping row's 3-column grid so labels and controls line up
   with the Field / Source-node / Default-value columns above. */

/* The grid form sits on the mapping rows' column grid: everything —
   the options fieldset and the sub-field groups — starts at the
   source-node column's left edge and runs to the row's right edge. The
   field-name column stays empty: these are details *of* the field above,
   not siblings.

   Scoped to :not(.is-stacked): the stacked variant (Auth tab) renders
   plain Craft .field blocks and must NOT inherit this grid, or each field
   gets pushed into column 2 with an empty column to its left. */
.influx-schema-form:not(.is-stacked) {
    display: grid;
    grid-template-columns: minmax(180px, 1.2fr) minmax(220px, 1.4fr) minmax(180px, 1fr);
    gap: 0 12px;
}

.influx-schema-form:not(.is-stacked) > * {
    grid-column: 2 / -1;
    min-width: 0;
}

.influx-schema-form .sub-field-row,
.influx-schema-form .value-map-row {
    display: grid;
    grid-template-columns: minmax(140px, 1fr) minmax(200px, 1.4fr) minmax(140px, 1fr);
    gap: 12px;
    align-items: start;
    padding: 6px 0;
}

/* The options fieldset — one bordered card grouping the field's own
   options (Match by, Download toggle, On conflict, …). Small-caps labels
   above full-width controls, mirroring the mockup while keeping Craft's
   quiet chrome. */
.influx-schema-form .extras-options {
    /* Column 2 only — the card's edges line up with the source-node
       select above it, instead of stretching under the default column. */
    grid-column: 2;
    margin: 6px 0 0;
    padding: 10px 12px 12px;
    background: #fff;
    border: 1px solid rgba(0, 0, 0, .1);
    border-radius: 5px;
}

.influx-schema-form .extras-options .option > label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #8a96a3;
    padding: 0 0 5px;
    line-height: 1.2;
}

.influx-schema-form .extras-options > * + * {
    margin-top: 10px;
}

.influx-schema-form .extras-options .inline-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
}

/* Sub-field rows mirror the parent mapping rows — label in the first
   column, node select + default in their own columns. No chevron gutter
   here: sub-fields never collapse individually. The min-width puts a
   floor under the fit-content() track below so very short labels don't
   collapse the column. */
.influx-schema-form .sub-field-row > label {
    min-width: 100px;
    font-size: 13px;
    font-weight: 600;
    color: inherit;
    padding-top: 6px;
    line-height: 1.2;
}

/* Same handle treatment as the parent rows' meta: own line, lightened —
   reference info, not something to read on every pass. */
.influx-schema-form .sub-field-row > label .handle {
    display: block;
    margin-top: 3px;
    font-size: 11px;
    font-weight: normal;
    color: #9aa5b1;
}

/* TokenizedInput's inner segments size to their content (field-sizing) —
   forcing them full-width would wrap every empty segment onto its own row. */
.influx-schema-form input[type="text"]:not(.influx-tokenized-text),
.influx-schema-form .select select {
    width: 100%;
}

/* Sub-field mapping groups reuse the main list's .influx-mapping-group
   chrome (white card, clickable header, mapped/missing pills) — defined
   in links.css. Only the nested-context spacing lives here. */
.influx-schema-form .influx-mapping-group {
    margin: 10px 0 6px;
}

/* One shared grid for the sub-fields card body: the label column shrinks
   to its widest label/handle (capped) so the node select and default
   value get every spare pixel. Rows join the shared tracks via subgrid,
   staying aligned despite the content sizing; their fixed template above
   is the no-subgrid fallback. */
.influx-schema-form .influx-subfields-group > .influx-mapping-group-body {
    display: grid;
    grid-template-columns: fit-content(200px) minmax(200px, 1.4fr) minmax(140px, 1fr);
    column-gap: 12px;
}

.influx-schema-form .influx-subfields-group.collapsed > .influx-mapping-group-body {
    display: none;
}

.influx-schema-form .influx-subfields-group .sub-fields-hint,
.influx-schema-form .influx-subfields-group .influx-mapping-headings,
.influx-schema-form .influx-subfields-group .sub-field-row {
    grid-column: 1 / -1;
}

.influx-schema-form .influx-subfields-group .influx-mapping-headings,
.influx-schema-form .influx-subfields-group .sub-field-row {
    /* Fixed template first as the no-subgrid fallback; subgrid joins the
       headings and rows to the card body's content-sized tracks. */
    grid-template-columns: minmax(140px, 1fr) minmax(200px, 1.4fr) minmax(140px, 1fr);
    grid-template-columns: subgrid;
}

/* Sub-field rows have no chevron gutter, so the Field heading doesn't
   need the 22px inset the main list's headings carry. */
.influx-schema-form .influx-subfields-group .influx-mapping-headings > div:first-child {
    padding-left: 0;
}

.influx-schema-form .influx-mapping-group .sub-field-row {
    padding: 6px 12px;
    border-bottom: 1px solid rgba(0, 0, 0, .05);
}

.influx-schema-form .influx-mapping-group .sub-field-row:last-child {
    border-bottom: none;
}

.influx-schema-form .influx-mapping-group .sub-fields-hint {
    padding: 6px 12px 0;
    font-size: 12px;
    margin: 0;
}

.influx-schema-form .value-map-row {
    grid-template-columns: minmax(140px, 1fr) minmax(180px, 1.4fr) auto;
}

.influx-schema-form .value-map-target {
    display: grid;
    grid-template-columns: 16px 1fr;
    align-items: center;
    gap: 6px;
}

.influx-schema-form .value-map-row .arrow { color: #888; text-align: center; }

.influx-schema-form .row-actions { padding: 6px 0 4px; }

.influx-schema-form .hint { padding: 0; font-size: 12px; margin: 2px 0 6px; }

.influx-schema-form .remove-row {
    justify-self: end;
    background: none;
    border: 1px solid #ccc;
    border-radius: 4px;
    width: 24px;
    height: 24px;
    cursor: pointer;
    color: #888;
}

.influx-schema-form .remove-row:hover {
    background: #f3f5f7;
    color: #cf1124;
    border-color: #cf1124;
}
</style>
