<template>
    <mapping-group-card variant="debug">
        <template #header>
            <span class="chevron" aria-hidden="true">▼</span>
            <span
                v-if="row.element && row.element.chipHtml"
                class="influx-debug-item-element"
                v-html="row.element.chipHtml"
                @click.stop
            ></span>
            <span v-else class="influx-debug-item-element">
                <a v-if="row.element" :href="row.element.cpEditUrl" target="_blank" rel="noopener" @click.stop>
                    {{ row.element.title }}
                    <span class="light">#{{ row.element.id }}</span>
                </a>
                <span v-else-if="row.action === 'would-create'" class="influx-debug-ghost-chip">
                    <span class="influx-debug-ghost-chip-glyph" aria-hidden="true">+</span>
                    {{ $t('New element') }}
                </span>
            </span>

            <span class="influx-debug-tag" :class="color">{{ row.action }}</span>
        </template>

        <debug-fields :row="row" />
    </mapping-group-card>
</template>

<script>
import MappingGroupCard from './MappingGroupCard.vue';
import DebugFields from './DebugFields.vue';
import { actionColor } from '../lib/actionColors.js';

/**
 * One inspected item — the dry-run/log drill-down card. Renders the JSON `row`
 * produced by DebugService::debugItem() inside the shared mapping-group card:
 * a header (element chip + status-coloured action tag) over the shared
 * DebugFields body (message/error notes, field-comparison grid, raw JSON).
 * Used by the debug inspector; the log viewer's LogItem reuses DebugFields
 * directly under its own header.
 */
export default {
    name: 'DebugItem',

    components: { MappingGroupCard, DebugFields },

    props: {
        row: { type: Object, required: true },
    },

    computed: {
        color() {
            return actionColor(this.row.action);
        },
    },
};
</script>

<style scoped>
/* Header chrome only — the field-comparison body lives in DebugFields. */
.influx-debug-tag {
    margin-left: auto;
    border-radius: 9px;
    padding: 2px 9px;
    font-size: 11px;
    font-weight: 600;
}
.influx-debug-tag.live { background: #d6f1de; color: #064f1f; border: 1px solid #7fcb95; }
.influx-debug-tag.pending { background: rgba(0, 0, 0, .08); color: #555; }
.influx-debug-tag.expired { background: #fde2e2; color: #8a1f1f; border: 1px solid #e7a3a3; }

.influx-debug-item-element { font-size: 13px; }

/* "Ghost chip" for the would-create state: a muted, dashed-outline pill that
   mirrors a Craft element chip's rounded shape, in the subtle green/create
   palette that echoes the would-create action tag — signalling an element that
   doesn't exist yet without competing with the real chips beside it. */
.influx-debug-ghost-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border: 1px dashed #7fcb95;
    border-radius: 9px;
    padding: 1px 9px 1px 7px;
    font-size: 12px;
    line-height: 18px;
    color: #064f1f;
    background: rgba(214, 241, 222, .35);
}
.influx-debug-ghost-chip-glyph {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 13px;
    height: 13px;
    border-radius: 50%;
    background: #45a35e;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}
</style>
