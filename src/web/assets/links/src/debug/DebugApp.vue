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
            <stats-grid tag="form" class="influx-feed-controls" @submit.prevent="inspect">
                <stat-cell class="influx-feed-cell" :label="$t('Site')">
                    <span v-if="!sites.length" class="influx-feed-static">{{ $t('Default endpoint') }}</span>
                    <div v-else class="select">
                        <select v-model="site">
                            <option v-for="s in sites" :key="s.handle" :value="s.handle">{{ s.name }}</option>
                        </select>
                    </div>
                </stat-cell>

                <stat-cell class="influx-feed-cell" :label="$t('Sliding window')">
                    <span v-if="!offsetHandles.length" class="influx-feed-static">{{ $t('No presets') }}</span>
                    <div v-else class="select">
                        <select v-model="offset">
                            <option value="">{{ $t('all') }}</option>
                            <option v-for="h in offsetHandles" :key="h" :value="h">{{ h }}</option>
                        </select>
                    </div>
                </stat-cell>

                <stat-cell class="influx-feed-cell" :label="$t('Limit')">
                    <input v-model.number="limit" type="number" min="1" max="500" class="text influx-feed-limit">
                </stat-cell>
            </stats-grid>

            <p v-if="!meta" class="influx-feed-loading"><span class="spinner"></span> {{ $t('Fetching feed…') }}</p>

            <error-panel v-else-if="meta.error" class="influx-feed-error" :error="meta.error" />

            <stats-grid v-else align-top class="influx-feed-stats">
                <!-- Pinned to the first column: what this feed is configured to
                     do when a real run commits. -->
                <stat-cell v-if="processingTags.length" class="influx-feed-cell influx-feed-cell--first" :label="$t('Actions')">
                    <span class="influx-feed-tags">
                        <action-badge v-for="t in processingTags" :key="t.label" class="influx-feed-tag" :color="t.color">{{ t.label }}</action-badge>
                    </span>
                </stat-cell>

                <stat-cell v-if="meta.paginatorNode" class="influx-feed-cell" :label="$t('Paginator')">
                    <code class="influx-feed-token">{{ meta.paginatorNode }}</code>
                    <span v-if="meta.paginatorValue" class="influx-feed-rel" :title="meta.paginatorValue">
                        <span class="influx-feed-arrow" aria-hidden="true">→</span><code class="influx-feed-token">{{ meta.paginatorValue }}</code>
                    </span>
                    <span v-else class="influx-feed-rel light">{{ $t('no next page') }}</span>
                </stat-cell>

                <stat-cell v-if="meta.matchAttribute" class="influx-feed-cell" :label="$t('Match')">
                    <code class="influx-feed-token">{{ meta.matchAttribute }}</code>
                    <span v-if="meta.matchNode" class="influx-feed-rel">
                        <span class="influx-feed-arrow" aria-hidden="true">↤</span><code class="influx-feed-token">{{ meta.matchNode }}</code>
                    </span>
                </stat-cell>

                <stat-cell v-if="meta.offsetLabel" class="influx-feed-cell" :label="$t('Sliding window')">
                    <code class="influx-feed-token">{{ meta.offset }}</code>
                    <span class="influx-feed-rel"><span class="influx-feed-arrow" aria-hidden="true">→</span>{{ meta.offsetLabel }}</span>
                </stat-cell>

                <!-- Resolved total/page counts — confirms the count nodes
                     actually resolve, so progress % can be trusted. -->
                <stat-cell v-if="meta.totalCount != null || meta.pageCount != null" class="influx-feed-cell" :label="$t('Feed reports')">
                    <span class="influx-feed-stat-v">
                        <template v-if="meta.totalCount != null">{{ meta.totalCount }} {{ $t('items') }}</template>
                        <template v-if="meta.totalCount != null && meta.pageCount != null"> · </template>
                        <template v-if="meta.pageCount != null">{{ meta.pageCount }} {{ $t('pages') }}</template>
                    </span>
                </stat-cell>

                <!-- Pinned to the last column (under the empty controls cell)
                     regardless of how many other facts are present. -->
                <stat-cell class="influx-feed-cell influx-feed-cell--last" :label="$t('Items on page')">
                    <span class="influx-feed-stat-v">{{ meta.itemsOnPage }}</span>
                    <span v-if="meta.itemsOnPage > meta.limit" class="influx-feed-rel">{{ $t('showing first {n}', { n: meta.limit }) }}</span>
                </stat-cell>
            </stats-grid>
        </div>

        <h2 class="influx-debug-h2">{{ $t('Items') }}</h2>

        <debug-item v-for="(row, i) in items" :key="`${inspectRun}:${i}`" :row="row" />

        <div v-if="statusLabel" class="light influx-debug-status">{{ statusLabel }}</div>
    </div>
</template>

<script>
import DebugItem from '../components/DebugItem.vue';
import ActionBadge from '../components/ActionBadge.vue';
import ErrorPanel from '../components/ErrorPanel.vue';
import StatsGrid from '../components/StatsGrid.vue';
import StatCell from '../components/StatCell.vue';
import { actionColor } from '../lib/actionColors.js';
import { requestErrorMessage } from '../lib/requestError.js';

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

    components: { DebugItem, ActionBadge, ErrorPanel, StatsGrid, StatCell },

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
            // Monotonic per-inspect counter: guards against superseded
            // responses (rapid re-clicks) AND keys the item rows so every
            // inspect remounts them — expanded cards / open <details> from a
            // previous payload must not bleed into positionally-matching rows.
            inspectRun: 0,
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
            const token = ++this.inspectRun;

            const base = this.config.inspectUrl;
            const params = new URLSearchParams();
            if (this.site) params.set('site', this.site);
            if (this.offset) params.set('offset', this.offset);
            params.set('limit', String(this.limit || 10));
            const url = base + (base.includes('?') ? '&' : '?') + params.toString();

            window.Craft.sendActionRequest('GET', url).then((response) => {
                if (token !== this.inspectRun) return;
                const data = response.data || {};
                this.meta = data.meta || null;
                this.items = data.items || [];
            }).catch((err) => {
                if (token !== this.inspectRun) return;
                // Surface as a feed-level error so the panel shows it.
                this.meta = { error: requestErrorMessage(err, this.$t('Inspection failed.')) };
                this.items = [];
            }).finally(() => {
                if (token === this.inspectRun) this.loading = false;
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

/* The quarter-width grid itself (and the controls' bottom / facts' top cell
   alignment) lives in StatsGrid; the cells in StatCell. Both rows share the
   same four columns, so Site/Window/Limit line up over the facts below. */
.influx-feed-controls { border-bottom: 1px solid var(--hairline-color); }

/* Configured actions pin left, items-on-page pins right, so they bookend the
   facts row whatever else is present; the remaining facts flow between. */
.influx-feed-cell--first { grid-column: 1; }
.influx-feed-cell--last { grid-column: 4; }

.influx-feed-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

/* Eyebrow for the endpoint header only — the row cells get theirs from
   StatCell now; the tag pills' chrome + palette live in ActionBadge. */
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

/* Slot-composed cells (Feed reports, Items on page) style their own value
   span; plain cells get this from StatCell's value. */
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

/* The <pre> treatment lives in ErrorPanel; the text colour is inherited. */
.influx-feed-error {
    padding: 14px 18px;
    color: var(--error-color);
}

/* The 2-column collapse itself lives in StatsGrid — only the bookend pins
   release here. */
@media (max-width: 740px) {
    .influx-feed-cell--first,
    .influx-feed-cell--last { grid-column: auto; }
}
</style>
