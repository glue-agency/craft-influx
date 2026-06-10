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

    <!-- Grid layout (default): the compact 3-column mapping-extras rows. -->
    <div v-else class="influx-schema-form">
        <template v-for="(node, idx) in visibleNodes" :key="node.handle || idx">
            <!-- Static explanatory text (e.g. the Matrix stub) -->
            <p v-if="node.type === 'note'" class="light">{{ node.text }}</p>

            <!-- Recursive native sub-fields (asset alt/title) — writes the
                 mapping's nativeFields channel, not options -->
            <div v-else-if="node.type === 'elementSubFields'" class="sub-fields">
                <p class="sub-fields-title">{{ node.label }}</p>
                <p v-if="node.instructions" class="light" v-html="node.instructions" />
                <element-sub-fields
                    :node="node"
                    :model-value="nativeFields"
                    :node-options="nodeOptions"
                    :read-only="readOnly"
                    @update:model-value="$emit('update:nativeFields', $event)"
                />
            </div>

            <div v-else-if="node.type === 'lightswitch'" class="row">
                <label class="inline-toggle">
                    <input
                        type="checkbox"
                        :checked="!!valueFor(node)"
                        :disabled="readOnly"
                        @change="setOption(node, $event.target.checked)"
                    >
                    {{ node.label }}
                </label>
            </div>

            <!-- Instructions render as HTML in both layouts: they're
                 server-authored BuilderSchema strings (may contain <code>),
                 never user input. -->
            <div v-else-if="node.type === 'valueMapTable'" class="value-map-node">
                <p v-if="node.instructions" class="light hint" v-html="node.instructions" />
                <value-map-table
                    :node="node"
                    :model-value="valueFor(node) || {}"
                    :read-only="readOnly"
                    @update:model-value="setOption(node, $event)"
                />
            </div>

            <div v-else class="row">
                <label>{{ node.label }}</label>
                <div class="control">
                    <select-input
                        v-if="node.type === 'select'"
                        :node="node"
                        :model-value="valueFor(node)"
                        :read-only="readOnly"
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
            </div>
        </template>
    </div>
</template>

<script>
import SelectInput from './inputs/SelectInput.vue';
import ElementSubFields from './inputs/ElementSubFields.vue';
import ValueMapTable from './inputs/ValueMapTable.vue';

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

    components: { SelectInput, ElementSubFields, ValueMapTable },

    props: {
        schema: { type: Array, required: true },
        options: { type: Object, required: true },
        nativeFields: { type: Object, default: () => ({}) },
        // Source-node candidates for elementSubFields selects.
        nodeOptions: { type: Array, default: () => [] },
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

.influx-schema-form .row,
.influx-schema-form .sub-field-row,
.influx-schema-form .value-map-row {
    display: grid;
    grid-template-columns: minmax(180px, 1.2fr) minmax(220px, 1.4fr) minmax(180px, 1fr);
    gap: 12px;
    align-items: start;
    padding: 6px 0;
}

.influx-schema-form .row > label,
.influx-schema-form .sub-field-row > label {
    font-size: 13px;
    font-weight: normal;
    color: inherit;
    padding-top: 6px;
    line-height: 1.2;
}

.influx-schema-form .row > .inline-toggle {
    grid-column: 2 / -1;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding-top: 0;
}

.influx-schema-form input[type="text"],
.influx-schema-form .select select {
    width: 100%;
}

.influx-schema-form .sub-fields {
    margin-top: 6px;
    border-top: 1px dashed rgba(0, 0, 0, .08);
    padding-top: 6px;
}

.influx-schema-form .sub-fields-title {
    padding: 0 0 4px;
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #777;
}

.influx-schema-form .sub-fields > .light {
    padding: 0 0 6px;
    font-size: 12px;
}

.influx-schema-form .value-map-row {
    grid-template-columns: minmax(180px, 1.2fr) minmax(220px, 1.4fr) auto;
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
