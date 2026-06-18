<template>
    <div class="influx-log-app">
        <!-- Pause/resume the live log refresh from Craft's page-header action
             slot (rendered server-side in logs/view.twig). This only stops the
             inbound updates — the sync worker keeps processing in the
             background. Only present while the run is live and hasn't finished;
             falls back to rendering in place when the target is absent (tests). -->
        <teleport v-if="isLive && !streamDone" :to="actionTarget" :disabled="!hasActionTarget">
            <button
                type="button"
                class="btn"
                :title="paused ? $t('Resume live log updates') : $t('Pause live log updates — the sync keeps running in the background')"
                @click="paused ? resumeStream() : pauseStream()"
            >
                {{ paused ? $t('Resume updates') : $t('Pause updates') }}
            </button>
        </teleport>

        <!-- One report panel: header (link + status), the error band (when the
             run failed), run info, then the per-action counters — all in the
             debug feed's quarter-width grid. -->
        <div class="influx-log-panel">
            <div class="influx-log-panel-bar">
                <div class="influx-log-cell">
                    <span class="influx-log-eyebrow">{{ $t('Link') }}</span>
                    <a v-if="linkUrl" :href="linkUrl" class="influx-log-link">{{ linkName }}</a>
                    <span v-else class="influx-log-link">{{ linkName }}</span>
                </div>
                <span class="influx-log-status" :class="statusClass">{{ log.status }}</span>
            </div>

            <div v-if="endpointUrl" class="influx-log-endpoint">
                <span class="influx-log-eyebrow">{{ $t('Endpoint') }}</span>
                <a :href="endpointUrl" target="_blank" rel="noopener" class="influx-log-endpoint-url">{{ endpointUrl }}</a>
            </div>

            <div v-if="log.error" class="influx-log-error"><pre>{{ log.error }}</pre></div>

            <div class="influx-log-grid">
                <div class="influx-log-cell">
                    <span class="influx-log-eyebrow">{{ $t('Trigger') }}</span>
                    <span class="influx-log-cell-v">{{ log.trigger }}</span>
                </div>
                <div class="influx-log-cell">
                    <span class="influx-log-eyebrow">{{ $t('Started') }}</span>
                    <span class="influx-log-cell-v">{{ log.startedAt }}</span>
                </div>
                <div class="influx-log-cell">
                    <span class="influx-log-eyebrow">{{ $t('Finished') }}</span>
                    <span class="influx-log-cell-v">{{ log.finishedAt || '—' }}</span>
                </div>
            </div>

            <div class="influx-log-grid influx-log-grid--divided">
                <div class="influx-log-cell"><span class="influx-log-eyebrow">{{ $t('Seen') }}</span><span class="influx-log-cell-v">{{ log.itemsSeen }}</span></div>
                <div class="influx-log-cell"><span class="influx-log-eyebrow">{{ $t('Created') }}</span><span class="influx-log-cell-v">{{ log.itemsCreated }}</span></div>
                <div class="influx-log-cell"><span class="influx-log-eyebrow">{{ $t('Updated') }}</span><span class="influx-log-cell-v">{{ log.itemsUpdated }}</span></div>
                <div class="influx-log-cell"><span class="influx-log-eyebrow">{{ $t('Skipped') }}</span><span class="influx-log-cell-v">{{ log.itemsSkipped }}</span></div>
                <div class="influx-log-cell"><span class="influx-log-eyebrow">{{ $t('Unchanged') }}</span><span class="influx-log-cell-v">{{ log.itemsUnchanged }}</span></div>
                <div class="influx-log-cell"><span class="influx-log-eyebrow">{{ $t('Deleted') }}</span><span class="influx-log-cell-v">{{ log.itemsDeleted }}</span></div>
                <div class="influx-log-cell"><span class="influx-log-eyebrow">{{ $t('Disabled') }}</span><span class="influx-log-cell-v">{{ log.itemsDisabled }}</span></div>
            </div>
        </div>

        <h2 class="influx-log-h2">
            {{ $t('Items') }}
            <span v-if="streamLabel" class="light">— {{ streamLabel }}</span>
        </h2>

        <!-- Craft element-index-style toolbar: status menu + search, both
             filtering server-side. -->
        <div class="influx-log-toolbar">
            <log-filter-menu
                :filter-defs="filterDefs"
                :model-value="activeAction"
                @update:model-value="setAction"
            />
            <div class="texticon search icon influx-log-search">
                <input
                    type="text"
                    class="text fullwidth"
                    :placeholder="$t('Search match value or message')"
                    v-model="search"
                >
            </div>
        </div>

        <div class="influx-log-items">
            <log-item
                v-for="item in items"
                :key="item.id"
                :item="item"
                :item-url-template="config.itemUrlTemplate"
            />
        </div>

        <nav v-if="totalPages > 1" class="influx-log-pager">
            <button type="button" class="btn" :disabled="currentPage <= 1 || loadingItems" @click="fetchPage(currentPage - 1)">← {{ $t('Previous') }}</button>
            <span class="light">{{ $t('Page {n} of {total}', { n: currentPage, total: totalPages }) }}</span>
            <button type="button" class="btn" :disabled="currentPage >= totalPages || loadingItems" @click="fetchPage(currentPage + 1)">{{ $t('Next') }} →</button>
        </nav>

        <p v-if="!itemTotal && !isLive" class="light">{{ $t('No data to process') }}</p>
    </div>
</template>

<script>
import LogItem from './LogItem.vue';
import LogFilterMenu from './LogFilterMenu.vue';

const FILTER_DEFS = [
    { action: 'created',   color: 'live' },
    { action: 'updated',   color: 'live' },
    { action: 'unchanged', color: 'pending' },
    { action: 'skipped',   color: 'pending' },
    { action: 'deleted',   color: 'expired' },
    { action: 'disabled',  color: 'expired' },
    { action: 'error',     color: 'expired' },
];

/**
 * The run-log viewer: the summary + counters panels, the action filter menu,
 * the paginated list of LogItem cards (each with an inspect drill-down), and —
 * for a still-running log — interval polling that appends new rows and
 * refreshes counters (with a pause control).
 */
export default {
    name: 'LogApp',

    components: { LogItem, LogFilterMenu },

    props: {
        config: { type: Object, required: true },
    },

    data() {
        return {
            log: { ...this.config.log },
            // The current page only — newest-first, server-filtered. The rest
            // is paged in from actionItems(); the bootstrap ships page 1.
            items: [...(this.config.items || [])],
            itemTotal: this.config.itemTotal || 0,
            perPage: this.config.perPage || 25,
            loadingItems: false,
            isLive: !!this.config.isLive,
            filterDefs: FILTER_DEFS,
            // Single-select action filter (null = All) + free-text search, both
            // applied server-side via actionItems.
            activeAction: null,
            search: '',
            currentPage: 1,
            streamLabel: this.config.isLive ? this.$t('connecting…') : '',
            poller: null,
            // User-paused the live updates (stops the poll timer; resume
            // restarts it from the last item). Distinct from streamDone, which
            // is the run actually finishing.
            paused: false,
            streamDone: false,
            // Craft renders an empty action-buttons slot (see logs/view.twig)
            // that we Teleport the pause/resume button into.
            actionTarget: '#influx-log-actions',
            hasActionTarget: false,
        };
    },

    computed: {
        linkUrl() {
            return this.config.linkId ? window.Craft.getCpUrl('influx/links/' + this.config.linkId) : null;
        },

        // Friendly name for the header link; falls back to the handle (logs
        // only store the handle, so the name rides along in the config).
        linkName() {
            return this.config.linkName || this.log.linkHandle;
        },

        endpointUrl() {
            return this.config.endpointUrl || null;
        },

        statusClass() {
            if (this.log.status === 'ok') return 'live';
            if (this.log.status === 'error') return 'expired';

            return 'pending';
        },

        totalPages() {
            return Math.max(1, Math.ceil(this.itemTotal / this.perPage));
        },
    },

    watch: {
        // Debounce the search box, then re-query from page 1.
        search() {
            clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(() => this.fetchPage(1), 300);
        },
    },

    mounted() {
        this.hasActionTarget = !!document.querySelector(this.actionTarget);

        if (this.isLive) {
            this.startPolling();
        }
    },

    beforeUnmount() {
        this.stopPolling();
    },

    methods: {
        setAction(action) {
            this.activeAction = action;
            this.fetchPage(1);
        },

        pauseStream() {
            this.paused = true;
            this.stopPolling();
            this.streamLabel = this.$t('updates paused');
        },

        resumeStream() {
            this.paused = false;
            this.startPolling();
        },

        // Poll the run on an interval while it's live — Craft's queue-runner
        // pattern — rather than holding an SSE connection (and the session
        // lock) open. Each tick just re-fetches the page in view, so new items
        // appear on page 1 and the counters refresh.
        startPolling() {
            this.streamLabel = this.$t('live updates');
            this.fetchPage(this.currentPage);
            this.poller = setInterval(() => this.fetchPage(this.currentPage), 1500);
        },

        stopPolling() {
            if (this.poller) {
                clearInterval(this.poller);
                this.poller = null;
            }
        },

        // Fetch one page of items (server-filtered + paginated) and refresh the
        // counters. The pager, the status filter, the search box, and the live
        // poll all route through here.
        fetchPage(page) {
            this.currentPage = Math.max(1, page);

            const params = new URLSearchParams();
            params.set('page', String(this.currentPage));

            // `status`, not `action`: Craft reserves the `action` query param
            // for controller-action routing, so `?action=…` 404s the request.
            if (this.activeAction) {
                params.set('status', this.activeAction);
            }

            if (this.search.trim() !== '') {
                params.set('search', this.search.trim());
            }

            const base = this.config.itemsUrl;
            const url = base + (base.includes('?') ? '&' : '?') + params.toString();

            this.loadingItems = true;

            window.Craft.sendActionRequest('GET', url).then((response) => {
                const data = response.data || {};
                this.items = data.items || [];
                this.itemTotal = data.total || 0;
                this.applyCounters(data);
            }).catch(() => {
                // A failed request reads as paused so the button offers a retry
                // rather than a dead "Pause".
                this.streamLabel = this.$t('connection lost');
                this.paused = true;
                this.stopPolling();
            }).finally(() => {
                this.loadingItems = false;
            });
        },

        applyCounters(data) {
            const c = data.counters || {};
            ['itemsSeen', 'itemsCreated', 'itemsUpdated', 'itemsUnchanged', 'itemsSkipped', 'itemsDeleted', 'itemsDisabled'].forEach((k) => {
                if (c[k] !== undefined) this.log[k] = c[k];
            });
            if (c.status) this.log.status = c.status;
            if (c.finishedAt) this.log.finishedAt = c.finishedAt;
            if (c.error) this.log.error = c.error;

            if (data.done) {
                this.streamLabel = '';
                this.streamDone = true;
                this.stopPolling();
            }
        },
    },
};
</script>

<style scoped>
/* Run summary + counters panels — the same quarter-width grid the debug feed
   panel uses (DebugApp), so the two screens read consistently. */
.influx-log-panel {
    overflow: hidden;
    margin-bottom: 14px;
    background: var(--white);
    border: 1px solid var(--hairline-color);
    border-radius: var(--large-border-radius);
}

.influx-log-panel-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 18px;
    background: var(--gray-050);
    border-bottom: 1px solid var(--hairline-color);
}

.influx-log-panel-bar .influx-log-cell { flex: 1 1 auto; }

/* The endpoint this run fetched from — a full-width sub-row under the link bar
   (URLs are long, so it wraps), hairline-separated from the run-info grid. */
.influx-log-endpoint {
    display: flex;
    flex-direction: column;
    gap: 5px;
    padding: 11px 18px;
    border-bottom: 1px solid var(--hairline-color);
}
.influx-log-endpoint-url {
    font-size: 12px;
    word-break: break-all;
}

/* Status as a colour-coded pill (same palette as the action tags). */
.influx-log-status {
    flex: none;
    border-radius: 9px;
    padding: 2px 10px;
    font-size: 11px;
    font-weight: 600;
}
.influx-log-status.live { background: #d6f1de; color: #064f1f; border: 1px solid #7fcb95; }
.influx-log-status.expired { background: #fde2e2; color: #8a1f1f; border: 1px solid #e7a3a3; }
.influx-log-status.pending { background: rgba(0, 0, 0, .08); color: #555; }

.influx-log-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px 18px;
    align-items: start;
    padding: 16px 18px;
}

/* Counters sit in the same panel as the run info, split off by a hairline. */
.influx-log-grid--divided { border-top: 1px solid var(--hairline-color); }

.influx-log-cell {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}

.influx-log-eyebrow {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--medium-text-color);
}

.influx-log-cell-v {
    font-size: 13px;
    color: var(--text-color);
    word-break: break-word;
}

.influx-log-cell-v code { padding: 0; background: none; }

/* Full-width error band between the header and the run info — red-ish field
   with the error text, same palette as the error pills. */
.influx-log-error {
    padding: 12px 18px;
    background: #fdecec;
    border-bottom: 1px solid var(--hairline-color);
}
.influx-log-error pre {
    margin: 0;
    font-size: 12px;
    color: #8a1f1f;
    white-space: pre-wrap;
    word-break: break-word;
}

.influx-log-h2 { margin-top: 24px; }

@media (max-width: 740px) {
    .influx-log-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

.influx-log-pager {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 14px;
}

/* Status menu + search, laid out like Craft's element-index toolbar. */
.influx-log-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 14px;
}
.influx-log-search { flex: 1 1 auto; }
</style>
