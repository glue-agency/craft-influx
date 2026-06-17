<template>
    <div class="influx-debug-app">
        <div class="content-pane">
            <form class="influx-debug-form" @submit.prevent="reinspect">
                <div class="flex influx-debug-controls">
                    <div class="field">
                        <div class="heading"><label>{{ $t('Site') }}</label></div>
                        <div class="input">
                            <span v-if="!sites.length" class="light">{{ $t('Default endpoint (no site endpoints configured)') }}</span>
                            <div v-else class="select">
                                <select v-model="site">
                                    <option v-for="s in sites" :key="s.handle" :value="s.handle">{{ s.name }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="field">
                        <div class="heading"><label>{{ $t('Sliding window') }}</label></div>
                        <div class="input">
                            <span v-if="!offsetHandles.length" class="light">{{ $t('No presets configured') }}</span>
                            <div v-else class="select">
                                <select v-model="offset">
                                    <option value="">{{ $t('all') }}</option>
                                    <option v-for="h in offsetHandles" :key="h" :value="h">{{ h }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="field">
                        <div class="heading"><label>{{ $t('Limit') }}</label></div>
                        <div class="input">
                            <input v-model.number="limit" type="number" min="1" max="500" class="text influx-debug-limit">
                        </div>
                    </div>

                    <div class="field">
                        <button type="submit" class="btn submit" :disabled="streaming">{{ $t('Inspect') }}</button>
                    </div>
                </div>
            </form>
        </div>

        <h2 class="influx-debug-h2">{{ $t('Feed') }}</h2>
        <div class="content-pane">
            <p v-if="!meta" class="light"><span class="spinner"></span> {{ $t('Loading…') }}</p>
            <table v-else class="data fullwidth fixed-layout">
                <tbody>
                    <tr><th>{{ $t('URL') }}</th><td><code class="influx-break">{{ meta.url || '—' }}</code></td></tr>
                    <tr v-if="meta.error"><th>{{ $t('Error') }}</th><td><pre class="error">{{ meta.error }}</pre></td></tr>
                    <template v-else>
                        <tr>
                            <th>{{ $t('Items on page') }}</th>
                            <td>
                                {{ meta.itemsOnPage }}
                                <span v-if="meta.itemsOnPage > meta.limit" class="light">— {{ $t('showing first {n}', { n: meta.limit }) }}</span>
                            </td>
                        </tr>
                        <tr v-if="meta.paginatorNode">
                            <th>{{ $t('Paginator') }}</th>
                            <td>
                                <code>{{ meta.paginatorNode }}</code>
                                <template v-if="meta.paginatorValue"> <span class="light">→</span> <code class="influx-break">{{ meta.paginatorValue }}</code></template>
                                <span v-else class="light"> — {{ $t('no next page') }}</span>
                            </td>
                        </tr>
                        <tr v-if="meta.matchAttribute">
                            <th>{{ $t('Match') }}</th>
                            <td>
                                <code>{{ meta.matchAttribute }}</code>
                                <template v-if="meta.matchNode"> <span class="light">↤</span> <code>{{ meta.matchNode }}</code></template>
                            </td>
                        </tr>
                        <tr v-if="meta.offsetLabel">
                            <th>{{ $t('Sliding window') }}</th>
                            <td><code>{{ meta.offset }}</code> <span class="light">→</span> <code>{{ meta.offsetLabel }}</code></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <h2 class="influx-debug-h2">
            {{ $t('Items') }}
            <span class="light">{{ counterLabel }}</span>
        </h2>

        <debug-item v-for="(row, i) in items" :key="i" :row="row" />

        <div class="light influx-debug-status">{{ statusLabel }}</div>
    </div>
</template>

<script>
import DebugItem from '../components/DebugItem.vue';

/**
 * The debug inspector page. Owns the site/offset/limit form, opens the SSE
 * stream and renders the meta panel + a live-growing list of DebugItem cards.
 * Re-inspecting reopens the stream in place (no full page reload).
 */
export default {
    name: 'DebugApp',

    components: { DebugItem },

    props: {
        config: { type: Object, required: true },
    },

    data() {
        return {
            site: this.config.selectedSite || '',
            offset: this.config.selectedOffset || '',
            limit: this.config.limit || 10,
            sites: this.config.sites || [],
            offsetHandles: this.config.offsetHandles || [],
            meta: null,
            items: [],
            streaming: false,
            streamError: false,
            es: null,
        };
    },

    computed: {
        counterLabel() {
            return this.items.length ? this.$t('{n} items', { n: this.items.length }) : '';
        },

        statusLabel() {
            if (this.streamError) return this.$t('Stream closed.');
            if (this.streaming) return this.$t('Streaming…');
            return this.meta ? this.$t('Done.') : '';
        },
    },

    mounted() {
        this.openStream();
    },

    beforeUnmount() {
        this.closeStream();
    },

    methods: {
        reinspect() {
            this.openStream();
        },

        openStream() {
            this.closeStream();
            this.meta = null;
            this.items = [];
            this.streamError = false;
            this.streaming = true;

            const base = this.config.streamUrl;
            const params = new URLSearchParams();
            if (this.site) params.set('site', this.site);
            if (this.offset) params.set('offset', this.offset);
            params.set('limit', String(this.limit || 25));
            const url = base + (base.includes('?') ? '&' : '?') + params.toString();

            const es = new EventSource(url, { withCredentials: true });
            this.es = es;

            es.addEventListener('meta', (e) => {
                try { this.meta = JSON.parse(e.data); } catch (err) { /* keep loading */ }
            });
            es.addEventListener('item', (e) => {
                try { this.items.push(JSON.parse(e.data)); } catch (err) { /* skip bad frame */ }
            });
            es.addEventListener('done', () => {
                this.streaming = false;
                this.closeStream();
            });
            es.addEventListener('error', () => {
                this.streaming = false;
                this.streamError = true;
                this.closeStream();
            });
        },

        closeStream() {
            if (this.es) {
                this.es.close();
                this.es = null;
            }
        },
    },
};
</script>

<style scoped>
.influx-debug-h2 { margin-top: 1.5em; }
.influx-debug-controls { gap: 1em; align-items: flex-end; flex-wrap: wrap; }
.influx-debug-limit { width: 80px; }
.influx-debug-status { margin-top: 1em; }
.influx-break { word-break: break-all; }
</style>
