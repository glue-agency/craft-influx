<template>
    <div class="influx-log-app">
        <!-- Pause/resume the live refresh from Craft's page-header action slot
             (rendered server-side in logs/view.twig). Only stops the inbound
             updates — the sync worker keeps running in the background. Present
             only while the run is live and hasn't settled. -->
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

        <!-- Run summary: identity + endpoint, the error band, and the counters
             (which double as the item-list filter). -->
        <div class="influx-log-summary">
            <div class="influx-log-summary-bar">
                <div class="influx-log-ident">
                    <a v-if="linkUrl" :href="linkUrl" class="influx-log-link">{{ linkName }}</a>
                    <span v-else class="influx-log-link">{{ linkName }}</span>
                    <a v-if="endpointUrl" :href="endpointUrl" target="_blank" rel="noopener" class="influx-log-endpoint-url">{{ endpointUrl }}</a>
                </div>
                <div class="influx-log-meta">
                    <span>{{ metaLine }}</span>
                    <span v-if="metaFinished">{{ metaFinished }}</span>
                </div>
            </div>

            <!-- All-sites run over per-site endpoints: one line each. -->
            <div v-if="!endpointUrl && endpoints.length" class="influx-log-endpoint">
                <span class="influx-log-eyebrow">{{ $t('Endpoints') }}</span>
                <code
                    v-for="endpoint in endpoints"
                    :key="endpoint.site"
                    class="influx-log-endpoint-url influx-log-endpoint-line"
                >{{ endpoint.site }}: {{ endpoint.url }}</code>
            </div>

            <!-- Single-resource run: the element it was triggered for. -->
            <div v-if="resourceHtml" class="influx-log-endpoint influx-log-resource">
                <span class="influx-log-eyebrow">{{ $t('Resource') }}</span>
                <span v-html="resourceHtml"></span>
            </div>

            <error-panel v-if="log.error" class="influx-log-error" :error="log.error" />

            <div class="influx-log-counters">
                <button
                    v-for="c in counters"
                    :key="c.label"
                    type="button"
                    class="influx-counter"
                    :class="{ 'is-active': c.action === activeAction }"
                    :title="c.action ? $t('Show only {label} items', { label: c.label }) : $t('Show all items')"
                    @click="setAction(c.action)"
                >
                    <span class="influx-counter-value" :class="c.tone">{{ c.value }}</span>
                    <span class="influx-counter-label">{{ c.label }}</span>
                </button>
            </div>
        </div>

        <!-- Split inspector, same pattern as the Debug view. -->
        <div class="influx-split">
            <div class="influx-split-list">
                <div class="influx-split-list-head">
                    <span class="influx-split-list-title">
                        {{ $t('Items') }}
                        <span class="light">{{ $t('{n} processed', { n: itemTotal }) }}</span>
                    </span>
                    <span class="influx-split-list-hint">
                        {{ activeAction ? $t('showing {label}', { label: activeLabel }) : $t('filter with the counters above') }}
                    </span>
                </div>

                <div class="influx-split-list-scroll">
                    <p v-if="loadingItems && !items.length" class="influx-split-loading"><span class="spinner"></span> {{ $t('Loading…') }}</p>

                    <template v-else>
                        <button
                            v-for="item in items"
                            :key="item.id"
                            type="button"
                            class="influx-split-item"
                            :class="{ 'is-selected': item.id === selectedId }"
                            @click="select(item.id)"
                        >
                            <span class="influx-split-item-top">
                                <span class="influx-split-item-title">{{ item.title }}</span>
                                <span
                                    v-if="item.errorCount"
                                    class="influx-log-haserror"
                                    data-icon="alert"
                                    :title="$t('Saved despite {n} field error(s)', { n: item.errorCount })"
                                >{{ item.errorCount }}</span>
                                <action-badge :action="item.action" class="influx-split-item-badge" />
                            </span>
                            <span v-if="item.message" class="influx-split-item-sub">{{ item.message }}</span>
                        </button>

                        <p v-if="!items.length" class="influx-split-empty light">{{ emptyLabel }}</p>
                    </template>
                </div>

                <nav v-if="totalPages > 1" class="influx-split-pager">
                    <button type="button" class="btn" :disabled="currentPage <= 1 || loadingItems" @click="fetchPage(currentPage - 1)">&larr;</button>
                    <span class="light">{{ $t('Page {n} of {total}', { n: currentPage, total: totalPages }) }}</span>
                    <button type="button" class="btn" :disabled="currentPage >= totalPages || loadingItems" @click="fetchPage(currentPage + 1)">&rarr;</button>
                </nav>
            </div>

            <div class="influx-split-detail">
                <p v-if="loadingRow" class="influx-split-loading"><span class="spinner"></span> {{ $t('Loading…') }}</p>
                <p v-else-if="selectedError" class="error influx-split-placeholder">{{ selectedError }}</p>
                <debug-item-detail
                    v-else-if="selectedRow"
                    :key="selectedId"
                    :row="selectedRow"
                    :match-attribute="selectedRow.matchAttribute || ''"
                />
                <p v-else class="influx-split-placeholder light">{{ $t('Select an item to inspect it.') }}</p>
            </div>
        </div>
    </div>
</template>

<script>
import DebugItemDetail from '../components/DebugItemDetail.vue';
import ActionBadge from '../components/ActionBadge.vue';
import ErrorPanel from '../components/ErrorPanel.vue';
import { requestErrorMessage } from '../lib/requestError.js';

// The counters shown above the item list, in order. Each doubles as a filter:
// clicking one restricts the list to that action; "seen" clears the filter.
// `good`/`bad` tint the value when non-zero (green wrote / red destructive).
const COUNTER_DEFS = [
    { key: 'itemsSeen',      action: null },
    { key: 'itemsCreated',   action: 'created',   good: true },
    { key: 'itemsUpdated',   action: 'updated',   good: true },
    { key: 'itemsUnchanged', action: 'unchanged' },
    { key: 'itemsSkipped',   action: 'skipped' },
    { key: 'itemsDeleted',   action: 'deleted',   bad: true },
    { key: 'itemsDisabled',  action: 'disabled',  bad: true },
];

const COUNTER_LABELS = {
    itemsSeen: 'seen', itemsCreated: 'created', itemsUpdated: 'updated',
    itemsUnchanged: 'unchanged', itemsSkipped: 'skipped', itemsDeleted: 'deleted', itemsDisabled: 'disabled',
};

/**
 * The run-log viewer — a split master/detail. The summary card's counters
 * filter the paginated item list on the left; selecting an item lazily fetches
 * its drill-down (the same row DebugItemDetail renders on the right). A live
 * run polls on an interval (Craft's queue-runner pattern) to append rows and
 * refresh counters, with a pause control in the page header.
 */
export default {
    name: 'LogApp',

    components: { DebugItemDetail, ActionBadge, ErrorPanel },

    props: {
        config: { type: Object, required: true },
    },

    data() {
        return {
            log: { ...this.config.log },
            items: [...(this.config.items || [])],
            itemTotal: this.config.itemTotal || 0,
            perPage: this.config.perPage || 25,
            loadingItems: false,
            isLive: !!this.config.isLive,
            // Single-select action filter (null = all), applied server-side.
            activeAction: null,
            currentPage: 1,
            // The selected item + a per-id cache of its fetched drill-down
            // ({ row } or { error }), so re-selecting never refetches.
            selectedId: null,
            rowCache: {},
            loadingRow: false,
            streamLabel: this.config.isLive ? this.$t('connecting…') : '',
            poller: null,
            paused: false,
            streamDone: false,
            actionTarget: '#influx-log-actions',
            hasActionTarget: false,
        };
    },

    computed: {
        linkUrl() {
            return this.config.linkId ? window.Craft.getCpUrl('influx/links/' + this.config.linkId) : null;
        },

        linkName() {
            return this.config.linkName || this.log.linkHandle;
        },

        endpointUrl() {
            return this.config.endpointUrl || null;
        },

        endpoints() {
            return this.config.endpoints || [];
        },

        resourceHtml() {
            return this.config.resourceHtml || null;
        },

        totalPages() {
            return Math.max(1, Math.ceil(this.itemTotal / this.perPage));
        },

        // The filterable action values (the counters minus "seen"), used to
        // validate a status read back from the URL.
        validActions() {
            return COUNTER_DEFS.map((d) => d.action).filter(Boolean);
        },

        counters() {
            return COUNTER_DEFS.map((d) => {
                const value = this.log[d.key] || 0;
                let tone = '';

                if (value === 0) tone = 'is-muted';
                else if (d.good) tone = 'is-good';
                else if (d.bad) tone = 'is-bad';

                return { label: this.$t(COUNTER_LABELS[d.key]), value, action: d.action, tone };
            });
        },

        activeLabel() {
            return this.activeAction ? this.$t(this.activeAction) : '';
        },

        selectedRow() {
            const entry = this.rowCache[this.selectedId];

            return entry ? entry.row || null : null;
        },

        selectedError() {
            const entry = this.rowCache[this.selectedId];

            return entry ? entry.error || '' : '';
        },

        // A run-info line for the summary bar: trigger, site/offset, started.
        metaLine() {
            const parts = [this.log.trigger];

            if (this.log.siteHandle) parts.push(this.$t('site {s}', { s: this.log.siteHandle }));
            if (this.log.offsetHandle) parts.push(this.$t('window {w}', { w: this.log.offsetHandle }));
            if (this.log.startedAt) parts.push(this.$t('started {d}', { d: this.log.startedAt }));

            return parts.join(' · ');
        },

        metaFinished() {
            if (this.log.duration) return this.$t('ran for {d}', { d: this.log.duration });
            if (this.isLive && !this.streamDone) return this.streamLabel || this.$t('running…');

            return '';
        },

        emptyLabel() {
            if (this.activeAction) return this.$t('No {label} items', { label: this.activeLabel });
            if (!this.itemTotal && !this.isLive) return this.$t('No data to process');

            return this.$t('No items');
        },
    },

    mounted() {
        this.hasActionTarget = !!document.querySelector(this.actionTarget);

        // A ?status=<action> in the URL pre-applies that counter's filter
        // (bookmarkable / survives reload).
        this.activeAction = this.statusFromUrl();

        if (this.isLive) {
            this.startPolling();
        } else if (this.activeAction) {
            // The bootstrap page is unfiltered, so fetch the filtered set.
            this.fetchPage(1);
        } else {
            // A finished run ships its first page in the bootstrap — no list
            // fetch needed; just open the first item's drill-down.
            this.autoSelect();
        }
    },

    beforeUnmount() {
        this.stopPolling();
    },

    methods: {
        setAction(action) {
            if (action === this.activeAction) {
                return;
            }

            this.activeAction = action;
            this.writeStatusToUrl();
            this.fetchPage(1);
        },

        // Read a valid status filter from the current URL (?status=…), or null.
        statusFromUrl() {
            const status = new URLSearchParams(window.location.search).get('status');

            return this.validActions.includes(status) ? status : null;
        },

        // Reflect the active filter in the URL without reloading, so it's
        // bookmarkable and survives a refresh. "seen"/all drops the param.
        writeStatusToUrl() {
            const url = new URL(window.location.href);

            if (this.activeAction) {
                url.searchParams.set('status', this.activeAction);
            } else {
                url.searchParams.delete('status');
            }

            window.history.replaceState({}, '', url.toString());
        },

        // Open an item's drill-down in the detail pane, fetching (and caching)
        // it the first time — a run can have many items, so detail is lazy.
        select(id) {
            this.selectedId = id;

            if (this.rowCache[id] || this.loadingRow) {
                return;
            }

            this.loadingRow = true;
            const url = this.config.itemUrlTemplate.replace('__ID__', encodeURIComponent(id));

            window.Craft.sendActionRequest('GET', url).then((response) => {
                const data = response.data || {};
                this.rowCache = {
                    ...this.rowCache,
                    [id]: data.row ? { row: data.row } : { error: data.message || this.$t('No content returned.') },
                };
            }).catch((err) => {
                this.rowCache = { ...this.rowCache, [id]: { error: requestErrorMessage(err, this.$t('Request failed.')) } };
            }).finally(() => {
                this.loadingRow = false;
            });
        },

        // Select the first item when the current selection is gone (or none) —
        // keeps the detail pane populated after a page/filter change or a poll.
        autoSelect() {
            if (this.items.some((i) => i.id === this.selectedId)) {
                return;
            }

            if (this.items.length) {
                this.select(this.items[0].id);
            } else {
                this.selectedId = null;
            }
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
        // counters. The pager, the counter filter, and the live poll all route
        // through here.
        fetchPage(page) {
            this.currentPage = Math.max(1, page);

            const params = new URLSearchParams();
            params.set('page', String(this.currentPage));

            // `status`, not `action`: Craft reserves the `action` query param
            // for controller-action routing, so `?action=…` 404s the request.
            if (this.activeAction) {
                params.set('status', this.activeAction);
            }

            const base = this.config.itemsUrl;
            const url = base + (base.includes('?') ? '&' : '?') + params.toString();

            this.loadingItems = true;

            window.Craft.sendActionRequest('GET', url).then((response) => {
                const data = response.data || {};
                this.items = data.items || [];
                this.itemTotal = data.total || 0;
                this.applyCounters(data);
                this.autoSelect();
            }).catch(() => {
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
            if (c.duration) this.log.duration = c.duration;
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
/* ---- Run summary -------------------------------------------------------- */
.influx-log-summary {
    overflow: hidden;
    margin-bottom: 14px;
    background: var(--white);
    border: 1px solid var(--hairline-color);
    border-radius: var(--large-border-radius);
}

.influx-log-summary-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 18px;
    background: var(--gray-050);
    border-bottom: 1px solid var(--hairline-color);
}

.influx-log-ident {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
    flex: 1 1 auto;
}

.influx-log-link {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-color);
}

.influx-log-endpoint-url {
    overflow: hidden;
    font-size: 12px;
    color: var(--medium-text-color);
    text-overflow: ellipsis;
    white-space: nowrap;
    word-break: break-all;
}

.influx-log-meta {
    display: flex;
    flex-direction: column;
    gap: 1px;
    flex: none;
    text-align: end;
    font-size: 12px;
    color: var(--medium-text-color);
}

/* Endpoint / resource sub-rows (multi-site or single-resource runs). */
.influx-log-endpoint {
    display: flex;
    flex-direction: column;
    gap: 5px;
    padding: 11px 18px;
    border-bottom: 1px solid var(--hairline-color);
}

.influx-log-endpoint .influx-log-endpoint-url { white-space: normal; }
.influx-log-endpoint-line { display: block; padding: 0; background: none; }

.influx-log-eyebrow {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--medium-text-color);
}

.influx-log-error {
    padding: 12px 18px;
    background: #fdecec;
    border-bottom: 1px solid var(--hairline-color);
    color: #8a1f1f;
}

/* ---- Counters (also the item filter) ------------------------------------ */
.influx-log-counters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
}

.influx-counter {
    display: flex;
    align-items: baseline;
    gap: 6px;
    padding: 9px 18px;
    border: 0;
    border-inline-end: 1px solid var(--hairline-color);
    background: transparent;
    text-align: start;
    cursor: pointer;
}

.influx-counter:hover { background: var(--gray-050); }

.influx-counter.is-active {
    background: hsl(208, 100%, 96%);
    box-shadow: inset 0 -2px 0 hsl(208, 100%, 42%);
}

.influx-counter-value {
    font-size: 15px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--text-color);
}

.influx-counter-value.is-muted { color: var(--light-text-color); }
.influx-counter-value.is-good { color: #087443; }
.influx-counter-value.is-bad { color: #8a1f1f; }

.influx-counter-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--medium-text-color);
}

/* ---- Split card (mirrors the Debug inspector's shell) ------------------- */
.influx-split {
    display: flex;
    align-items: stretch;
    /* The summary card sits above, so this offset is larger than the Debug
       view's. Constrains the panes to the viewport instead of the page. */
    max-height: calc(100vh - 360px);
    min-height: 300px;
    overflow: hidden;
    background: var(--white);
    border: 1px solid var(--hairline-color);
    border-radius: var(--large-border-radius);
}

.influx-split-list {
    display: flex;
    flex: 0 0 380px;
    flex-direction: column;
    min-height: 0;
    border-inline-end: 1px solid var(--hairline-color);
}

.influx-split-list-head {
    display: flex;
    flex: none;
    flex-direction: column;
    justify-content: center;
    gap: 3px;
    box-sizing: border-box;
    min-height: 62px;
    padding: 10px 14px;
    background: var(--gray-050);
    border-bottom: 1px solid var(--hairline-color);
}

.influx-split-list-title {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--medium-text-color);
}

.influx-split-list-title .light {
    margin-inline-start: 6px;
    font-weight: 400;
    text-transform: none;
    letter-spacing: 0;
}

.influx-split-list-hint {
    font-size: 11px;
    color: var(--light-text-color);
}

.influx-split-list-scroll {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
}

.influx-split-item {
    display: flex;
    flex-direction: column;
    gap: 3px;
    width: 100%;
    padding: 10px 14px;
    border: 0;
    border-bottom: 1px solid var(--hairline-color);
    background: transparent;
    text-align: start;
    cursor: pointer;
}

.influx-split-item:hover { background: var(--gray-050); }

.influx-split-item.is-selected {
    background: hsl(208, 100%, 96%);
    box-shadow: inset 3px 0 0 hsl(208, 100%, 42%);
}

.influx-split-item-top {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.influx-split-item-title {
    overflow: hidden;
    flex: 1 1 auto;
    min-width: 0;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-color);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.influx-split-item-badge { flex: none; }
.influx-split-item-sub { font-size: 11px; color: var(--light-text-color); }

/* "Saved despite a field error" count beside the action badge. */
.influx-log-haserror {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex: none;
    border-radius: 9px;
    padding: 1px 7px;
    font-size: 11px;
    font-weight: 600;
    background: #fde2e2;
    color: #8a1f1f;
    border: 1px solid #e7a3a3;
}

.influx-split-detail {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    overflow: hidden;
}

.influx-split-loading,
.influx-split-empty { padding: 16px 14px; }

.influx-split-loading { color: var(--light-text-color); }
.influx-split-placeholder { padding: 18px; }

.influx-split-pager {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 8px 14px;
    border-top: 1px solid var(--hairline-color);
    background: var(--gray-050);
    font-size: 12px;
}
</style>
