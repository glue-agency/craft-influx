<template>
    <!-- Stacked layout: Craft-style .field blocks (heading / instructions /
         input), used by full-width forms like the Auth tab. -->
    <div v-if="layout === 'stacked'" class="influx-schema-form is-stacked">
        <div v-for="(node, idx) in visibleNodes" :key="node.handle || idx" class="field">
            <div class="heading"><label :for="fieldId(node)" v-text="node.label"></label></div>
            <div v-if="node.instructions" class="instructions"><p v-html="node.instructions" /></div>
            <div class="input ltr">
                <v-select-input
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
                <v-tokenized-input
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
         grid. Two dedicated structures: the field's own options (Match
         by, …) grouped in one bordered fieldset card on the source-node
         column onward, then element sub-field mappings as nested
         collapsible groups reusing the main list's group chrome — those
         cards span all three columns so their rows read like parent
         mapping rows. -->
    <div v-else class="influx-schema-form">
        <div v-if="optionNodes.length" class="extras-options" role="group">
            <template v-for="(node, idx) in optionNodes" :key="node.handle || idx">
                <!-- Static explanatory text (e.g. the Matrix stub) -->
                <p v-if="node.type === 'note'" class="light" v-text="node.text"></p>

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
                <div v-else class="option">
                    <label v-text="node.label"></label>
                    <v-select-input
                        v-if="node.type === 'select'"
                        :node="node"
                        :model-value="valueFor(node)"
                        searchable
                        :read-only="readOnly"
                        @update:model-value="setOption(node, $event)"
                    />
                    <v-tokenized-input
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

        <!-- Sub-fields the field owns itself (a Table field's columns) —
             writes the mapping's flat `fields` channel and nothing else, so
             it needs none of ElementSubFields' channel splitting and mounts
             the shared SubFieldRows table directly. -->
        <v-sub-field-rows
            v-for="(node, idx) in fieldsNodes"
            :key="'fields-' + (node.handle || idx)"
            :node="node"
            :rows="fields"
            :node-options="nodeOptions"
            :discovered-nodes="discoveredNodes"
            :read-only="readOnly"
            @update:rows="$emit('update:fields', $event)"
        />

        <!-- The related element's own sub-fields — its natives (asset
             alt/title, an entry's title) alongside its layout's custom fields,
             ONE card writing BOTH channels: a row's `channel` key decides
             which, and the card emits both on every write. Rendered after the
             options fieldset as their own group cards.

             The `fields` channel is shared with the Table card above, but no
             field type emits both card kinds, and both write full
             replacements — so they can't clobber each other. -->
        <v-element-sub-fields
            v-for="(node, idx) in subFieldNodes"
            :key="'subfields-' + (node.handle || idx)"
            :node="node"
            :fields="fields"
            :native-fields="nativeFields"
            :node-options="nodeOptions"
            :discovered-nodes="discoveredNodes"
            :read-only="readOnly"
            @update:fields="$emit('update:fields', $event)"
            @update:native-fields="$emit('update:nativeFields', $event)"
        />

        <!-- Matrix block sub-fields — one card per block type, ALL rendered
             at once (Feed Me-style; matrixFields nodes are never showIf-
             gated). Each card reads and writes its own type's slice of the
             mapping's recursive `blocks` channel (absolute item paths). -->
        <v-matrix-fields
            v-for="(node, idx) in matrixFieldNodes"
            :key="'matrixfields-' + (node.blockType || idx)"
            :node="node"
            :model-value="blocks"
            :node-options="nodeOptions"
            :discovered-nodes="discoveredNodes"
            :read-only="readOnly"
            @update:model-value="$emit('update:blocks', $event)"
        />
    </div>
</template>

<script>
import SelectInput from './inputs/SelectInput.vue';
import ElementSubFields from './inputs/ElementSubFields.vue';
import MatrixFields from './inputs/MatrixFields.vue';
import SubFieldRows from './inputs/SubFieldRows.vue';
import TokenizedInput from '../TokenizedInput.vue';

/**
 * Generic renderer for the declarative form-node schema PHP strategies
 * declare via Field::schema() (see schema/SchemaBuilder.php).
 * Dispatches purely on node *type* — it knows nothing about field kinds,
 * which is exactly what keeps "add a mapping kind" a single-PHP-file change.
 *
 * The type dispatch is open-ended by design: a type this renderer doesn't
 * know (a third-party kind pushed through SchemaBuilder::node()) lands on
 * the text-input branch, so it still renders labeled and still reads/writes
 * its handle — degrading instead of vanishing. Only `subFields`,
 * `elementSubFields` and `matrixFields` are routed away from that branch.
 *
 * Stateless: values come from the `options` / `fields` / `nativeFields` /
 * `blocks` props, edits emit fully-merged replacements upward. The parent
 * owns the models.
 */
export default {
    name: 'SchemaForm',

    emits: ['update:options', 'update:fields', 'update:nativeFields', 'update:blocks'],

    props: {
        schema: { type: Array, required: true },
        options: { type: Object, required: true },
        // The mapping's flat `fields` channel — the sub-fields a field owns
        // itself (a Table field's columns).
        fields: { type: Object, default: () => ({}) },
        nativeFields: { type: Object, default: () => ({}) },
        // The mapping's whole per-block-type tree — matrixFields cards each
        // read/write their own `blocks.<type>` slice of it.
        blocks: { type: Object, default: () => ({}) },
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
            const subFieldTypes = ['subFields', 'elementSubFields', 'matrixFields'];

            return this.visibleNodes.filter((node) => !subFieldTypes.includes(node.type));
        },

        /** Own sub-field mapping nodes — the `fields` channel's group cards. */
        fieldsNodes() {
            return this.visibleNodes.filter((node) => node.type === 'subFields');
        },

        /** Sub-field mapping nodes, rendered as nested group cards. */
        subFieldNodes() {
            return this.visibleNodes.filter((node) => node.type === 'elementSubFields');
        },

        /** Matrix block sub-field nodes — every block type's card, straight
         *  off the schema (never showIf-gated), writing the `blocks`
         *  channel. */
        matrixFieldNodes() {
            return this.schema.filter((node) => node.type === 'matrixFields');
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

    components: { 'v-select-input': SelectInput, 'v-sub-field-rows': SubFieldRows, 'v-element-sub-fields': ElementSubFields, 'v-matrix-fields': MatrixFields, 'v-tokenized-input': TokenizedInput },
};
</script>
