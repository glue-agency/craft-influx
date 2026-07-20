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

        <!-- Controls. A form so Enter in any field re-inspects (and the unit
             test can drive a submit); the visible trigger is the header button
             above. The link field navigates rather than re-inspecting. -->
        <form class="toolbar flex flex-nowrap influx-debug-toolbar" @submit.prevent="inspect">
            <div v-if="links.length" class="influx-debug-field">
                <label>{{ $t('Link') }}</label>
                <div class="select">
                    <select :value="linkHandle" @change="goToLink">
                        <option v-for="l in links" :key="l.handle" :value="l.handle">{{ l.name }}</option>
                    </select>
                </div>
            </div>

            <div class="influx-debug-field">
                <label>{{ $t('Site') }}</label>
                <span v-if="!sites.length" class="influx-debug-static">{{ $t('Default endpoint') }}</span>
                <div v-else class="select">
                    <select v-model="site">
                        <option v-for="s in sites" :key="s.handle" :value="s.handle">{{ s.name }}</option>
                    </select>
                </div>
            </div>

            <div v-if="offsetHandles.length" class="influx-debug-field">
                <label>{{ $t('Sliding window') }}</label>
                <div class="select">
                    <select v-model="offset">
                        <option value="">{{ $t('Whole feed') }}</option>
                        <option v-for="h in offsetHandles" :key="h" :value="h">{{ h }}</option>
                    </select>
                </div>
            </div>

            <div class="influx-debug-field">
                <label>{{ $t('Limit') }}</label>
                <div class="influx-debug-limit-row">
                    <input v-model.number="limit" type="number" min="1" max="500" class="text influx-debug-limit">
                    <span v-if="totalAvailable != null" class="influx-debug-of">{{ $t('of {n}', { n: totalAvailable }) }}</span>
                </div>
            </div>

            <div class="flex-grow"></div>
        </form>

        <error-panel v-if="meta && meta.error" class="influx-feed-error influx-debug-error" :error="meta.error" />

        <!-- Split inspector: browse the fetched items left, drill into the
             selected one right. -->
        <div v-else class="influx-split">
            <div class="influx-split-list">
                <div class="influx-split-list-head">
                    <span class="influx-split-list-title">
                        {{ $t('Items') }}
                        <span class="light">{{ $t('{n} fetched', { n: items.length }) }}</span>
                    </span>
                    <code v-if="meta && meta.url" class="influx-split-endpoint" :title="meta.url">{{ meta.url }}</code>
                </div>

                <div class="influx-split-list-scroll">
                    <p v-if="loading" class="influx-split-loading"><span class="spinner"></span> {{ $t('Fetching feed…') }}</p>

                    <template v-else>
                        <button
                            v-for="(item, i) in items"
                            :key="`${inspectRun}:${i}`"
                            type="button"
                            class="influx-split-item"
                            :class="{ 'is-selected': i === selectedIndex }"
                            @click="selectedIndex = i"
                        >
                            <span class="influx-split-item-top">
                                <span class="influx-split-item-title">{{ itemTitle(item) }}</span>
                                <action-badge :action="item.action" class="influx-split-item-badge" />
                            </span>
                            <span class="influx-split-item-sub">{{ changesSummary(item) }}</span>
                        </button>

                        <p v-if="!items.length" class="influx-split-empty light">{{ $t('No items on this page.') }}</p>
                    </template>
                </div>
            </div>

            <div class="influx-split-detail">
                <debug-item-detail
                    v-if="selectedItem"
                    :key="`${inspectRun}:${selectedIndex}`"
                    :row="selectedItem"
                    :match-attribute="meta && meta.matchAttribute || ''"
                />
                <p v-else-if="!loading" class="influx-split-placeholder light">{{ $t('Select an item to inspect it.') }}</p>
            </div>
        </div>
    </div>
</template>

<script>
import DebugItemDetail from '../components/DebugItemDetail.vue';
import ActionBadge from '../components/ActionBadge.vue';
import ErrorPanel from '../components/ErrorPanel.vue';
import { requestErrorMessage } from '../lib/requestError.js';

/**
 * The debug inspector page — a split master/detail view. The toolbar owns the
 * link/site/window/limit controls; a single JSON fetch (the inspector only
 * ever reads the first page) fills the left item list, and the selected item's
 * drill-down renders on the right via DebugItemDetail. Inspecting again
 * re-fetches in place; changing the link navigates to that link's page.
 */
export default {
    name: 'DebugApp',

    components: { DebugItemDetail, ActionBadge, ErrorPanel },

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
            links: this.config.links || [],
            linkHandle: this.config.linkHandle || null,
            meta: null,
            items: [],
            selectedIndex: 0,
            loading: false,
            // Craft renders an empty action-buttons slot (see debug.twig) that
            // we Teleport the Inspect button into. Resolved in mounted(); until
            // then the Teleport is disabled and the button renders in place.
            actionTarget: '#influx-debug-actions',
            hasActionTarget: false,
            // Monotonic per-inspect counter: guards against superseded
            // responses (rapid re-clicks) AND keys the list rows + detail so a
            // fresh inspect remounts them rather than patching stale state in.
            inspectRun: 0,
        };
    },

    computed: {
        selectedItem() {
            return this.items[this.selectedIndex] || null;
        },

        // The feed's reported total (or items-on-page) for the "of N" hint next
        // to the limit — null when the feed reports neither.
        totalAvailable() {
            if (!this.meta) {
                return null;
            }

            return this.meta.totalCount != null ? this.meta.totalCount : (this.meta.itemsOnPage ?? null);
        },
    },

    mounted() {
        this.hasActionTarget = !!document.querySelector(this.actionTarget);
        this.inspect();
    },

    methods: {
        // Left-list label: the resolved element's title, else the match value,
        // else blank (a would-skip item with no match value).
        itemTitle(item) {
            return (item.element && item.element.title) || item.matchValue || '';
        },

        // One-line summary of what a real run would do to this item.
        changesSummary(item) {
            if (item.action === 'would-create') {
                return this.$t('New element');
            }

            if (item.action === 'would-skip') {
                return item.message || this.$t('Skipped');
            }

            const changed = (item.mappings || []).filter((m) => m.changed).length;

            if (changed === 0) {
                return this.$t('No changes');
            }

            return changed === 1 ? this.$t('1 change') : this.$t('{n} changes', { n: changed });
        },

        goToLink(e) {
            const link = this.links.find((l) => l.handle === e.target.value);

            if (link) {
                window.location.href = link.url;
            }
        },

        inspect() {
            this.meta = null;
            this.items = [];
            this.selectedIndex = 0;
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
                this.selectedIndex = 0;
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
/* ---- Toolbar ------------------------------------------------------------ */
.influx-debug-toolbar {
    align-items: flex-end;
    margin-bottom: 14px;
}

.influx-debug-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

/* Give the selects a consistent minimum so they don't collapse to odd widths
   when their options are short (the narrow Limit field sizes to its input). */
.influx-debug-field .select { min-width: 160px; }
.influx-debug-field .select select { width: 100%; }

.influx-debug-field label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--medium-text-color);
}

.influx-debug-static {
    height: var(--input-height, 34px);
    display: flex;
    align-items: center;
    font-size: 13px;
    color: var(--light-text-color);
}

.influx-debug-limit-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.influx-debug-limit { width: 72px; }

.influx-debug-of {
    font-size: 13px;
    color: var(--light-text-color);
}

/* ---- Split card --------------------------------------------------------- */
.influx-split {
    display: flex;
    align-items: stretch;
    /* Constrain the card to the viewport (minus the CP header / title /
       toolbar chrome) so each pane scrolls internally instead of growing the
       page. Falls back to a sensible minimum on short viewports. */
    max-height: calc(100vh - 240px);
    min-height: 320px;
    overflow: hidden;
    background: var(--white);
    border: 1px solid var(--hairline-color);
    border-radius: var(--large-border-radius);
}

.influx-split-list {
    display: flex;
    /* Matches the log detail's items column — at most a quarter of the split. */
    flex: 1 1 0;
    max-width: 25%;
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

/* Only the item list scrolls; the header above stays put. */
.influx-split-list-scroll {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
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

.influx-split-endpoint {
    overflow: hidden;
    padding: 0;
    background: none;
    font-size: 11px;
    color: var(--light-text-color);
    text-overflow: ellipsis;
    white-space: nowrap;
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
    font-size: 13px;
    font-weight: 600;
    color: var(--text-color);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.influx-split-item-badge { margin-left: auto; flex: none; }

.influx-split-item-sub {
    font-size: 11px;
    color: var(--light-text-color);
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

.influx-debug-error {
    padding: 14px 18px;
    color: var(--error-color);
}
</style>
