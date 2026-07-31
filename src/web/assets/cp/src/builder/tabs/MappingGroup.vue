<template>
    <v-mapping-group-card :label="group.label" :data-group="group.label">
        <template v-slot:tags>
            <span v-if="missingCount > 0"
                  class="pill pill-missing"
                  :data-missing="missingCount"
                  :title="$t('Fields whose saved source node is no longer in the fetched sample')">
                <span class="num" v-text="missingCount"></span>&nbsp;{{ $t('missing') }}
            </span>

            <span class="pill pill-mapped"
                  :data-mapped="mappedCount"
                  :title="$t('Fields with an active source node')">
                <span class="num" v-text="mappedCount"></span>&nbsp;{{ $t('mapped') }}
            </span>

            <span class="pill pill-count" :title="$t('Total fields in this group')" v-text="group.fields.length"></span>

            <!-- Both .stop modifiers are load-bearing: the card header is its
                 own toggle, on click AND on keydown.enter/.space — and those
                 key handlers carry .prevent, which would swallow the button's
                 native Enter → click before it ever fires. -->
            <button v-if="hasMappingData && ! readOnly"
                    type="button"
                    class="btn small clear-group"
                    :title="$t('Clear every mapping in this group')"
                    v-text="$t('Clear')"
                    @click.stop="clearGroup"
                    @keydown.stop></button>
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
import { store } from '../store.js';
import { clearMappings, discoveredNodes } from '../lib/mappings.js';

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
            return this.group.fields.reduce((count, f) => {
                const mapping = this.link.mappings?.[f.handle];

                // subfieldsOnly fields (Matrix) never carry a node of their
                // own — they count as mapped when any sub-mapping content
                // was saved on the row.
                if (f.fieldMeta?.subfieldsOnly) {
                    return count + (Object.keys(mapping || {}).length ? 1 : 0);
                }

                return count + (mapping?.node ? 1 : 0);
            }, 0);
        },

        // Saved source nodes that are no longer present in the latest
        // fetched sample. Only meaningful once a sample with an item in it
        // has been run.
        missingCount() {
            const discovered = discoveredNodes(this.ui.sample);
            if (! discovered) return 0;
            const available = new Set(discovered.map(o => o.value));
            return this.group.fields.reduce((count, f) => {
                const saved = this.link.mappings?.[f.handle]?.node;
                if (! saved) return count;
                return count + (available.has(saved) ? 0 : 1);
            }, 0);
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

    components: { 'v-mapping-row': MappingRow, 'v-mapping-group-card': MappingGroupCard },
};
</script>
