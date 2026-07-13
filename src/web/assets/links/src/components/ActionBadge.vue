<template>
    <span class="influx-action-badge" :class="resolvedColor"><slot>{{ action }}</slot></span>
</template>

<script>
import { actionColor } from '../lib/actionColors.js';

/**
 * The status-coloured pill for a sync action or run status — the split
 * inspectors' item + detail badges (DebugApp's list, DebugItemDetail's header,
 * LogApp's items) and LogApp's run-status pill. Owns the pill chrome and the
 * live/pending/expired palette once; consumers pass a fallthrough class for
 * positioning tweaks.
 */
export default {
    name: 'ActionBadge',

    props: {
        // A sync action ('created', 'would-skip', …) — the default badge text,
        // mapped to its palette colour via lib/actionColors.js.
        action: { type: String, default: '' },
        // Explicit palette override ('live' | 'pending' | 'expired') for
        // consumers whose colour isn't derived from an action here (the run
        // status, DebugApp's precomputed tag list).
        color: { type: String, default: '' },
    },

    computed: {
        resolvedColor() {
            return this.color || actionColor(this.action);
        },
    },
};
</script>

<style scoped>
.influx-action-badge {
    border-radius: 9px;
    padding: 2px 9px;
    font-size: 11px;
    font-weight: 600;
}

/* The one copy of the action-tag palette (green/grey/red = Craft's
   live/pending/expired status colours). */
.influx-action-badge.live { background: #d6f1de; color: #064f1f; border: 1px solid #7fcb95; }
.influx-action-badge.pending { background: rgba(0, 0, 0, .08); color: #555; }
.influx-action-badge.expired { background: #fde2e2; color: #8a1f1f; border: 1px solid #e7a3a3; }
</style>
