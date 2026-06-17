<template>
    <tr class="influx-log-row" :data-action="item.action">
        <td>
            <button type="button" class="btn small influx-log-inspect" data-icon="search" :title="$t('Inspect')" @click="toggle">
                {{ item.id }}
            </button>
        </td>
        <td><span class="status" :class="color"></span> <code>{{ item.action }}</code></td>
        <td><code>{{ item.matchValue || '—' }}</code></td>
        <td>
            <span v-if="item.elementHtml" v-html="item.elementHtml"></span>
            <span v-else class="light">—</span>
        </td>
        <td>{{ item.message || '' }}</td>
    </tr>
    <tr v-if="expanded" class="influx-log-detail">
        <td :colspan="colspan">
            <p v-if="loading" class="light influx-log-detail-msg"><span class="spinner"></span> {{ $t('Loading…') }}</p>
            <debug-item v-else-if="row" :row="row" />
            <p v-else class="error influx-log-detail-msg">{{ errorMsg }}</p>
        </td>
    </tr>
</template>

<script>
import DebugItem from '../components/DebugItem.vue';
import { actionColor } from '../lib/actionColors.js';

/**
 * One run-log item: a table row plus an on-demand inspect drill-down. Clicking
 * the id button fetches the item's full debug row (the same JSON DebugItem
 * renders) and expands a DebugItem card beneath the row. Element chips are
 * rendered server-side (Craft markup) and injected verbatim.
 */
export default {
    name: 'LogItem',

    components: { DebugItem },

    props: {
        item: { type: Object, required: true },
        // URL template with an `__ID__` placeholder (influx/logs/items/__ID__).
        itemUrlTemplate: { type: String, required: true },
        colspan: { type: Number, default: 5 },
    },

    data() {
        return {
            expanded: false,
            loading: false,
            row: null,
            errorMsg: '',
        };
    },

    computed: {
        color() {
            return actionColor(this.item.action);
        },
    },

    methods: {
        toggle() {
            if (this.expanded) {
                this.expanded = false;

                return;
            }

            this.expanded = true;

            if (this.row === null && ! this.loading) {
                this.fetch();
            }
        },

        fetch() {
            this.loading = true;
            this.errorMsg = '';
            const url = this.itemUrlTemplate.replace('__ID__', encodeURIComponent(this.item.id));

            window.Craft.sendActionRequest('GET', url).then((response) => {
                const data = response.data || {};
                this.row = data.row || null;

                if (! this.row) {
                    this.errorMsg = data.message || this.$t('No content returned.');
                }
            }).catch((err) => {
                this.errorMsg = err?.response?.data?.message || err?.message || this.$t('Request failed.');
            }).finally(() => {
                this.loading = false;
            });
        },
    },
};
</script>

<style scoped>
.influx-log-detail-msg { padding: 8px; margin: 0; }
</style>
