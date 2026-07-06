<template>
    <div class="influx-tab-pagination">
        <div class="field">
            <div class="heading"><label>{{ $t('Root node') }}</label></div>
            <div class="instructions">
                <p>{{ $t('The main node containing every element that needs to be parsed by the mappings.') }}</p>
            </div>
            <div class="input ltr">
                <searchable-select
                    :model-value="link.rootNode ?? ''"
                    :options="rootNodeOptions"
                    :disabled="readOnly"
                    searchable
                    :placeholder="$t('— response root —')"
                    :search-placeholder="$t('Search nodes…')"
                    :empty-label="$t('Run “Fetch sample” to discover nodes.')"
                    @update:model-value="link.rootNode = $event || null"
                />
            </div>
        </div>

        <hr>
        <h2>{{ $t('Pagination') }}</h2>

        <div class="field">
            <div class="heading"><label>{{ $t('Paginator node') }}</label></div>
            <div class="instructions">
                <p>{{ $t('The node containing the URL of the next page to fetch.') }}</p>
            </div>
            <div class="input ltr">
                <searchable-select
                    :model-value="link.paginatorNode ?? ''"
                    :options="paginatorNodeOptions"
                    :disabled="readOnly"
                    searchable
                    :placeholder="$t('— no paginator —')"
                    :search-placeholder="$t('Search nodes…')"
                    :empty-label="$t('Run “Fetch sample” to discover nodes.')"
                    @update:model-value="link.paginatorNode = $event || null"
                />
            </div>
        </div>

        <div class="field">
            <div class="heading"><label>{{ $t('Total-count node') }}</label></div>
            <div class="instructions">
                <p>{{ $t('The node containing the total number of items.') }}</p>
            </div>
            <div class="input ltr">
                <searchable-select
                    :model-value="link.totalCountNode ?? ''"
                    :options="totalCountNodeOptions"
                    :disabled="readOnly"
                    searchable
                    :placeholder="$t('— none —')"
                    :search-placeholder="$t('Search nodes…')"
                    :empty-label="$t('Run “Fetch sample” to discover nodes.')"
                    @update:model-value="link.totalCountNode = $event || null"
                />
            </div>
        </div>

        <div class="field">
            <div class="heading"><label>{{ $t('Page-count node') }}</label></div>
            <div class="instructions">
                <p>{{ $t('The node containing the total number of pages.') }}</p>
            </div>
            <div class="input ltr">
                <searchable-select
                    :model-value="link.pageCountNode ?? ''"
                    :options="pageCountNodeOptions"
                    :disabled="readOnly"
                    searchable
                    :placeholder="$t('— none —')"
                    :search-placeholder="$t('Search nodes…')"
                    :empty-label="$t('Run “Fetch sample” to discover nodes.')"
                    @update:model-value="link.pageCountNode = $event || null"
                />
            </div>
        </div>

    </div>
</template>

<script>
import SearchableSelect from '../SearchableSelect.vue';
import { store } from '../store.js';
import { mergeNodeOptions } from '../lib/mappings.js';

export default {
    name: 'PaginationTab',

    components: { SearchableSelect },

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

        // Same grouped shape as the mapping rows' source-node select: the
        // "no selection" sentinel as a plain row up top, the sample-
        // discovered candidates inside a "Nodes" chip group. Saved-but-not-
        // discovered values are merged back in so the user doesn't lose
        // their config when the sample doesn't include them (e.g. they
        // swapped the endpoint).
        rootNodeOptions() {
            return this.nodeGroups(
                this.$t('— response root —'),
                this.ui.sample?.rootNodeCandidates ?? [],
                this.link.rootNode,
            );
        },

        paginatorNodeOptions() {
            return this.nodeGroups(
                this.$t('— no paginator —'),
                this.ui.sample?.paginatorNodeCandidates ?? [],
                this.link.paginatorNode,
            );
        },

        // The count nodes live on the response, so they draw from the same
        // response-level scalar leaves the paginator does.
        totalCountNodeOptions() {
            return this.nodeGroups(
                this.$t('— none —'),
                this.ui.sample?.countNodeCandidates ?? [],
                this.link.totalCountNode,
            );
        },

        pageCountNodeOptions() {
            return this.nodeGroups(
                this.$t('— none —'),
                this.ui.sample?.countNodeCandidates ?? [],
                this.link.pageCountNode,
            );
        },
    },

    methods: {
        nodeGroups(sentinelLabel, candidates, saved) {
            const groups = [
                {
                    label: null,
                    kind: null,
                    options: [{ value: '', label: sentinelLabel }],
                },
            ];
            const options = mergeNodeOptions(candidates, saved ? [saved] : []);
            if (options.length) {
                groups.push({ label: this.$t('Nodes'), kind: 'node', options });
            }
            return groups;
        },
    },
};
</script>
