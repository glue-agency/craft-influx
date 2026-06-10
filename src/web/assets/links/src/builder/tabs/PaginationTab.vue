<template>
    <div class="influx-tab-pagination">
        <div v-if="ui.sampleError" class="influx-sample-error">
            <strong>{{ $t('Sample failed:') }}</strong> {{ ui.sampleError }}
        </div>
        <p v-else-if="ui.sample" class="light">
            {{ $t('Last fetched from') }} <code>{{ ui.sample.url }}</code>.
        </p>

        <div class="field">
            <div class="heading"><label for="builder-rootNode">{{ $t('Root node') }}</label></div>
            <div class="instructions">
                <p>{{ $t('The main node containing every element that needs to be parsed by the mappings.') }}</p>
            </div>
            <div class="input ltr">
                <div class="select">
                    <select id="builder-rootNode" v-model="link.rootNode">
                        <option :value="null">{{ $t('— response root —') }}</option>
                        <option v-for="opt in rootOptions" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="field">
            <div class="heading"><label for="builder-paginatorNode">{{ $t('Paginator node') }}</label></div>
            <div class="instructions">
                <p>{{ $t('The node containing the URL of the next page to fetch.') }}</p>
            </div>
            <div class="input ltr">
                <div class="select">
                    <select id="builder-paginatorNode" v-model="link.paginatorNode">
                        <option :value="null">{{ $t('— no paginator —') }}</option>
                        <option v-for="opt in paginatorOptions" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                </div>
            </div>
        </div>

    </div>
</template>

<script>
import { store } from '../store.js';

export default {
    name: 'PaginationTab',

    data() {
        return {
            ui: store.ui,
        };
    },

    computed: {
        // Through the stable getter — load()/save() replace the underlying
        // object, so a data() capture would go stale.
        link() { return store.link; },

        canSample() {
            const ep = this.link.endpoint;
            return typeof ep === 'string' && ep.trim() !== '';
        },

        // Root + paginator dropdowns: merge any saved-but-not-discovered
        // value back in so the user doesn't lose their config when the
        // sample doesn't include it (e.g. they swapped the endpoint).
        rootOptions() {
            const discovered = this.ui.sample?.rootNodeCandidates ?? [];
            const saved = this.link.rootNode;
            if (saved && !discovered.includes(saved)) {
                return [saved, ...discovered];
            }
            return discovered;
        },

        paginatorOptions() {
            const discovered = this.ui.sample?.paginatorNodeCandidates ?? [];
            const saved = this.link.paginatorNode;
            if (saved && !discovered.includes(saved)) {
                return [saved, ...discovered];
            }
            return discovered;
        },
    },

    methods: {
        onFetch() {
            store.fetchSample();
        },
    },
};
</script>
