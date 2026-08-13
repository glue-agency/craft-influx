<template>
    <v-mapping-group-card :label="group.label" :data-group="group.label">
        <template v-slot:tags>
            <!-- Both .stop modifiers are load-bearing: the card header is its
                 own toggle, on click AND on keydown.enter/.space — and those
                 key handlers carry .prevent, which would swallow the button's
                 native Enter → click before it ever fires. -->
            <button v-if="hasMappingData && ! readOnly"
                    type="button"
                    class="influx-clear-link clear-group"
                    :title="$t('Clear every mapping in this group')"
                    v-text="$t('clear nodes')"
                    @click.stop="clearGroup"
                    @keydown.stop></button>

            <!-- Each count's sentence is the content, not a name for an icon,
                 so it goes through Craft's own tooltip. The total is the
                 exception: the number IS the fact. -->
            <v-craft-tooltip v-if="autoCount > 0"
                             :text="$t('Fields filled in by Auto-match')"
                             trigger-class="pill pill-auto"
                             :data-auto="autoCount">
                <span class="num" v-text="autoCount"></span>&nbsp;{{ $t('auto') }}
            </v-craft-tooltip>

            <v-craft-tooltip v-if="missingCount > 0"
                             :text="$t('Fields whose source node isn’t in the fetched sample')"
                             trigger-class="pill pill-missing"
                             :data-missing="missingCount">
                <span class="num" v-text="missingCount"></span>&nbsp;{{ $t('missing') }}
            </v-craft-tooltip>

            <!-- Nothing mapped and nothing missing is the state the total
                 already tells you; a gray "0 mapped" beside it only takes room
                 to say the same thing again. -->
            <v-craft-tooltip v-if="mappedCount > 0 || missingCount > 0"
                             :text="$t('Fields with an active source node')"
                             trigger-class="pill pill-mapped"
                             :data-mapped="mappedCount">
                <span class="num" v-text="mappedCount"></span>&nbsp;{{ $t('mapped') }}
            </v-craft-tooltip>

            <v-craft-tooltip :text="$t('Total fields in this group')" trigger-class="pill pill-count">
                {{ group.fields.length }}
            </v-craft-tooltip>
        </template>

        <div class="influx-mapping-headings">
            <div v-text="$t('Field')"></div>
            <div v-text="$t('Source node')"></div>
            <div v-text="$t('Default value')"></div>
        </div>

        <v-mapping-row
            v-for="field in group.fields"
            :key="field.handle"
            :field="field"
            :node-options="nodeOptions"
        />
    </v-mapping-group-card>
</template>

<script>
import MappingRow from './MappingRow.vue';
import MappingGroupCard from '../../components/MappingGroupCard.vue';
import InfluxTooltip from '../../components/InfluxTooltip.vue';
import { store } from '../store.js';
import { clearMappings, discoveredNodes, isMapped, isMissingNode } from '../lib/mappings.js';

/**
 * One top-level group of the Mapping tab: the shared card chrome with the
 * missing / mapped / total pills, the column headings, and a MappingRow per
 * field in the group.
 *
 * The header's "Clear" wipes every one of the group's field mappings in a
 * single store write. That is only safe because MappingRow derives its extras
 * models from the store instead of caching them — see the row's docblock.
 */
export default {
    name: 'MappingGroup',

    props: {
        group: { type: Object, required: true },
        nodeOptions: { type: Array, default: () => [] },
    },

    data() {
        return {
            ui: store.ui,
        };
    },

    computed: {
        // Through the stable getter — load()/save() replace the underlying
        // object, so a data() capture would go stale.
        link() { return store.link; },

        readOnly() { return !!this.ui.meta?.readOnly; },

        // Anything at all saved under any of the group's handles — any slot,
        // any channel. Gates the header's Clear, and deliberately wider than
        // mappedCount: a group whose only content is defaults or sub-field
        // rows carries nothing "mapped" and still has something to clear.
        hasMappingData() {
            const mappings = this.link.mappings || {};

            return this.group.fields.some((f) => Object.keys(mappings[f.handle] || {}).length > 0);
        },

        mappedCount() {
            return this.group.fields
                .filter((f) => isMapped(f, this.link.mappings?.[f.handle]))
                .length;
        },

        // How many of the group's rows Auto-match filled in and the user
        // hasn't touched since — the same transient store state the rows'
        // own badges read.
        autoCount() {
            const mappings = this.link.mappings || {};

            return this.group.fields.reduce((count, f) => {
                const isAuto = this.ui.autoMatched.includes(f.handle) && !!mappings[f.handle]?.node;

                return count + (isAuto ? 1 : 0);
            }, 0);
        },

        // Saved source nodes no longer in the latest sample — the same rule the
        // rows badge on, so this pill always counts rows the operator can find.
        missingCount() {
            const discovered = discoveredNodes(this.ui.sample);

            return this.group.fields.reduce(
                (count, field) => count + (isMissingNode(field, this.link.mappings?.[field.handle], discovered) ? 1 : 0),
                0,
            );
        },
    },

    methods: {
        /**
         * Wipe every field mapping in the group — one store write, so the
         * rows below all redraw off the same change. Fields outside the group
         * (and handles the group carries nothing for) are left alone.
         */
        clearGroup() {
            this.link.mappings = clearMappings(this.link.mappings, this.group.fields.map((f) => f.handle));
        },
    },

    components: {
        'v-craft-tooltip': InfluxTooltip, 'v-mapping-row': MappingRow, 'v-mapping-group-card': MappingGroupCard },
};
</script>
