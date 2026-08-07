<template>
    <div class="influx-schema-form">
        <!-- The field's own options (Match by, On conflict, a date format, a user
             group's toggles) grouped in one bordered card from the source-node
             column onward. -->
        <div v-if="optionNodes.length" class="extras-options" role="group">
            <template v-for="(node, idx) in optionNodes" :key="node.handle || idx">
                <!-- A lightswitch carries its own label beside the toggle, and a
                     note is only text — neither takes the labelled `.option`
                     wrapper the rest do. -->
                <component
                    :is="controlFor(node)"
                    v-if="unlabelled(node)"
                    :node="node"
                    :model-value="valueFor(node)"
                    inline-label
                    :read-only="readOnly"
                    @update:model-value="writeLeaf(node, $event)"
                />

                <div v-else class="option">
                    <label v-text="node.label"></label>
                    <component
                        :is="controlFor(node)"
                        :node="node"
                        :model-value="valueFor(node)"
                        empty-is-value
                        placeholder="—"
                        :read-only="readOnly"
                        @update:model-value="writeLeaf(node, $event)"
                    />
                    <!-- Instructions render as HTML: they're server-authored schema
                         strings (may contain <code>), never operator input. -->
                    <p v-if="node.instructions" class="light hint" v-html="node.instructions" />
                </div>
            </template>
        </div>

        <!-- Sub-mapping cards, each binding a whole stored channel rather than one
             value. Rendered after the options card, as their own group cards
             spanning all three columns so their rows read like parent mapping rows.
             Matrix cards come off the raw schema: every block type renders at once,
             so they are never showIf-gated. They do get the row's options — a card
             whose meaning depends on a setting (which block type a single-type
             list is for) can only know it from there. -->
        <component
            :is="controlFor(node)"
            v-for="(node, idx) in cardNodes"
            :key="cardKey(node, idx)"
            :node="node"
            :channels="channelsFor(node)"
            :node-options="nodeOptions"
            :discovered-nodes="discoveredNodesFor(node)"
            :mapping-options="mapping.options || {}"
            :read-only="readOnly"
            @update:channels="writeCard($event)"
        />
    </div>
</template>

<script>
import { controlFor } from './registry.js';
import { channelsFor as nodeChannels, readChannels, readNode, writeChannels, writeNode } from '../lib/slots.js';
import { isVisible } from '../lib/conditions.js';

/**
 * A mapping row's extras — the `extra` region its field's strategy declared,
 * rendered through the same `type => component` registry the two cells use.
 *
 * Two binding arities meet here, and that is the only thing this knows about a
 * node beyond its type. A LEAF binds one value: one key of the mapping's `options`,
 * named by its handle. A CONTAINER binds whole stored channels (`fields` /
 * `nativeFields` / `blocks`) and emits the same channels back, because what it edits
 * is a map of rows whose channel is a property of the schema rather than of the
 * table. `lib/slots.js` owns both.
 *
 * Stateless, like the cells: whoever owns the mapping owns the write, and this
 * emits the whole new mapping.
 *
 * That single-mapping contract is what lets a NESTED row reuse this. A sub-field
 * row's stored shape is itself a whole mapping, so a nested Assets row's `mode` is
 * one key of its own `options` and its alt/title card is its own `nativeFields`
 * channel — addressed exactly as a top-level row's are, and honoured the same way
 * at sync time.
 *
 * Separate from {@see SchemaForm} — which renders a flat auth-strategy schema as
 * stacked Craft `.field` blocks — because the two share no chrome at all: this is a
 * three-column grid of cards, that is a labelled column of inputs. They share the
 * registry, which is the part worth sharing.
 */
export default {
    name: 'MappingExtras',

    emits: ['update:mapping'],

    props: {
        // The `extra` region's nodes.
        nodes: { type: Array, default: () => [] },
        // The stored mapping these extras configure — a field's, or a sub-field
        // row's when they're nested.
        mapping: { type: Object, default: () => ({}) },
        // Source-node candidates for the sub-field cards' selects.
        nodeOptions: { type: Array, default: () => [] },
        // The sample's discovered flatNodes, for per-sub-field missing detection.
        // Null until a sample has been fetched.
        discoveredNodes: { type: Array, default: null },
        readOnly: { type: Boolean, default: false },
    },

    computed: {
        /**
         * Nodes whose showIf conditions all pass against the stored options
         * ({@see ../lib/conditions.js} owns the grammar).
         */
        visibleNodes() {
            return this.nodes.filter((node) => isVisible(node, this.resolvedValue));
        },

        /** The leaf nodes — everything that isn't a sub-mapping card. */
        optionNodes() {
            return this.visibleNodes.filter((node) => ! nodeChannels(node));
        },

        /**
         * The container nodes. Matrix cards come off the RAW node list rather than
         * the visible one: every block type's card renders at once, and none is
         * ever showIf-gated.
         */
        cardNodes() {
            return this.nodes.filter((node) => nodeChannels(node));
        },
    },

    methods: {
        controlFor,

        /** Whether the control renders its own label rather than taking a heading. */
        unlabelled(node) {
            return node.type === 'lightswitch' || node.type === 'note';
        },

        valueFor(node) {
            return readNode(this.mapping, 'extra', node);
        },

        channelsFor(node) {
            return readChannels(this.mapping, node);
        },

        /**
         * The discovered nodes a card checks its saved paths against — none for
         * a Matrix card. Its sub-field paths are relative to one item of the
         * list the row names, and discovery only ever produced absolute paths,
         * so every one of them would read as missing. Null is already this
         * prop's "nothing to check against".
         */
        discoveredNodesFor(node) {
            return node.type === 'matrixFields' ? null : this.discoveredNodes;
        },

        /** showIf conditions resolve against the same declared-default fallback. */
        resolvedValue(handle) {
            const node = this.nodes.find((n) => n.handle === handle);

            return this.mapping.options?.[handle] ?? node?.default;
        },

        writeLeaf(node, value) {
            this.$emit('update:mapping', writeNode(this.mapping, 'extra', node, value));
        },

        writeCard(channels) {
            this.$emit('update:mapping', writeChannels(this.mapping, channels));
        },

        /** Stable across a re-render: a Matrix card is one per block type. */
        cardKey(node, idx) {
            return `${node.type}-${node.blockType || node.handle || idx}`;
        },
    },
};
</script>
