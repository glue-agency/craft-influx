<template>
    <div class="influx-drill">
        <!-- The way back out, carrying the parent's own identity and action so a
             drilled reader never loses the anchor they came from. -->
        <button type="button" class="influx-drill-back" @click="$emit('back')">
            <span class="influx-drill-back-icon" data-icon="leftangle" aria-hidden="true"></span>

            <span class="influx-drill-back-text">
                <span class="influx-drill-back-title" v-text="parentTitle"></span>
                <span class="influx-drill-back-sub" v-text="$t('Back to parent')"></span>
            </span>

            <v-action-badge :action="parentAction" class="influx-drill-back-badge" />
        </button>

        <!-- Which field we're inside, the feed node it read from, and how many
             children it produced. -->
        <div class="influx-drill-strip">
            <span class="influx-drill-strip-label" v-text="fieldLabel"></span>
            <code v-if="fieldNode" class="influx-drill-strip-node" v-text="fieldNode"></code>
            <span class="influx-drill-strip-count" v-text="countSummary(childrenType, children.length)"></span>
        </div>

        <div class="influx-drill-scroll">
            <button
                v-for="(child, i) in children"
                :key="i"
                type="button"
                class="influx-drill-item"
                :class="{ 'is-selected': i === selectedIndex }"
                @click="$emit('select', i)"
            >
                <span class="influx-drill-item-title" v-text="label(child, i)"></span>
                <v-action-badge :action="child.action" class="influx-drill-item-badge" />
            </button>
        </div>
    </div>
</template>

<style scoped>
.influx-drill {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
}

/* Sized to the item-list header it replaces (.influx-split-list-head, 62px), so
   drilling doesn't shift the pane beside it. Transparent with a grey hover, the
   same affordance the item rows use. */
.influx-drill-back {
    display: flex;
    flex: none;
    align-items: center;
    gap: 8px;
    box-sizing: border-box;
    width: 100%;
    min-height: var(--influx-split-head-height);
    padding: 10px 14px;
    border: 0;
    border-bottom: 1px solid var(--hairline-color);
    background: transparent;
    text-align: start;
    cursor: pointer;
}

.influx-drill-back:hover { background: var(--gray-050); }

.influx-drill-back-icon {
    flex: none;
    color: var(--light-text-color);
}

.influx-drill-back-text {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.influx-drill-back-title {
    overflow: hidden;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-color);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.influx-drill-back-sub {
    font-size: 11px;
    color: var(--light-text-color);
}

.influx-drill-back-badge { flex: none; }

/* Sub-strip: the same tinted band DebugItemDetail's column headings use, so the
   two panes' second rows line up as one header band. */
.influx-drill-strip {
    display: flex;
    flex: none;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    background: #f3f7fc;
    border-bottom: 1px solid rgba(0, 0, 0, .06);
}

.influx-drill-strip-label {
    flex: none;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--medium-text-color);
}

.influx-drill-strip-node {
    overflow: hidden;
    min-width: 0;
    padding: 0;
    background: none;
    font-size: 11px;
    color: #9aa5b1;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.influx-drill-strip-count {
    flex: none;
    margin-inline-start: auto;
    font-size: 11px;
    color: var(--light-text-color);
}

/* Only the child rows scroll; the back header and sub-strip above stay put. */
.influx-drill-scroll {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    scrollbar-gutter: stable;
}
/* Craft paints scrollbar tracks grey (--gray-050, 11px wide). In a pane whose
   inline-end edge IS the split's seam that reads as a second line beside the
   hairline — a striped border rather than one edge. The thumb alone says as
   much, and `scrollbar-gutter: stable` above keeps the rows from shifting when
   it appears. */
.influx-drill-scroll::-webkit-scrollbar-track {
    background: transparent;
}


/* One line, like the item list's own rows — the label and the badge, nothing
   under it. */
.influx-drill-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 10px 14px;
    border: 0;
    border-bottom: 1px solid var(--hairline-color);
    background: transparent;
    text-align: start;
    cursor: pointer;
}

.influx-drill-item:hover { background: var(--gray-050); }

.influx-drill-item.is-selected {
    background: hsl(208, 100%, 96%);
    box-shadow: inset 3px 0 0 hsl(208, 100%, 42%);
}

.influx-drill-item-title {
    overflow: hidden;
    flex: 1 1 auto;
    min-width: 0;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-color);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.influx-drill-item-badge { flex: none; }
</style>

<script>
import ActionBadge from './ActionBadge.vue';

/**
 * The left pane once a reader drills into a mapping row that nests elements —
 * it replaces the item list for as long as they're inside. A back header out to
 * the parent item, a strip naming the field they came in through, then one row
 * per child (its label and an action badge), with the selected one rendered by
 * DebugItemDetail on the right.
 *
 * A child's label is its own title — a Matrix block's title, a related element's
 * UI label — and, for a child that has none (a block the sync would add, whose
 * feed maps no title), its zero-padded ordinal instead.
 *
 * A child row carries no note line under it, exactly like the item rows it
 * stands in for: what a run would do to the child is its badge's job, and the
 * field-level detail is the right pane's.
 *
 * Shared by both split inspectors, same as DebugItemDetail: the `children`
 * payload is identical in the live dry-run and the run log, so the pane is too.
 * The count-summary literals are duplicated from DebugItemDetail on purpose —
 * the PHP catalogue is scanned per component file, so each file states its own
 * strings.
 */
export default {
    name: 'DrillList',

    emits: ['back', 'select'],

    props: {
        // The item (or parent child) drilled out of: its header title and
        // action, echoed in the back header.
        parentTitle: { type: String, default: '' },
        parentAction: { type: String, default: '' },
        // The mapping row drilled into: its field label, the feed node it read
        // from (blank for a node-less mapping) and the kind of children it
        // produced ('blocks', 'entries', …).
        fieldLabel: { type: String, default: '' },
        fieldNode: { type: String, default: '' },
        childrenType: { type: String, default: '' },
        children: { type: Array, default: () => [] },
        selectedIndex: { type: Number, default: 0 },
    },

    methods: {
        // What the row is called: the child's own title, else its zero-padded
        // ordinal — padded so single- and double-digit fallbacks line up with
        // each other.
        label(child, i) {
            return child.title || String(i + 1).padStart(2, '0');
        },

        // How many children are in here, named by kind. One literal per noun so
        // the strings are scannable for the translation catalogue.
        countSummary(type, n) {
            switch (type) {
                case 'blocks':
                    return this.$t('{n} blocks', { n });
                case 'assets':
                    return this.$t('{n} assets', { n });
                case 'entries':
                    return this.$t('{n} entries', { n });
                case 'users':
                    return this.$t('{n} users', { n });
                case 'categories':
                    return this.$t('{n} categories', { n });
                case 'tags':
                    return this.$t('{n} tags', { n });
                default:
                    return this.$t('{n} elements', { n });
            }
        },
    },

    components: { 'v-action-badge': ActionBadge },
};
</script>
