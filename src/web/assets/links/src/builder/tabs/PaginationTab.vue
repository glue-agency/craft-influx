<template>
    <div class="influx-tab-pagination">
        <p class="light" v-html="$t('Use the <strong>Fetch sample</strong> action in the page header to call your configured endpoint and populate the dropdowns below from the discovered JSON nodes.')"></p>

        <div v-if="ui.sampleError" class="influx-sample-error">
            <strong>{{ $t('Sample failed:') }}</strong> {{ ui.sampleError }}
        </div>
        <p v-else-if="ui.sample" class="light">
            {{ $t('Last fetched from') }} <code>{{ ui.sample.url }}</code>.
        </p>

        <div class="field">
            <div class="heading"><label for="builder-rootNode">{{ $t('Root node') }}</label></div>
            <div class="instructions">
                <p>{{ $t('Dot-path to the iterable list inside the response. Leave blank if the response itself is a JSON array.') }}</p>
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
                <p>{{ $t('Dot-path to the next-page URL for cursor pagination. Leave blank if the response is single-page.') }}</p>
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

        <div v-if="ui.sample?.sampleItem" class="influx-sample-preview">
            <h3>{{ $t('Sample item') }}</h3>
            <p class="light">{{ $t('First item under') }} <code>{{ ui.sample.rootNode ?? $t('— response root —') }}</code>.</p>
            <pre>{{ samplePreviewJson }}</pre>
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

        samplePreviewJson() {
            const item = this.ui.sample?.sampleItem;
            return item ? JSON.stringify(item, null, 2) : '';
        },
    },

    methods: {
        onFetch() {
            store.fetchSample();
        },
    },
};
</script>
