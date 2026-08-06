<template>
    <div class="influx-schema-form is-stacked">
        <div v-for="(node, idx) in visibleNodes" :key="node.handle || idx" class="field">
            <div class="heading"><label v-text="node.label"></label></div>
            <div v-if="node.instructions" class="instructions"><p v-html="node.instructions" /></div>
            <div class="input ltr">
                <component
                    :is="controlFor(node)"
                    :node="node"
                    :model-value="valueFor(node)"
                    :token-groups="tokenGroups"
                    native
                    :read-only="readOnly"
                    @update:model-value="setOption(node, $event)"
                />
            </div>
        </div>
    </div>
</template>

<script>
import { controlFor } from './registry.js';

/**
 * A flat schema rendered as stacked Craft `.field` blocks — heading,
 * instructions, input — which is what an auth strategy's form is
 * ({@see \GlueAgency\Influx\auth\AuthStrategyInterface::schema()}): single
 * controls over a flat `options` object, with no mapping row, no stored slot and
 * no nested cards.
 *
 * Dispatches through the same `type => component` registry the mapping cells and
 * extras use, so adding a control kind stays a component plus one registry line.
 * A type nothing claims lands on the text control, so a third-party kind pushed
 * through `SchemaBuilder::node()` still renders labelled and still reads/writes
 * its handle instead of vanishing.
 *
 * `native` is the one thing this layout asserts about its controls: a select
 * renders as the plain CP select here, which is the idiom in a stacked field —
 * {@see inputs/SelectField} still upgrades a GROUPED list, whose headings only the
 * searchable one can render.
 *
 * Stateless: values come from `options`, edits emit a fully-merged replacement.
 */
export default {
    name: 'SchemaForm',

    emits: ['update:options'],

    props: {
        schema: { type: Array, required: true },
        options: { type: Object, required: true },
        // Suggestion groups for tokenInput nodes (env vars / aliases / custom tokens).
        tokenGroups: { type: Array, default: () => [] },
        readOnly: { type: Boolean, default: false },
    },

    computed: {
        /**
         * Nodes whose showIf conditions all pass against the current options. A
         * condition without `equals` means "truthy".
         */
        visibleNodes() {
            return this.schema.filter((node) => (node.showIf || []).every((cond) => (
                'equals' in cond
                    ? this.resolvedValue(cond.handle) === cond.equals
                    : !! this.options[cond.handle]
            )));
        },
    },

    methods: {
        controlFor,

        /**
         * Display value: the saved option, falling back to the node's declared
         * default. Defaults are display-only — never written into the saved options
         * unless the operator touches the control, so an untouched form stays free
         * of noise keys.
         */
        valueFor(node) {
            const saved = this.options[node.handle];

            return saved !== undefined ? saved : node.default;
        },

        /** showIf conditions resolve against the same default fallback. */
        resolvedValue(handle) {
            const saved = this.options[handle];

            if (saved !== undefined) return saved;

            return this.schema.find((n) => n.handle === handle)?.default;
        },

        setOption(node, value) {
            this.$emit('update:options', { ...this.options, [node.handle]: value });
        },
    },
};
</script>
