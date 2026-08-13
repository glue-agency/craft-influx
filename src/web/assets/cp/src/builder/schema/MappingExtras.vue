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
            :node-options="nodeOptionsFor(node)"
            :discovered-nodes="discoveredNodesFor(node)"
            :feed-keys="feedKeys"
            :mapping-options="mapping.options || {}"
            :read-only="readOnly"
            @update:channels="writeCard($event)"
            @update:option="writeLeaf($event.node, $event.value)"
        />
    </div>
</template>

<script>
import { controlFor } from './registry.js';
import { channelsFor as nodeChannels, readChannels, readNode, writeChannels, writeNode } from '../lib/slots.js';
import { isVisible } from '../lib/conditions.js';
import { mergeNodeOptions } from '../lib/mappings.js';
import { feedKeysIn, LIST_BY_KEY, listAt, relativeNodesFor } from '../lib/relativeNodes.js';
import { flattenChannels } from '../lib/channels.js';

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
        // The raw sample item, for the one card kind whose paths aren't item-level:
        // a Matrix's are relative to an element of the list its row names, which
        // only the item itself can answer ({@see ../lib/relativeNodes.js}).
        sampleItem: { type: Object, default: null },
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

        /** Every block type the field declares — one card each, so one node each. */
        blockTypeHandles() {
            return this.cardNodes
                .filter((node) => node.type === 'matrixFields')
                .map((node) => node.blockType)
                .filter(Boolean);
        },

        /**
         * The list this row's source node resolves to in the sample — what every
         * Matrix card reads its relative nodes out of. Null when there's no
         * sample, no node picked, or the node doesn't land on a list, which is
         * also every state in which a card has nothing to suggest.
         */
        blockList() {
            return listAt(this.sampleItem, this.mapping.node);
        },

        /**
         * What the feed calls the things in that list — the candidates every
         * card's key control offers. Computed once here rather than per card,
         * since it is a fact about the list rather than about a block type, and
         * a card whose key isn't among them is precisely the case worth seeing.
         */
        feedKeys() {
            return feedKeysIn(
                this.blockList,
                this.mapping.options?.blockSource || LIST_BY_KEY,
                this.mapping.options || {},
            );
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
         * The relative nodes ONE Matrix card offers, out of the elements of the
         * row's list that belong to its block type.
         */
        relativeNodes(node) {
            return relativeNodesFor(
                this.blockList,
                node.blockType,
                this.mapping.options?.blockSource || LIST_BY_KEY,
                this.mapping.options || {},
                this.blockTypeHandles,
            );
        },

        /**
         * The source-node candidates a card's selects offer. Every card but a
         * Matrix takes the item-level list; a Matrix takes its own relative
         * nodes, because an item-level path on a block sub-field addresses the
         * whole item and resolves to nothing against a list element — offering
         * them is offering wrong answers, which is what this replaces.
         *
         * Merged with the card's own saved paths for the same reason the row's
         * are: a path that fell out of the latest sample still has to render as
         * a legible selected option.
         */
        nodeOptionsFor(node) {
            if (node.type !== 'matrixFields') return this.nodeOptions;

            const saved = Object.values(flattenChannels(this.mapping.blocks?.[node.blockType]))
                .map((row) => row?.node)
                .filter(Boolean);

            return mergeNodeOptions(this.relativeNodes(node), saved);
        },

        /**
         * The discovered nodes a card checks its saved paths against.
         *
         * A Matrix card checks against its own relative nodes rather than the
         * item-level ones — against those, every correctly-relative path reads
         * as missing, which is why this used to withhold them altogether. An
         * unresolvable list is null, this prop's "can't know", so a row is never
         * badged on the strength of a list that isn't there.
         */
        discoveredNodesFor(node) {
            if (node.type !== 'matrixFields') return this.discoveredNodes;

            const relative = this.relativeNodes(node);

            return relative.length ? relative : null;
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
