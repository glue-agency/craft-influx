<template>
    <mapping-group-card
        variant="debug"
        :default-expanded="false"
        :data-action="item.action"
        @toggle="onToggle"
    >
        <template #header>
            <span class="chevron" aria-hidden="true">▼</span>
            <span class="influx-log-id light">#{{ item.id }}</span>
            <span v-if="item.elementHtml" class="influx-log-element" v-html="item.elementHtml" @click.stop></span>
            <span v-else class="influx-log-element light">—</span>
            <span v-if="item.message" class="influx-log-message light">{{ item.message }}</span>
            <span class="influx-log-tag" :class="color">{{ item.action }}</span>
            <span
                v-if="hasFieldErrors"
                class="influx-log-haserror"
                data-icon="alert"
                :title="errorTitle"
            >{{ item.errorCount }}</span>
        </template>

        <p v-if="loading" class="light influx-log-detail-msg"><span class="spinner"></span> {{ $t('Loading…') }}</p>
        <debug-fields v-else-if="row" :row="row" :show-message="false" />
        <p v-else-if="errorMsg" class="error influx-log-detail-msg">{{ errorMsg }}</p>
    </mapping-group-card>
</template>

<script>
import MappingGroupCard from '../components/MappingGroupCard.vue';
import DebugFields from '../components/DebugFields.vue';
import { actionColor } from '../lib/actionColors.js';

/**
 * One run-log item, presented as a collapsed debug-style card: the header
 * always shows the element chip, the committed action tag and the item's
 * message; expanding it fetches the item's full debug row (the same JSON
 * DebugItem renders) and reveals the shared DebugFields body beneath. Element
 * chips are server-rendered Craft markup, injected verbatim.
 */
export default {
    name: 'LogItem',

    components: { MappingGroupCard, DebugFields },

    props: {
        item: { type: Object, required: true },
        // URL template with an `__ID__` placeholder (influx/logs/items/__ID__).
        itemUrlTemplate: { type: String, required: true },
    },

    data() {
        return {
            loading: false,
            row: null,
            errorMsg: '',
        };
    },

    computed: {
        color() {
            return actionColor(this.item.action);
        },

        // A created/updated item can still carry field errors (a field failed
        // but the element committed) — flag it, since its action tag reads as a
        // clean success. An `error` item is already flagged by its tag.
        hasFieldErrors() {
            return this.item.errorCount > 0 && this.item.action !== 'error';
        },

        errorTitle() {
            return this.$t('Saved despite {n} field error(s)', { n: this.item.errorCount });
        },
    },

    methods: {
        // Lazy-load the field comparison the first time the card is opened —
        // a run can have many items, so we never fetch detail we don't show.
        onToggle(expanded) {
            if (expanded && this.row === null && ! this.loading) {
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
.influx-log-id {
    flex: none;
    font-size: 12px;
    font-weight: normal;
    font-variant-numeric: tabular-nums;
}

.influx-log-element { font-size: 13px; }

/* Message sits between the element and the right-pinned action tag, taking the
   slack and truncating so a long reason never wraps the header. */
.influx-log-message {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-size: 12px;
    font-weight: normal;
}

/* Same pill palette as the debug inspector's action tag. */
.influx-log-tag {
    margin-left: auto;
    flex: none;
    border-radius: 9px;
    padding: 2px 9px;
    font-size: 11px;
    font-weight: 600;
}
.influx-log-tag.live { background: #d6f1de; color: #064f1f; border: 1px solid #7fcb95; }
.influx-log-tag.pending { background: rgba(0, 0, 0, .08); color: #555; }
.influx-log-tag.expired { background: #fde2e2; color: #8a1f1f; border: 1px solid #e7a3a3; }

/* "Saved but a field errored" — a red count badge beside the action tag, so a
   green created/updated tag doesn't read as a fully clean run. */
.influx-log-haserror {
    flex: none;
    margin-left: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border-radius: 9px;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 600;
    background: #fde2e2;
    color: #8a1f1f;
    border: 1px solid #e7a3a3;
}

.influx-log-detail-msg { padding: 8px 12px; margin: 0; }
</style>
