<template>
    <div class="influx-mapping-group"
         :class="{ collapsed: !expanded }"
         :data-group="group.label">
        <div class="influx-mapping-group-header"
             role="button"
             tabindex="0"
             :aria-expanded="expanded ? 'true' : 'false'"
             @click="expanded = !expanded"
             @keydown.enter.prevent="expanded = !expanded"
             @keydown.space.prevent="expanded = !expanded">
            <span class="chevron" aria-hidden="true">▼</span>
            <span class="label">{{ group.label }}</span>

            <span class="pill pill-mapped"
                  :data-mapped="mappedCount"
                  :title="$t('Fields with an active source node')">
                <span class="num">{{ mappedCount }}</span>&nbsp;{{ $t('mapped') }}
            </span>

            <span v-if="missingCount > 0"
                  class="pill pill-missing"
                  :data-missing="missingCount"
                  :title="$t('Fields whose saved source node is no longer in the fetched sample')">
                <span class="num">{{ missingCount }}</span>&nbsp;{{ $t('missing') }}
            </span>

            <span class="pill pill-count" :title="$t('Total fields in this group')">{{ group.fields.length }}</span>
        </div>

        <div class="influx-mapping-group-body">
            <div class="influx-mapping-headings">
                <div>{{ $t('Field') }}</div>
                <div>{{ $t('Source node') }}</div>
                <div>{{ $t('Default value') }}</div>
            </div>

            <mapping-row
                v-for="field in group.fields"
                :key="field.handle"
                :field="field"
                :node-options="nodeOptions"
            />
        </div>
    </div>
</template>

<script>
import MappingRow from './MappingRow.vue';
import { store } from '../store.js';

export default {
    name: 'MappingGroup',

    components: { MappingRow },

    props: {
        group: { type: Object, required: true },
        nodeOptions: { type: Array, default: () => [] },
    },

    data() {
        return {
            expanded: true,
            ui: store.ui,
        };
    },

    computed: {
        // Through the stable getter — load()/save() replace the underlying
        // object, so a data() capture would go stale.
        link() { return store.link; },

        mappedCount() {
            return this.group.fields.reduce((count, f) => {
                return count + (this.link.mappings?.[f.handle]?.node ? 1 : 0);
            }, 0);
        },

        // Saved source nodes that are no longer present in the latest
        // fetched sample. Only meaningful once a sample has been run.
        missingCount() {
            const discovered = this.ui.sample?.flatNodes;
            if (!discovered) return 0;
            const available = new Set(discovered.map(o => o.value));
            return this.group.fields.reduce((count, f) => {
                const saved = this.link.mappings?.[f.handle]?.node;
                if (!saved) return count;
                return count + (available.has(saved) ? 0 : 1);
            }, 0);
        },
    },
};
</script>
