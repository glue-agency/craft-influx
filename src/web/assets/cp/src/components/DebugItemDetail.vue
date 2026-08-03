<template>
    <div class="influx-detail">
        <!-- Header: what this item resolves to, the dry-run action, and the
             Parsed / Raw JSON switch. -->
        <div class="influx-detail-head">
            <span
                v-if="row.element && row.element.chipHtml"
                class="influx-detail-chip"
                v-html="row.element.chipHtml"
            ></span>
            <span v-else class="influx-detail-title" v-text="title"></span>

            <v-action-badge :action="row.action" class="influx-detail-badge" />

            <div v-if="! drilled" class="btngroup influx-detail-toggle">
                <button
                    type="button"
                    class="btn"
                    :class="{ active: view === 'parsed' }"
                    :aria-pressed="view === 'parsed'"
                    @click="view = 'parsed'"
                    v-text="$t('Parsed')"
                />
                <button
                    type="button"
                    class="btn"
                    :class="{ active: view === 'raw' }"
                    :aria-pressed="view === 'raw'"
                    @click="view = 'raw'"
                    v-text="$t('Raw JSON')"
                />
            </div>
        </div>

        <!-- Parsed: incoming vs current (or, in the log context, vs parsed),
             one row per mapped field. -->
        <div v-if="activeView === 'parsed'" class="influx-detail-body">
            <p v-if="row.message" class="influx-debug-item-note" v-text="row.message"></p>
            <pre v-if="row.error" class="influx-debug-item-error" v-text="row.error"></pre>

            <template v-if="row.mappings && row.mappings.length">
                <!-- Three labelled columns plus an unlabelled gutter for a drill
                     row's chevron; the "did it change" signal is the row's green
                     tint (data-changed), not a column. Per-row status is carried
                     by the pills beside the field label, each opening a popover
                     with the full "why" on click. -->
                <div class="influx-detail-headings">
                    <div v-text="$t('Field')"></div>
                    <div v-text="$t('Incoming')"></div>
                    <div v-text="context === 'log' ? $t('Parsed') : $t('Current')"></div>
                </div>

                <template v-for="m in row.mappings" :key="m.handle">
                    <!-- A row whose value nests elements is the way into them:
                         its two value cells give way to a count + state summary,
                         and the whole row is the affordance (hence role/tabindex
                         and the gutter chevron). -->
                    <div
                        class="influx-detail-row"
                        :class="{ 'influx-detail-row--drill': hasChildren(m) }"
                        :data-changed="! hasChildren(m) && m.changed ? 'true' : null"
                        :data-drill-state="drillState(m)"
                        :role="hasChildren(m) ? 'button' : null"
                        :tabindex="hasChildren(m) ? 0 : null"
                        @click="drill(m)"
                        @keyup.enter="drill(m)"
                    >
                        <div class="influx-detail-field">
                            <span class="influx-detail-field-name">
                                {{ m.label }}
                                <!-- Why this row is flagged, in Craft's own
                                     tooltip: the CP registers <craft-tooltip>
                                     and positions, flips and themes it, and the
                                     plugin already leans on it for the element
                                     editor's field indicators. On Craft 4, which
                                     ships no such element, the wrapper is an
                                     inert inline span and the button's `title`
                                     carries the same sentence natively.

                                     `.stop` keeps the press off the row's drill
                                     toggle. It stops propagation, not immediate
                                     propagation, so the tooltip's own listener
                                     on the same button still fires. -->
                                <component
                                    :is="tooltipTag"
                                    v-for="pill in pills(m)"
                                    :key="pill.key"
                                    placement="top"
                                    max-width="260px"
                                    :text="pill.info"
                                >
                                    <button
                                        type="button"
                                        class="influx-detail-pill"
                                        :class="pill.class"
                                        :aria-label="pill.info"
                                        :title="hasCraftTooltip ? null : pill.info"
                                        @click.stop
                                    >{{ pill.label }}<span class="influx-detail-pill-info" data-icon="info" aria-hidden="true" /></button>
                                </component>
                            </span>
                            <!-- The feed node this mapping reads from. A
                                 node-less mapping (an explicit default) shows no
                                 line here — its "use default" pill says it. -->
                            <code v-if="m.node" class="influx-detail-node" v-text="m.node"></code>
                        </div>

                        <!-- Drilling replaces the value cells outright: a
                             nested value's own blob says nothing a reader can
                             use, so the row summarises what's inside instead. -->
                        <template v-if="hasChildren(m)">
                            <div class="influx-detail-drill-summary" v-text="countSummary(m.childrenType, m.children.length)"></div>
                            <div
                                class="influx-detail-drill-state"
                                :class="'influx-detail-drill-state--' + (drillState(m) || 'none')"
                                v-text="drillLabel(m)"
                            ></div>
                            <span class="influx-detail-drill-chevron" data-icon="rightangle" aria-hidden="true"></span>
                        </template>

                        <template v-else>
                            <div class="influx-detail-val">
                                <code v-if="! isNullish(incomingCell(m))" v-text="incomingCell(m)"></code>
                            </div>

                            <div class="influx-detail-val" :class="{ 'influx-detail-val--current': context !== 'log' }">
                                <!-- Log context: a parsed value with a rich
                                     display comes down as server-rendered
                                     markup — element chips for relations, a
                                     lightswitch for booleans (server-generated,
                                     same trust level as the header chip).
                                     Everything else falls back to the plain
                                     text. -->
                                <div
                                    v-if="context === 'log' && ! isNullish(m.parsedHtml)"
                                    class="influx-detail-rich"
                                    v-html="m.parsedHtml"
                                ></div>
                                <code v-else-if="! isNullish(middleCell(m))" v-text="middleCell(m)"></code>
                            </div>
                        </template>
                    </div>

                    <!-- A field whose strategy errored: a full-width red band
                         aligned to the value columns, between this row and the next. -->
                    <p v-if="m.error" class="influx-detail-field-error"><span v-text="m.error"></span></p>
                </template>
            </template>

            <p v-else-if="! row.message && ! row.error" class="influx-detail-empty light" v-text="$t('No mapped fields.')"></p>
        </div>

        <!-- Raw: the item's payload exactly as it came off the feed. -->
        <pre v-else class="influx-detail-raw" v-text="rawJson"></pre>

    </div>
</template>

<style scoped>
.influx-detail {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    overflow: hidden;
}

.influx-detail-head {
    display: flex;
    flex: none;
    align-items: center;
    gap: 10px;
    box-sizing: border-box;
    min-height: var(--influx-split-head-height);
    padding: 10px 18px;
    background: var(--gray-050);
    border-bottom: 1px solid var(--hairline-color);
}

/* Only the field table / raw payload scrolls; the header above stays put. */
.influx-detail-body {
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
.influx-detail-body::-webkit-scrollbar-track {
    background: transparent;
}


.influx-detail-chip { min-width: 0; }
.influx-detail-title { font-size: 13px; font-weight: 600; }
.influx-detail-badge { flex: none; }
.influx-detail-toggle { margin-left: auto; flex: none; }

/* Field-comparison grid — four columns shared by the headings, rows and the
   per-field error band so everything lines up. The narrow last one is the drill
   gutter (a chevron on rows that nest elements); every other row simply leaves
   it empty, so nothing renders a filler cell for it. */
.influx-detail-headings,
.influx-detail-row,
.influx-detail-field-error {
    display: grid;
    grid-template-columns: minmax(130px, .8fr) minmax(160px, 1.2fr) minmax(160px, 1.2fr) 24px;
    gap: 12px;
}

.influx-detail-headings {
    /* Pinned to the top of the scrolling body (.influx-detail-body is the
       scroll container in both hosts), so the column labels stay visible
       while the field rows scroll underneath. The solid background is what
       hides the rows passing behind; the z-index keeps injected content
       (element chips, lightswitches) from painting over it. */
    position: sticky;
    top: 0;
    z-index: 2;
    padding: 8px 18px;
    background: #f3f7fc;
    border-bottom: 1px solid rgba(0, 0, 0, .06);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #777;
}

.influx-detail-row {
    align-items: start;
    padding: 9px 18px;
    border-bottom: 1px solid rgba(0, 0, 0, .05);
}

/* Fields that will change get the green tinted row + inset rail. */
.influx-detail-row[data-changed="true"] {
    background: #eef9f1;
    box-shadow: inset 3px 0 0 #45a35e;
}

/* A row that drills reads as one affordance — the whole row is the button. */
.influx-detail-row--drill { cursor: pointer; }

/* Its wash is the worst state inside it, a shade lighter than the flat
   data-changed green so a drill row is legible as its own kind of row. */
.influx-detail-row[data-drill-state="changed"] {
    background: #f2faf4;
    box-shadow: inset 2px 0 0 #45a35e;
}

.influx-detail-row[data-drill-state="warn"] {
    background: #fff9ea;
    box-shadow: inset 2px 0 0 #ed8936;
}

.influx-detail-row[data-drill-state="error"] {
    background: #fdeeee;
    box-shadow: inset 2px 0 0 #d64242;
}

.influx-detail-drill-summary {
    min-width: 0;
    font-size: 12px;
    color: var(--fg-subtle);
}

.influx-detail-drill-state {
    min-width: 0;
    font-size: 12px;
    font-weight: 600;
}

.influx-detail-drill-state--error { color: #a52121; }
.influx-detail-drill-state--warn { color: #8a6116; }
.influx-detail-drill-state--changed { color: #1f7a38; }
.influx-detail-drill-state--none { color: var(--light-text-color); }

.influx-detail-drill-chevron {
    align-self: center;
    justify-self: end;
    color: var(--light-text-color);
}

.influx-detail-field { min-width: 0; }

.influx-detail-field-name {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
}

/* Status pills beside the field label. They're buttons — Craft's tooltip needs
   a focusable trigger, and it keeps the explanation reachable by keyboard — so
   reset the native chrome and share one shape; each variant only differs in
   palette. The trailing info glyph marks them as "there's more". */
/* The tooltip wrapper takes the pill's place as a flex item of the label row:
   cp.css styles only `.craft-tooltip` (the span it appends to the body), never
   the host element, which is inline by default. */
.influx-detail-field-name > craft-tooltip,
.influx-detail-field-name > span:has(> .influx-detail-pill) {
    display: inline-flex;
}

.influx-detail-pill {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 0 7px;
    border-radius: 9px;
    border: 1px solid transparent;
    font-family: inherit;
    font-size: 10px;
    font-weight: 600;
    line-height: 16px;
    white-space: nowrap;
    cursor: pointer;
}

/* Craft's own info glyph (data-icon="info"), inheriting the pill's colour and
   dimmed until hover — the same affordance Craft uses for instructions. */
.influx-detail-pill-info {
    font-size: 12px;
    opacity: .6;
}

.influx-detail-pill:hover .influx-detail-pill-info {
    opacity: 1;
}

/* Match key — informational blue. */
.influx-detail-pill--match {
    background: #e1eefc;
    border-color: #b9d3f0;
    color: #1c4f8a;
}

/* "Left untouched" — warm amber, matching the mapper's missing-node badge
   (.influx-missing-badge) so the same "no source node" signal reads
   consistently across the builder and the inspector. */
.influx-detail-pill--untouched {
    background: #fdecc8;
    border-color: #f0c674;
    color: #8a6d00;
}

/* Used the configured default — neutral grey, an informational fallback. */
.influx-detail-pill--default {
    background: #eceef1;
    border-color: #d5d9df;
    color: #6b7280;
}

/* Reconciled by the target, not written on the element save — muted violet,
   distinct from the grey default pill. */
.influx-detail-pill--managed {
    background: #ece9f5;
    border-color: #d3cbe8;
    color: #5b4a8a;
}

.influx-detail-node {
    display: block;
    margin-top: 2px;
    padding: 0;
    background: none;
    font-size: 11px;
    color: #9aa5b1;
}

.influx-detail-val { min-width: 0; }

.influx-detail-val code {
    padding: 0;
    background: none;
    font-size: 12px;
    white-space: pre-wrap;
    word-break: break-word;
}

.influx-detail-val--current code { color: var(--fg-subtle); }

/* Log context: rich parsed values render as server-side markup — element chips
   for relations, a lightswitch for booleans. The pieces sit inline and wrap
   onto further rows, with a small gap between them (the markup itself is
   Craft-styled, injected via v-html). */
.influx-detail-rich {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
}


.influx-detail-field-error {
    margin: 0;
    padding: 7px 18px;
    background: #fde2e2;
    box-shadow: inset 3px 0 0 #d64242;
    color: #8a1f1f;
}

.influx-detail-field-error > span {
    grid-column: 2 / -1;
    white-space: pre-wrap;
    word-break: break-word;
}

/* Item-level message / error bands, spanning the full width above the table. */
.influx-debug-item-note {
    margin: 0;
    padding: 9px 18px;
    background: #f3f7fc;
    box-shadow: inset 3px 0 0 #9aa5b1;
    color: #596673;
}

.influx-debug-item-error {
    margin: 0;
    padding: 9px 18px;
    background: #fde2e2;
    box-shadow: inset 3px 0 0 #d64242;
    color: #8a1f1f;
    white-space: pre-wrap;
    word-break: break-word;
}

.influx-detail-empty { padding: 16px 18px; }

.influx-detail-raw {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
    margin: 0;
    padding: 14px 18px;
    font-size: 12px;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-word;
    background: #f8fafc;
}

</style>

<script>
import ActionBadge from './ActionBadge.vue';
import { drillCounts, drillState } from '../lib/drill.js';

/**
 * The right pane of the split debug inspector: the drill-down for one selected
 * item. Header (element chip or match-value title + action tag + Parsed /
 * Raw JSON switch) over either the field-comparison table or the raw payload.
 * Renders the same `row` shape InspectorService::itemRow() produces, so it's
 * shared by both split inspectors — the live debug dry-run (DebugApp) and the
 * run-log drill-down (LogApp).
 *
 * A `context` prop tailors the middle value column: 'debug' (the default) shows
 * the element's live Current value; 'log' replaces it with the feed's Parsed
 * value next to the raw Incoming value, since a historical run has no
 * meaningful "current" state to compare against. In the log context a mapping
 * may also carry `parsedHtml` — a server-rendered rich variant of the parsed
 * value (Craft element chips for relations, a display-only lightswitch for
 * booleans) shown in that column in place of the plain text, which stays as
 * the fallback when the key is absent/null. The debug context ignores the key
 * entirely, so its live streaming table is unaffected.
 *
 * There is no "Changed?" column: a row that would change is marked by its green
 * tint (data-changed). Per-row status — match key, missing node, used default,
 * managed by the target — shows as pills beside the field label, each opening a
 * short popover explaining the "why" on click.
 *
 * A row whose value nests elements (Matrix blocks, relations — anything the
 * server sent `children` for) is the way into them instead of a value: its two
 * value cells become a count summary ("4 blocks") and a state-coloured label
 * ("2 changes"), a chevron lands in the grid's gutter column, and the row as a
 * whole emits `drill` with that mapping on click or Enter. Its wash comes from
 * `data-drill-state` — the worst state inside it (error > missing node > change,
 * see lib/drill.js) — and never from `data-changed`, so the two signals can't
 * fight over one row.
 *
 * The host mounts the component again for the child a reader drills into, this
 * time with `drilled` (no Parsed / Raw switch — a child has no payload of its
 * own) and a `fallbackLabel` matching the child's place in the drill list, for
 * the case where the child has neither an element to chip nor a title.
 */
export default {
    name: 'DebugItemDetail',

    emits: ['drill'],

    props: {
        row: { type: Object, required: true },
        // The link's match attribute handle, so the field it reads from gets a
        // "Match by" tag.
        matchAttribute: { type: String, default: '' },
        // Where this drill-down is rendered: 'debug' (the live dry-run, where
        // the middle column is the element's Current value) or 'log' (a
        // historical run, where it's the feed's Parsed value instead — rendered
        // rich via a mapping's `parsedHtml` when present).
        context: { type: String, default: 'debug' },
        // Rendering a child node rather than a feed item: there's no payload of
        // its own to show, so the Parsed / Raw switch goes away and the view
        // stays parsed.
        drilled: { type: Boolean, default: false },
        // Last resort for the header label, filled by the host with the child's
        // place in the drill list ('01', '02', …) — used only when there is
        // neither an element chip nor a title, so the two panes still name the
        // selection the same way.
        fallbackLabel: { type: String, default: '' },
    },

    data() {
        return {
            view: 'parsed',
        };
    },

    computed: {
        /**
         * Craft 5 registers `<craft-tooltip>`; Craft 4 ships nothing of the
         * kind, so the wrapper degrades to a plain inline span and each pill
         * falls back to its native `title`. Read once — a custom element can't
         * be registered mid-render.
         */
        hasCraftTooltip() {
            return !!window.customElements?.get?.('craft-tooltip');
        },

        tooltipTag() {
            return this.hasCraftTooltip ? 'craft-tooltip' : 'span';
        },

        // Header label when there's no element chip (a would-create or
        // would-skip item, or a child the sync hasn't created yet): the match
        // value, else the row's own title (children carry no match value), else
        // whatever the host handed down as a last resort — the child's ordinal.
        title() {
            return (this.row.element && this.row.element.title)
                || this.row.matchValue
                || this.row.title
                || this.fallbackLabel
                || '';
        },

        // A drilled child has no raw payload, so the toggle is gone and the
        // view is pinned — whatever the local `view` last held.
        activeView() {
            return this.drilled ? 'parsed' : this.view;
        },

        rawJson() {
            try {
                return JSON.stringify(this.row.raw ?? {}, null, 2);
            } catch (e) {
                return String(this.row.raw);
            }
        },
    },

    methods: {
        // The value coming in from the feed: the parsed value when there is one
        // (falls back to the raw value for native attributes, which don't parse).
        incoming(m) {
            return this.isNullish(m.parsedValue) ? m.rawValue : m.parsedValue;
        },

        // The Incoming column: the parsed (raw-fallback) value in the debug
        // inspector, but the untouched raw value straight off the feed in the
        // log context, where the parsed value gets its own column.
        incomingCell(m) {
            return this.context === 'log' ? m.rawValue : this.incoming(m);
        },

        // The middle column: the element's live Current value in the debug
        // inspector, the feed's Parsed (raw-fallback) value in the log context.
        middleCell(m) {
            return this.context === 'log' ? this.incoming(m) : m.currentValue;
        },

        isMatch(m) {
            return this.matchAttribute !== '' && m.handle === this.matchAttribute;
        },

        hasChildren(m) {
            return !! (m.children && m.children.length);
        },

        // Re-exported so the template can read a row's worst-state roll-up.
        drillState,

        // Ask the host to drill into this row. A plain row's click falls
        // through here and does nothing (the status pills @click.stop before
        // this anyway, so their popovers don't also drill).
        drill(m) {
            if (this.hasChildren(m)) {
                this.$emit('drill', m);
            }
        },

        // What a drill row shows in place of the Incoming value: how many
        // children are in there, named by kind. One literal per noun so the
        // strings are scannable for the translation catalogue.
        countSummary(type, n) {
            switch (type) {
                case 'blocks':
                    return this.$t('{n} blocks', { n });
                case 'rows':
                    return this.$t('{n} rows', { n });
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

        // The middle cell of a drill row: the worst state inside it, counted.
        // Same priority as the row's wash, so label and colour never disagree.
        drillLabel(m) {
            const { errors, missing, changes } = drillCounts(m);

            if (errors) {
                return errors === 1 ? this.$t('1 error') : this.$t('{n} errors', { n: errors });
            }

            if (missing) {
                return missing === 1 ? this.$t('1 missing node') : this.$t('{n} missing nodes', { n: missing });
            }

            if (changes) {
                return changes === 1 ? this.$t('1 change') : this.$t('{n} changes', { n: changes });
            }

            return this.$t('No changes');
        },

        /**
         * The flags this row carries, each with the sentence that explains it.
         * One list rather than four near-identical blocks, since every pill
         * differs only in when it shows, what it says and its palette.
         *
         * The explanation is the pill's accessible NAME (`aria-label`), not just
         * its tooltip text: Craft's tooltip sets no `role="tooltip"` and no
         * `aria-describedby`, so a reader who can't see it would otherwise get
         * only "use default" with no way to reach the why.
         */
        pills(m) {
            return [
                {
                    key: 'match',
                    when: this.isMatch(m),
                    class: 'influx-detail-pill--match',
                    label: this.$t('match by'),
                    info: this.$t('the unique identifier used by this Element Link'),
                },
                {
                    key: 'untouched',
                    when: m.unaddressed,
                    class: 'influx-detail-pill--untouched',
                    label: this.$t('missing node'),
                    info: this.$t('the mapped node does not exist for this Element Link'),
                },
                {
                    key: 'default',
                    when: m.usedDefault,
                    class: 'influx-detail-pill--default',
                    label: this.$t('use default'),
                    info: this.$t('the mapped node pushed a default value for this Element Link'),
                },
                {
                    key: 'managed',
                    when: m.managedByTarget,
                    class: 'influx-detail-pill--managed',
                    label: this.$t('not managed by element'),
                    info: this.$t('This value isn\'t written during the element save — Influx reconciles it separately after each item is imported.'),
                },
            ].filter((pill) => pill.when);
        },

        // Values arrive already stringified + truncated by describeValue();
        // a genuine null/undefined renders as a blank cell.
        isNullish(v) {
            return v === null || v === undefined;
        },

    },

    components: { 'v-action-badge': ActionBadge },
};
</script>
