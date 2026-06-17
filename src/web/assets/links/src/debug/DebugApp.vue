<template>
    <div class="influx-debug-app">
        <!-- Inspector toolbar — the three controls read as one instrument
             panel (labelled segments split by hairline rules) with the
             primary action pinned to the right, rather than three stacked
             Craft fields. -->
        <form class="influx-inspector" @submit.prevent="reinspect">
            <div class="influx-inspector-segment">
                <span class="influx-inspector-label">{{ $t('Site') }}</span>
                <span v-if="!sites.length" class="influx-inspector-static">{{ $t('Default endpoint') }}</span>
                <div v-else class="select">
                    <select v-model="site">
                        <option v-for="s in sites" :key="s.handle" :value="s.handle">{{ s.name }}</option>
                    </select>
                </div>
            </div>

            <span class="influx-inspector-rule" aria-hidden="true"></span>

            <div class="influx-inspector-segment">
                <span class="influx-inspector-label">{{ $t('Sliding window') }}</span>
                <span v-if="!offsetHandles.length" class="influx-inspector-static">{{ $t('No presets') }}</span>
                <div v-else class="select">
                    <select v-model="offset">
                        <option value="">{{ $t('all') }}</option>
                        <option v-for="h in offsetHandles" :key="h" :value="h">{{ h }}</option>
                    </select>
                </div>
            </div>

            <span class="influx-inspector-rule" aria-hidden="true"></span>

            <div class="influx-inspector-segment">
                <span class="influx-inspector-label">{{ $t('Limit') }}</span>
                <input v-model.number="limit" type="number" min="1" max="500" class="text influx-inspector-limit">
            </div>

            <div class="influx-inspector-actions">
                <button type="submit" class="btn submit" data-icon="search" :disabled="streaming">
                    {{ streaming ? $t('Inspecting…') : $t('Inspect') }}
                </button>
            </div>
        </form>

        <!-- Feed report — a response-style header (live status dot + endpoint)
             over a tiled grid of the run's resolved facts, in place of the old
             key/value table. -->
        <h2 class="influx-debug-h2">{{ $t('Feed') }}</h2>
        <div class="influx-feed" :class="feedState">
            <div class="influx-feed-bar">
                <span class="influx-feed-dot" aria-hidden="true"></span>
                <div class="influx-feed-endpoint">
                    <span class="influx-feed-eyebrow">{{ $t('Endpoint') }}</span>
                    <code class="influx-feed-url">{{ (meta && meta.url) || '—' }}</code>
                </div>
                <span class="influx-feed-status">{{ feedStatusLabel }}</span>
            </div>

            <p v-if="!meta" class="influx-feed-loading"><span class="spinner"></span> {{ $t('Fetching feed…') }}</p>

            <div v-else-if="meta.error" class="influx-feed-error">
                <pre>{{ meta.error }}</pre>
            </div>

            <div v-else class="influx-feed-stats">
                <div class="influx-feed-stat">
                    <span class="influx-feed-stat-k">{{ $t('Items on page') }}</span>
                    <span class="influx-feed-stat-v">
                        {{ meta.itemsOnPage }}
                        <em v-if="meta.itemsOnPage > meta.limit">{{ $t('showing first {n}', { n: meta.limit }) }}</em>
                    </span>
                </div>

                <div v-if="meta.paginatorNode" class="influx-feed-stat">
                    <span class="influx-feed-stat-k">{{ $t('Paginator') }}</span>
                    <span class="influx-feed-stat-v">
                        <code>{{ meta.paginatorNode }}</code>
                        <template v-if="meta.paginatorValue"><span class="influx-feed-arrow">→</span> <code class="influx-break">{{ meta.paginatorValue }}</code></template>
                        <em v-else>{{ $t('no next page') }}</em>
                    </span>
                </div>

                <div v-if="meta.matchAttribute" class="influx-feed-stat">
                    <span class="influx-feed-stat-k">{{ $t('Match') }}</span>
                    <span class="influx-feed-stat-v">
                        <code>{{ meta.matchAttribute }}</code>
                        <template v-if="meta.matchNode"><span class="influx-feed-arrow">↤</span> <code>{{ meta.matchNode }}</code></template>
                    </span>
                </div>

                <div v-if="meta.offsetLabel" class="influx-feed-stat">
                    <span class="influx-feed-stat-k">{{ $t('Sliding window') }}</span>
                    <span class="influx-feed-stat-v"><code>{{ meta.offset }}</code> <span class="influx-feed-arrow">→</span> <code>{{ meta.offsetLabel }}</code></span>
                </div>
            </div>
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

        // Feed fetch outcome — drives the report card's status dot/label
        // colour. Loading until the meta frame lands; ok unless the feed (or
        // the stream) reported an error.
        feedState() {
            if (this.streamError || this.meta?.error) return 'is-error';
            if (!this.meta) return 'is-loading';
            return 'is-ok';
        },

        feedStatusLabel() {
            if (this.streamError) return this.$t('Connection lost');
            if (this.meta?.error) return this.$t('Fetch failed');
            if (!this.meta) return this.$t('Connecting…');
            return this.$t('Connected');
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
.influx-debug-h2 { margin-top: 28px; }
.influx-debug-h2 .light { font-weight: normal; }
.influx-debug-status { margin-top: 14px; }
.influx-break { word-break: break-all; }

/* ---- Inspector toolbar -------------------------------------------------- */
.influx-inspector {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 14px 18px;
    padding: 14px 18px;
    background: var(--gray-050);
    border: 1px solid var(--hairline-color);
    border-radius: var(--large-border-radius);
}

.influx-inspector-segment {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.influx-inspector-label,
.influx-feed-eyebrow,
.influx-feed-stat-k {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--medium-text-color);
}

.influx-inspector-static {
    padding: 6px 0;
    font-size: 13px;
    color: var(--light-text-color);
}

.influx-inspector-limit { width: 76px; }

/* Hairline separators between segments. They stretch to control height and
   simply wrap out of view on narrow layouts. */
.influx-inspector-rule {
    align-self: stretch;
    width: 1px;
    margin: 2px 0;
    background: var(--hairline-color);
}

.influx-inspector-actions {
    margin-left: auto;
    align-self: flex-end;
}

/* ---- Feed report card --------------------------------------------------- */
.influx-feed {
    overflow: hidden;
    background: var(--white);
    border: 1px solid var(--hairline-color);
    border-radius: var(--large-border-radius);
}

.influx-feed-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 18px;
    background: var(--gray-050);
    border-bottom: 1px solid var(--hairline-color);
}

.influx-feed-dot {
    flex: none;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--medium-text-color);
}

.influx-feed.is-ok .influx-feed-dot {
    background: var(--enabled-color);
    box-shadow: 0 0 0 3px rgba(45, 168, 100, 0.16);
}

.influx-feed.is-error .influx-feed-dot {
    background: var(--error-color);
    box-shadow: 0 0 0 3px rgba(207, 17, 36, 0.16);
}

.influx-feed.is-loading .influx-feed-dot {
    background: var(--pending-color);
    animation: influx-feed-pulse 1.2s ease-in-out infinite;
}

@keyframes influx-feed-pulse {
    0%, 100% { opacity: 1;    transform: scale(1); }
    50%      { opacity: 0.4;  transform: scale(0.8); }
}

.influx-feed-endpoint {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    flex: 1 1 auto;
}

.influx-feed-url {
    padding: 0;
    background: none;
    font-size: 13px;
    color: var(--text-color);
    word-break: break-all;
}

.influx-feed-status {
    flex: none;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--medium-text-color);
}

.influx-feed.is-ok    .influx-feed-status { color: var(--enabled-color); }
.influx-feed.is-error .influx-feed-status { color: var(--error-color); }

.influx-feed-loading {
    margin: 0;
    padding: 16px 18px;
    color: var(--light-text-color);
}

.influx-feed-error {
    padding: 14px 18px;
}

.influx-feed-error pre {
    margin: 0;
    font-size: 12px;
    color: var(--error-color);
    white-space: pre-wrap;
    word-break: break-word;
}

/* Tiled facts — 1px gaps over a hairline backdrop draw the gridlines, so
   each tile keeps Craft's panel colour while the dividers stay crisp. */
.influx-feed-stats {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 1px;
    background: var(--hairline-color);
}

.influx-feed-stat {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 14px 18px;
    background: var(--white);
}

.influx-feed-stat-v {
    font-size: 13px;
    color: var(--text-color);
}

.influx-feed-stat-v code {
    padding: 1px 5px;
    font-size: 12px;
    background: var(--gray-100);
    border-radius: var(--small-border-radius);
}

.influx-feed-stat-v em {
    font-style: normal;
    color: var(--light-text-color);
}

.influx-feed-arrow {
    margin: 0 3px;
    color: var(--light-text-color);
}
</style>
