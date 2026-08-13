<template>
    <!-- One card exists per block type and ALL of them render at once (Feed
         Me-style) — each card reads and writes only its own type's slice. A
         block type without mappable sub-fields still gets its card so the full
         type list stays visible — the empty hint says why there are no rows
         to map. -->
    <v-sub-field-rows
        :node="node"
        :rows="typeRows"
        :node-options="nodeOptions"
        :discovered-nodes="discoveredNodes"
        :read-only="readOnly || lockedOut"
        :notice="lockedOut ? lockedOutHint : null"
        :empty-hint="$t('This block type has no mappable sub-fields.')"
        @update:rows="mergeTypeRows"
    >
        <!-- This block type's own settings, above its rows: they configure the
             rows beneath them, and they belong to the type rather than to the
             field, which is why they render here instead of stacking one box per
             block type above every card. -->
        <template v-if="visibleSettings.length" v-slot:settings>
            <!-- On the card's tracks for its ALIGNMENT — label at the Field
                 column's edge, control under Source node — and set apart from the
                 rows by everything else: a tinted strip closed by a rule, with
                 the small-caps label the extras card gives its own settings. What
                 made this read as a mapping was never the alignment, it was
                 having nothing say otherwise. -->
            <div class="block-settings">
                <div
                    v-for="setting in visibleSettings"
                    :key="setting.handle"
                    class="block-setting"
                    :data-missing="keyState(setting) ? 'true' : 'false'"
                >
                    <label>
                        {{ setting.label }}
                        <!-- A key that claims nothing — never declared, or
                             declared and matching nothing in the sample. Said
                             where the key is, rather than found out by pressing
                             Save (the first case) or by a run that quietly
                             produces no blocks (the second). -->
                        <v-influx-tooltip
                            v-if="keyState(setting)"
                            :text="keyHint(setting)"
                            trigger-class="influx-missing-badge"
                        >{{ $t('missing key') }}</v-influx-tooltip>
                    </label>
                    <div>
                        <!-- The keys the sample's own list offers, when it offers
                             any: what the feed calls the things in it IS the thing
                             this asks for, so it's picked rather than typed and
                             hoped for. Custom values stay allowed — a sample is
                             one page, and a type absent from it is not a type
                             absent from the feed. -->
                        <v-searchable-select
                            v-if="feedKeys.length"
                            :model-value="settingValue(setting) ?? ''"
                            :options="feedKeys"
                            searchable
                            allow-custom
                            :placeholder="setting.placeholder"
                            :search-placeholder="$t('Search keys…')"
                            :disabled="readOnly"
                            @update:model-value="$emit('update:option', { node: setting, value: $event })"
                        />
                        <component
                            v-else
                            :is="controlFor(setting)"
                            :node="setting"
                            :model-value="settingValue(setting)"
                            empty-is-value
                            :read-only="readOnly"
                            @update:model-value="$emit('update:option', { node: setting, value: $event })"
                        />
                        <!-- Server-authored schema strings, never operator input.
                             The label alone can't carry this: "Key" beside a block
                             type's name is ambiguous on a screen full of keys. -->
                        <p v-if="setting.instructions" class="light hint" v-html="setting.instructions" />
                    </div>
                </div>
            </div>
        </template>
    </v-sub-field-rows>

</template>

<script>
import SubFieldRows from './SubFieldRows.vue';
import SearchableSelect from '../../../components/SearchableSelect.vue';
import InfluxTooltip from '../../../components/InfluxTooltip.vue';
import { flattenChannels, splitChannels } from '../../lib/channels.js';
import { isVisible } from '../../lib/conditions.js';
import { readNode } from '../../lib/slots.js';
import { LIST_BY_KEY, LIST_SINGLE, sourceKeyOption } from '../../lib/relativeNodes.js';
import { controlFor } from '../registry.js';

/**
 * Schema matrixFields node: source-node + default rows for ONE Matrix block
 * type's mappable sub-fields — its custom fields, plus the block's native
 * Title where the type has one — Feed Me-style: every block type's card
 * renders at once, each independently mappable. The shared SubFieldRows
 * table owns the card chrome and the row rewrites (see its docblock for the
 * preserving rows contract: a child row's unknown keys — `options`, nested
 * `fields`, … — round-trip untouched, and a row drops only when nothing is
 * left).
 *
 * Contract: the `blocks` channel this card binds is the mapping's WHOLE `blocks`
 * object (`{<typeHandle>: {fields: {...}, nativeFields: {...}}}`). The card renders
 * its own `node.blockType` slice as ONE row table and emits full `blocks`
 * replacements that leave every other type's slice — and any unknown keys on its
 * own type's entry — untouched. Taking the whole channel keeps the merge and its
 * pruning next to the rewrite instead of splitting them across the renderer.
 *
 * Channels: a sub-field node carries an optional `channel` key saying which
 * half of its type's entry the row is stored in — `nativeFields` for the
 * block's native Title, ABSENT for a custom field, which means `fields` (the
 * stored shape that predates the key). The two channels are one table to the
 * editor and are split apart again on every write; the arithmetic is shared
 * with the other two-channel card in {@see ../../lib/channels.js}, which this
 * calls with `fields` as the default. A handle can't collide across them in
 * practice (`title` is a reserved Craft field handle), but if one ever did
 * `nativeFields` would win deterministically — on the render and the write
 * alike.
 *
 * Matrix-specific rules:
 *   - node paths are RELATIVE to one element of the list the Matrix row names
 *     (`image`), never absolute against the whole feed item — so both the paths
 *     this card offers and the keys its settings offer are read out of that list
 *     rather than out of the item-level discovery every other card uses
 *     ({@see ../../lib/relativeNodes.js});
 *   - a single-type list locks every card but the one already mapped
 *     ({@see lockedOut}), which is why this card reads the row's options;
 *   - emptied slices collapse away: a channel map with no rows drops off its
 *     type entry, and an entry left with nothing drops the type out of
 *     `blocks` (an all-empty `blocks` then prunes off the mapping in
 *     MappingRow.writeMapping()).
 */
export default {
    name: 'MatrixFields',

    inheritAttrs: false,

    // Two narrow emits rather than one wide one: the card binds a channel, and
    // its settings bind one key of the ROW's options apiece. Emitting a whole
    // mapping instead would make the card the only container that writes outside
    // the channel it was handed.
    emits: ['update:channels', 'update:option'],

    props: {
        node: { type: Object, required: true },
        // The stored channels this card binds — `blocks` alone, per its registry
        // entry (lib/slots.js). See the contract above.
        channels: { type: Object, default: () => ({}) },
        nodeOptions: { type: Array, default: () => [] },
        // The sample's discovered flatNodes — the "is the node still live"
        // signal. Null when no sample has been fetched. See SubFieldRows.
        discoveredNodes: { type: Array, default: null },
        // The Matrix row's own options — read for `blockSource`, which decides
        // whether more than one block type may be mapped at all.
        mappingOptions: { type: Object, default: () => ({}) },
        // What the feed calls the things in the row's list, as the key control's
        // candidates. Empty with no sample, or under a source that claims
        // nothing by name — the control falls back to a plain box there.
        feedKeys: { type: Array, default: () => [] },
        readOnly: { type: Boolean, default: false },
    },

    computed: {
        blocks() {
            return this.channels.blocks || {};
        },

        /** The handles of the OTHER block types that already carry rows. */
        otherMappedTypes() {
            return Object.keys(this.blocks).filter((type) => (
                type !== this.node.blockType && Object.keys(flattenChannels(this.blocks[type])).length > 0
            ));
        },

        /**
         * Whether this card is closed for business: a single-type list has one
         * block type by definition, so once another card carries rows an EMPTY
         * one can't start a second. Locked rather than hidden — switching
         * sources shouldn't make an operator's existing work disappear, and
         * clearing the other card's rows re-opens the choice.
         *
         * A card carrying rows of its own is never locked, which is the part
         * that matters: switching an already-two-type mapping to a single-type
         * list would otherwise lock BOTH cards, and "clear nodes" is itself
         * gated on the card being editable — no way out but changing the
         * setting back. Leaving populated cards open means the conflict is
         * always resolvable where it is. The strategy still throws on two mapped
         * types at sync time, which is the backstop for config written outside
         * the builder.
         */
        lockedOut() {
            return this.blockSource === LIST_SINGLE
                && Object.keys(this.typeRows).length === 0
                && this.otherMappedTypes.length > 0;
        },

        /**
         * The row's block source, with the fallback PHP applies to an unset
         * option ({@see \GlueAgency\Influx\enums\MatrixBlockSource::fallback()}).
         * Both readers go through here, so the lockout and the gated settings
         * can't disagree about what an untouched row means.
         */
        blockSource() {
            return this.mappingOptions.blockSource || LIST_BY_KEY;
        },

        lockedOutHint() {
            return this.$t('A single-type list maps one block type, and “{type}” is already mapped.', {
                type: this.otherMappedTypes[0],
            });
        },

        /**
         * The card's own settings whose showIf passes — resolved against the
         * ROW's options, since that is where both the settings and the
         * `blockSource` they gate on are stored. The alias only means something
         * to a source that matches a key.
         */
        visibleSettings() {
            return (this.node.settings || []).filter((setting) => isVisible(setting, this.resolvedOption));
        },

        /**
         * Whether this type maps anything at all — a row with a node or an
         * explicit "use default", which is `FieldMapping::isActive()`. An
         * untouched card asks for no key.
         */
        hasActiveRows() {
            return Object.values(this.typeRows).some((row) => row?.node || row?.useDefault);
        },

        /** This card's own type entry — both channels, or nothing saved yet. */
        typeEntry() {
            return this.blocks[this.node.blockType] || {};
        },

        /**
         * The rows the table renders: both of this type's channels flattened
         * into one map, since a row is addressed by handle either way (the
         * row ORDER comes from node.subFields, not from this map).
         */
        typeRows() {
            return flattenChannels(this.typeEntry);
        },
    },

    methods: {
        controlFor,

        /**
         * A gated setting resolves against the row's stored options, falling back
         * to the declared default — the same rule MappingExtras applies to its
         * own leaves, so a card and the controls above it agree on what an
         * untouched option means.
         *
         * `blockSource` is the exception because it is the ROW's leaf, not one of
         * the card's: its default isn't reachable from here, so it comes from the
         * shared fallback instead ({@see blockSource}).
         */
        resolvedOption(handle) {
            if (handle === 'blockSource') return this.blockSource;

            return this.mappingOptions[handle] ?? this.defaultFor(handle);
        },

        /**
         * Why this type's key doesn't reach the feed, or null when it does.
         *
         * Two ways to fail, and the difference is who can tell:
         *
         *   - `unset` — nothing is declared to claim the type. The save itself
         *     refuses this ({@see \GlueAgency\Influx\fields\Matrix::validateMapping()}),
         *     off the same three conditions asked here; all this does is ask
         *     them while the operator can still act.
         *   - `unmatched` — a key IS declared and nothing in the fetched sample
         *     carries it. The key equivalent of a source node that fell out of
         *     the sample, and warned about the same way rather than blocked:
         *     one page is not the feed, and a typo and a type that simply isn't
         *     on this page look identical from here. Judged only when there IS
         *     a sample, which is the same "can't know, flag nothing" the node
         *     rule applies ({@see ../../lib/mappings.js}).
         */
        keyState(setting) {
            if (setting.handle !== sourceKeyOption(this.node.blockType)) return null;

            if (this.blockSource === LIST_SINGLE || ! this.hasActiveRows) return null;

            const key = String(this.mappingOptions[setting.handle] ?? '').trim();

            if (key === '') return 'unset';

            if (! this.feedKeys.length) return null;

            return this.feedKeys.some((option) => String(option.value) === key) ? null : 'unmatched';
        },

        /** The sentence for each way a key can fail to reach the feed. */
        keyHint(setting) {
            if (this.keyState(setting) === 'unset') {
                return this.$t('Nothing in the feed names this block type without a key, so its blocks are skipped.');
            }

            return this.$t('No item in the fetched sample carries this key, so this block type would get no blocks.');
        },

        /** The declared default of one of this card's own settings. */
        defaultFor(handle) {
            return (this.node.settings || []).find((setting) => setting.handle === handle)?.default;
        },

        /** A setting's value: what the row's options hold, else its own default. */
        settingValue(setting) {
            return readNode({ options: this.mappingOptions }, 'extra', setting);
        },

        /**
         * Merge the rewritten rows back into the whole `blocks` object: the
         * rows are partitioned by channel onto this type's entry, other
         * types' slices pass through untouched, unknown keys on this type's
         * entry survive, an emptied channel collapses off the entry, and an
         * entry left with nothing collapses the type out of `blocks`.
         */
        mergeTypeRows(nextRows) {
            const type = this.node.blockType;
            const entry = { ...this.typeEntry };
            // 'fields' is also pinned as MappingSlots::CHANNEL_DEFAULT['matrixFields'],
            // which the save-time prune uses to pick the surviving copy when a
            // handle ends up in both channels. Change one, change both.
            const channels = splitChannels(nextRows, this.node.subFields, this.typeEntry, 'fields');

            Object.entries(channels).forEach(([channel, rows]) => {
                if (Object.keys(rows).length === 0) {
                    delete entry[channel];
                } else {
                    entry[channel] = rows;
                }
            });

            const next = { ...this.blocks };
            if (Object.keys(entry).length === 0) {
                delete next[type];
            } else {
                next[type] = entry;
            }

            this.$emit('update:channels', { blocks: next });
        },
    },

    components: {
        'v-sub-field-rows': SubFieldRows,
        'v-searchable-select': SearchableSelect,
        'v-influx-tooltip': InfluxTooltip,
    },
};
</script>
