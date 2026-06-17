<template>
    <div class="influx-log-app">
        <table class="data fullwidth fixed-layout">
            <tbody>
                <tr>
                    <th>{{ $t('Link') }}</th>
                    <td>
                        <a v-if="linkUrl" :href="linkUrl"><code>{{ log.linkHandle }}</code></a>
                        <code v-else>{{ log.linkHandle }}</code>
                    </td>
                </tr>
                <tr><th>{{ $t('Trigger') }}</th><td>{{ log.trigger }}</td></tr>
                <tr>
                    <th>{{ $t('Status') }}</th>
                    <td><span class="status" :class="statusClass"></span> <span>{{ log.status }}</span></td>
                </tr>
                <tr><th>{{ $t('Started') }}</th><td>{{ log.startedAt }}</td></tr>
                <tr><th>{{ $t('Finished') }}</th><td>{{ log.finishedAt || '—' }}</td></tr>
                <tr v-if="log.error"><th>{{ $t('Error') }}</th><td><pre class="error">{{ log.error }}</pre></td></tr>
            </tbody>
        </table>

        <h2>{{ $t('Counters') }}</h2>
        <table class="data fullwidth fixed-layout">
            <tbody>
                <tr><th>{{ $t('Seen') }}</th><td>{{ log.itemsSeen }}</td></tr>
                <tr><th>{{ $t('Created') }}</th><td>{{ log.itemsCreated }}</td></tr>
                <tr><th>{{ $t('Updated') }}</th><td>{{ log.itemsUpdated }}</td></tr>
                <tr><th>{{ $t('Unchanged') }}</th><td>{{ log.itemsUnchanged }}</td></tr>
                <tr><th>{{ $t('Skipped') }}</th><td>{{ log.itemsSkipped }}</td></tr>
                <tr><th>{{ $t('Deleted / disabled') }}</th><td>{{ log.itemsDeleted }}</td></tr>
            </tbody>
        </table>

        <h2>
            {{ $t('Items') }}
            <span v-if="streamLabel" class="light">— {{ streamLabel }}</span>
        </h2>

        <!-- Native Craft checkbox group (matches the builder's processing-action
             filters): each action toggles its own visibility; the status dot
             carries the action's colour, the count rides along in the label. -->
        <ul class="influx-log-filters">
            <li v-for="f in filterDefs" :key="f.action">
                <input
                    type="checkbox"
                    class="checkbox"
                    :id="`influx-log-filter-${f.action}`"
                    :checked="activeFilters[f.action]"
                    @change="toggleFilter(f.action)"
                >
                <label :for="`influx-log-filter-${f.action}`">
                    <span class="status" :class="f.color"></span>{{ $t(f.action) }} <span class="light">({{ filterCounts[f.action] || 0 }})</span>
                </label>
            </li>
        </ul>

        <div class="influx-log-items">
            <log-item
                v-for="item in visibleItems"
                :key="item.id"
                :item="item"
                :item-url-template="config.itemUrlTemplate"
            />
        </div>

        <p v-if="!items.length && !isLive" class="light">{{ $t('No per-item rows.') }}</p>
    </div>
</template>

<script>
import LogItem from './LogItem.vue';

const FILTER_DEFS = [
    { action: 'created',   color: 'live' },
    { action: 'updated',   color: 'live' },
    { action: 'unchanged', color: 'pending' },
    { action: 'skipped',   color: 'pending' },
    { action: 'deleted',   color: 'expired' },
    { action: 'disabled',  color: 'expired' },
];

/**
 * The run-log viewer: the summary + counters tables, the per-action filter
 * bar, the items table (LogItem rows with inspect drill-downs), and — for a
 * still-running log — the live SSE that appends rows and refreshes counters.
 */
export default {
    name: 'LogApp',

    components: { LogItem },

    props: {
        config: { type: Object, required: true },
    },

    data() {
        const filterDefs = FILTER_DEFS;
        const activeFilters = {};
        filterDefs.forEach((f) => { activeFilters[f.action] = true; });

        return {
            log: { ...this.config.log },
            items: [...(this.config.items || [])],
            isLive: !!this.config.isLive,
            filterDefs,
            activeFilters,
            streamLabel: this.config.isLive ? this.$t('connecting…') : '',
            es: null,
        };
    },

    computed: {
        linkUrl() {
            return this.config.linkId ? window.Craft.getCpUrl('influx/links/' + this.config.linkId) : null;
        },

        statusClass() {
            if (this.log.status === 'ok') return 'live';
            if (this.log.status === 'error') return 'expired';

            return 'pending';
        },

        filterCounts() {
            const counts = {};
            this.filterDefs.forEach((f) => { counts[f.action] = 0; });
            this.items.forEach((it) => {
                if (counts[it.action] != null) counts[it.action]++;
            });

            return counts;
        },

        visibleItems() {
            // Newest first. filter() returns a fresh array, so reverse() here
            // doesn't mutate the source order the stream appends to.
            return this.items.filter((it) => {
                const filterable = this.activeFilters[it.action] !== undefined;

                return ! filterable || this.activeFilters[it.action];
            }).reverse();
        },
    },

    mounted() {
        if (this.isLive) {
            this.openStream();
        }
    },

    beforeUnmount() {
        this.closeStream();
    },

    methods: {
        toggleFilter(action) {
            this.activeFilters[action] = ! this.activeFilters[action];
        },

        openStream() {
            const lastId = this.items.length ? this.items[this.items.length - 1].id : 0;
            const base = this.config.streamUrl;
            const url = base + (base.includes('?') ? '&' : '?') + 'lastId=' + encodeURIComponent(lastId);

            const es = new EventSource(url, { withCredentials: true });
            this.es = es;
            this.streamLabel = this.$t('streaming…');

            es.addEventListener('item', (e) => {
                try {
                    const d = JSON.parse(e.data);
                    if (! this.items.some((it) => it.id === d.id)) {
                        this.items.push(d);
                    }
                } catch (err) { /* skip bad frame */ }
            });
            es.addEventListener('counters', (e) => {
                try {
                    const d = JSON.parse(e.data);
                    ['itemsSeen', 'itemsCreated', 'itemsUpdated', 'itemsUnchanged', 'itemsSkipped', 'itemsDeleted'].forEach((k) => {
                        if (d[k] !== undefined) this.log[k] = d[k];
                    });
                    if (d.status) this.log.status = d.status;
                    if (d.finishedAt) this.log.finishedAt = d.finishedAt;
                    if (d.error) this.log.error = d.error;
                } catch (err) { /* ignore */ }
            });
            es.addEventListener('done', () => {
                this.streamLabel = '';
                this.closeStream();
            });
            es.addEventListener('error', () => {
                this.streamLabel = this.$t('stream closed');
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
.influx-log-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 18px;
    margin: 0 0 14px;
    padding: 0;
    list-style: none;
}
/* Anchor the visually-hidden checkbox input to its own row. */
.influx-log-filters li { position: relative; }
.influx-log-filters .status { margin-right: 5px; }
</style>
