<template>
    <div class="influx-debug-app">
        <!-- Inspect lives in Craft's page-header action-buttons slot (rendered
             server-side in debug.twig), so it sits where every other CP primary
             action does. Teleport keeps it a reactive Vue button (disabled/label
             track the fetch) while placing it there; it falls back to rendering
             in place when the target is absent (e.g. unit tests). -->
        <teleport :to="actionTarget" :disabled="!hasActionTarget">
            <button type="button" class="btn submit" data-icon="search" :disabled="loading" @click="inspect">
                {{ loading ? $t('Inspecting…') : $t('Inspect') }}
            </button>
        </teleport>

        <!-- Feed report — one panel: a header (live status dot + endpoint), the
             site/window/limit controls, and the run's resolved facts, all sharing
             a single quarter-width column grid so the rows line up. -->
        <div class="influx-feed">
            <div class="influx-feed-bar">
                <div class="influx-feed-endpoint">
                    <span class="influx-feed-eyebrow">{{ $t('Endpoint') }}</span>
                    <code class="influx-feed-url">{{ (meta && meta.url) || '—' }}</code>
                </div>
            </div>

            <!-- Controls row. A form so Enter in any field re-inspects (and the
                 unit test can drive a submit); the visible trigger is the
                 page-header button above. -->
            <form class="influx-feed-row influx-feed-controls" @submit.prevent="inspect">
                <div class="influx-feed-cell">
                    <span class="influx-feed-eyebrow">{{ $t('Site') }}</span>
                    <span v-if="!sites.length" class="influx-feed-static">{{ $t('Default endpoint') }}</span>
                    <div v-else class="select">
                        <select v-model="site">
                            <option v-for="s in sites" :key="s.handle" :value="s.handle">{{ s.name }}</option>
                        </select>
                    </div>
                </div>

                <div class="influx-feed-cell">
                    <span class="influx-feed-eyebrow">{{ $t('Sliding window') }}</span>
                    <span v-if="!offsetHandles.length" class="influx-feed-static">{{ $t('No presets') }}</span>
                    <div v-else class="select">
                        <select v-model="offset">
                            <option value="">{{ $t('all') }}</option>
                            <option v-for="h in offsetHandles" :key="h" :value="h">{{ h }}</option>
                        </select>
                    </div>
                </div>

                <div class="influx-feed-cell">
                    <span class="influx-feed-eyebrow">{{ $t('Limit') }}</span>
                    <input v-model.number="limit" type="number" min="1" max="500" class="text influx-feed-limit">
                </div>
            </form>

            <p v-if="!meta" class="influx-feed-loading"><span class="spinner"></span> {{ $t('Fetching feed…') }}</p>

            <div v-else-if="meta.error" class="influx-feed-error">
                <pre>{{ meta.error }}</pre>
            </div>

            <div v-else class="influx-feed-row influx-feed-stats">
                <!-- Pinned to the first column: what this feed is configured to
                     do when a real run commits. -->
                <div v-if="processingTags.length" class="influx-feed-cell influx-feed-cell--first">
                    <span class="influx-feed-eyebrow">{{ $t('Actions') }}</span>
                    <span class="influx-feed-tags">
                        <span v-for="t in processingTags" :key="t.label" class="influx-feed-tag" :class="t.color">{{ t.label }}</span>
                    </span>
                </div>

                <div v-if="meta.paginatorNode" class="influx-feed-cell">
                    <span class="influx-feed-eyebrow">{{ $t('Paginator') }}</span>
                    <code class="influx-feed-token">{{ meta.paginatorNode }}</code>
                    <span v-if="meta.paginatorValue" class="influx-feed-rel" :title="meta.paginatorValue">
                        <span class="influx-feed-arrow" aria-hidden="true">→</span><code class="influx-feed-token">{{ meta.paginatorValue }}</code>
                    </span>
                    <span v-else class="influx-feed-rel light">{{ $t('no next page') }}</span>
                </div>

                <div v-if="meta.matchAttribute" class="influx-feed-cell">
                    <span class="influx-feed-eyebrow">{{ $t('Match') }}</span>
                    <code class="influx-feed-token">{{ meta.matchAttribute }}</code>
                    <span v-if="meta.matchNode" class="influx-feed-rel">
                        <span class="influx-feed-arrow" aria-hidden="true">↤</span><code class="influx-feed-token">{{ meta.matchNode }}</code>
                    </span>
                </div>

                <div v-if="meta.offsetLabel" class="influx-feed-cell">
                    <span class="influx-feed-eyebrow">{{ $t('Sliding window') }}</span>
                    <code class="influx-feed-token">{{ meta.offset }}</code>
                    <span class="influx-feed-rel"><span class="influx-feed-arrow" aria-hidden="true">→</span>{{ meta.offsetLabel }}</span>
                </div>

                <!-- Resolved total/page counts — confirms the count nodes
                     actually resolve, so progress % can be trusted. -->
                <div v-if="meta.totalCount != null || meta.pageCount != null" class="influx-feed-cell">
                    <span class="influx-feed-eyebrow">{{ $t('Feed reports') }}</span>
                    <span class="influx-feed-stat-v">
                        <template v-if="meta.totalCount != null">{{ meta.totalCount }} {{ $t('items') }}</template>
                        <template v-if="meta.totalCount != null && meta.pageCount != null"> · </template>
                        <template v-if="meta.pageCount != null">{{ meta.pageCount }} {{ $t('pages') }}</template>
                    </span>
                </div>

                <!-- Pinned to the last column (under the empty controls cell)
                     regardless of how many other facts are present. -->
                <div class="influx-feed-cell influx-feed-cell--last">
                    <span class="influx-feed-eyebrow">{{ $t('Items on page') }}</span>
                    <span class="influx-feed-stat-v">{{ meta.itemsOnPage }}</span>
                    <span v-if="meta.itemsOnPage > meta.limit" class="influx-feed-rel">{{ $t('showing first {n}', { n: meta.limit }) }}</span>
                </div>
            </div>
        </div>

        <h2 class="influx-debug-h2">{{ $t('Items') }}</h2>

        <debug-item v-for="(row, i) in items" :key="i" :row="row" />

        <div v-if="statusLabel" class="light influx-debug-status">{{ statusLabel }}</div>
    </div>
</template>

<script>
import DebugItem from '../components/DebugItem.vue';
import { actionColor } from '../lib/actionColors.js';

// Configured processing actions → the dry-run tag they'd produce. Mirrors
// ItemAction::dryRunLabel() on the PHP side; the order is ALL_PROCESSING so
// the tags always read create → update → disable → delete.
const PROCESSING_LABELS = {
    'create':          'would-create',
    'update':          'would-update',
    'disable':         'would-disable',
    'delete':          'would-delete',
    'delete-for-site': 'would-delete-for-site',
};

/**
 * The debug inspector page. Owns the site/offset/limit form and renders the
 * feed panel + a list of DebugItem cards from a single JSON fetch (the
 * inspector only ever reads the first page, so there's nothing to stream).
 * Inspecting again re-fetches in place — no full page reload.
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
            processing: this.config.processing || [],
            meta: null,
            items: [],
            loading: false,
            // Craft renders an empty action-buttons slot (see debug.twig) that
            // we Teleport the Inspect button into. Resolved in mounted(); until
            // then the Teleport is disabled and the button renders in place.
            actionTarget: '#influx-debug-actions',
            hasActionTarget: false,
        };
    },

    computed: {
        // The link's configured processing actions as dry-run tags — what
        // this feed is allowed to do when a real run commits.
        processingTags() {
            return Object.keys(PROCESSING_LABELS)
                .filter((action) => this.processing.includes(action))
                .map((action) => {
                    const label = PROCESSING_LABELS[action];

                    return { label, color: actionColor(label) };
                });
        },

        statusLabel() {
            return this.loading ? this.$t('Loading…') : '';
        },
    },

    mounted() {
        this.hasActionTarget = !!document.querySelector(this.actionTarget);
        this.inspect();
    },

    methods: {
        inspect() {
            this.meta = null;
            this.items = [];
            this.loading = true;

            // Ignore a response that a newer inspect has superseded (rapid
            // re-clicks) or that lands after the component is gone.
            const token = this._reqToken = (this._reqToken || 0) + 1;

            const base = this.config.inspectUrl;
            const params = new URLSearchParams();
            if (this.site) params.set('site', this.site);
            if (this.offset) params.set('offset', this.offset);
            params.set('limit', String(this.limit || 10));
            const url = base + (base.includes('?') ? '&' : '?') + params.toString();

            window.Craft.sendActionRequest('GET', url).then((response) => {
                if (token !== this._reqToken) return;
                const data = response.data || {};
                this.meta = data.meta || null;
                this.items = data.items || [];
            }).catch((err) => {
                if (token !== this._reqToken) return;
                // Surface as a feed-level error so the panel shows it.
                this.meta = { error: err?.response?.data?.message || err?.message || this.$t('Inspection failed.') };
                this.items = [];
            }).finally(() => {
                if (token === this._reqToken) this.loading = false;
            });
        },
    },
};
</script>

<style scoped>
.influx-debug-h2 { margin-top: 28px; }
.influx-debug-status { margin-top: 14px; }

/* ---- Feed panel --------------------------------------------------------- */
.influx-feed {
    overflow: hidden;
    background: var(--white);
    border: 1px solid var(--hairline-color);
    border-radius: var(--large-border-radius);
}

/* Header bar: the resolved endpoint. */
.influx-feed-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 18px;
    background: var(--gray-050);
    border-bottom: 1px solid var(--hairline-color);
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

/* Shared quarter-width column grid — the controls row and the facts row use
   the same four columns, so Site/Window/Limit line up over the facts below. */
.influx-feed-row {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px 18px;
    align-items: end;
    padding: 16px 18px;
}

.influx-feed-controls { border-bottom: 1px solid var(--hairline-color); }

/* Controls bottom-align their inputs (.influx-feed-row default); facts top-align
   so their labels share a baseline while values flow down to varying heights. */
.influx-feed-stats { align-items: start; }

.influx-feed-cell {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}

/* Configured actions pin left, items-on-page pins right, so they bookend the
   facts row whatever else is present; the remaining facts flow between. */
.influx-feed-cell--first { grid-column: 1; }
.influx-feed-cell--last { grid-column: 4; }

.influx-feed-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

/* Same pill palette as the debug-item action tags. */
.influx-feed-tag {
    border-radius: 9px;
    padding: 2px 9px;
    font-size: 11px;
    font-weight: 600;
}

.influx-feed-tag.live    { background: #d6f1de; color: #064f1f; border: 1px solid #7fcb95; }
.influx-feed-tag.pending { background: rgba(0, 0, 0, 0.08); color: #555; }
.influx-feed-tag.expired { background: #fde2e2; color: #8a1f1f; border: 1px solid #e7a3a3; }

.influx-feed-eyebrow {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--medium-text-color);
}

.influx-feed-static {
    padding: 6px 0;
    font-size: 13px;
    color: var(--light-text-color);
}

/* Controls fill their quarter rather than sizing to content. */
.influx-feed-controls .select,
.influx-feed-controls .select select,
.influx-feed-limit { width: 100%; }

.influx-feed-stat-v {
    font-size: 13px;
    color: var(--text-color);
}

/* Identifiers (node paths, handles) read as code but without the heavy chip
   chrome — plain monospace that sits inline with the surrounding text. */
.influx-feed-token {
    padding: 0;
    background: none;
    font-size: 12px;
    color: var(--text-color);
}

/* The resolved/secondary side of a fact (the live next-page URL, the source
   node a match maps from, the window label) on its own muted line — truncated
   with a hover title so a long URL never wraps mid-token. */
.influx-feed-rel {
    display: block;
    overflow: hidden;
    max-width: 100%;
    font-size: 12px;
    color: var(--medium-text-color);
    white-space: nowrap;
    text-overflow: ellipsis;
}

.influx-feed-rel .influx-feed-token { color: var(--medium-text-color); }

.influx-feed-arrow {
    margin-right: 5px;
    color: var(--light-text-color);
}

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

@media (max-width: 740px) {
    .influx-feed-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .influx-feed-cell--first,
    .influx-feed-cell--last { grid-column: auto; }
}
</style>
