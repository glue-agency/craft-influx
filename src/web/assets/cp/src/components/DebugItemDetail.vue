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
            <span v-else class="influx-detail-title">{{ title }}</span>

            <action-badge :action="row.action" class="influx-detail-badge" />

            <div class="btngroup influx-detail-toggle">
                <button
                    type="button"
                    class="btn"
                    :class="{ active: view === 'parsed' }"
                    :aria-pressed="view === 'parsed'"
                    @click="view = 'parsed'"
                >{{ $t('Parsed') }}</button>
                <button
                    type="button"
                    class="btn"
                    :class="{ active: view === 'raw' }"
                    :aria-pressed="view === 'raw'"
                    @click="view = 'raw'"
                >{{ $t('Raw JSON') }}</button>
            </div>
        </div>

        <!-- Parsed: incoming vs current (or, in the log context, vs parsed),
             one row per mapped field. -->
        <div v-if="view === 'parsed'" class="influx-detail-body">
            <p v-if="row.message" class="influx-debug-item-note">{{ row.message }}</p>
            <pre v-if="row.error" class="influx-debug-item-error">{{ row.error }}</pre>

            <template v-if="row.mappings && row.mappings.length">
                <!-- Three columns; the "did it change" signal is the row's
                     green tint (data-changed), not a column. Per-row status is
                     carried by the pills beside the field label, each opening a
                     popover with the full "why" on click. -->
                <div class="influx-detail-headings">
                    <div>{{ $t('Field') }}</div>
                    <div>{{ $t('Incoming') }}</div>
                    <div>{{ context === 'log' ? $t('Parsed') : $t('Current') }}</div>
                </div>

                <template v-for="m in row.mappings" :key="m.handle">
                    <div class="influx-detail-row" :data-changed="m.changed ? 'true' : null">
                        <div class="influx-detail-field">
                            <span class="influx-detail-field-name">
                                {{ m.label }}
                                <button
                                    v-if="isMatch(m)"
                                    type="button"
                                    class="influx-detail-pill influx-detail-pill--match"
                                    @click.stop="toggleInfo($event, m.handle + ':match', $t('the unique identifier used by this Element Link'))"
                                >{{ $t('match by') }}<span class="influx-detail-pill-info" data-icon="info" aria-hidden="true"></span></button>
                                <button
                                    v-if="m.unaddressed"
                                    type="button"
                                    class="influx-detail-pill influx-detail-pill--untouched"
                                    @click.stop="toggleInfo($event, m.handle + ':untouched', $t('the mapped node does not exist for this Element Link'))"
                                >{{ $t('missing node') }}<span class="influx-detail-pill-info" data-icon="info" aria-hidden="true"></span></button>
                                <button
                                    v-if="m.usedDefault"
                                    type="button"
                                    class="influx-detail-pill influx-detail-pill--default"
                                    @click.stop="toggleInfo($event, m.handle + ':default', $t('the mapped node pushed a default value for this Element Link'))"
                                >{{ $t('use default') }}<span class="influx-detail-pill-info" data-icon="info" aria-hidden="true"></span></button>
                                <button
                                    v-if="m.managedByTarget"
                                    type="button"
                                    class="influx-detail-pill influx-detail-pill--managed"
                                    @click.stop="toggleInfo($event, m.handle + ':managed', $t('This value isn\'t written during the element save — Influx reconciles it separately after each item is imported.'))"
                                >{{ $t('not managed by element') }}<span class="influx-detail-pill-info" data-icon="info" aria-hidden="true"></span></button>
                            </span>
                            <!-- The feed node this mapping reads from. A
                                 node-less mapping (an explicit default) shows no
                                 line here — its "use default" pill says it. -->
                            <code v-if="m.node" class="influx-detail-node">{{ m.node }}</code>
                        </div>

                        <div class="influx-detail-val">
                            <code v-if="!isNullish(incomingCell(m))">{{ incomingCell(m) }}</code>
                        </div>

                        <div class="influx-detail-val" :class="{ 'influx-detail-val--current': context !== 'log' }">
                            <!-- Log context: a parsed value with a rich display
                                 comes down as server-rendered markup — element
                                 chips for relations, a lightswitch for booleans
                                 (server-generated, same trust level as the
                                 header chip). Everything else falls back to the
                                 plain text. -->
                            <div
                                v-if="context === 'log' && !isNullish(m.parsedHtml)"
                                class="influx-detail-rich"
                                v-html="m.parsedHtml"
                            ></div>
                            <code v-else-if="!isNullish(middleCell(m))">{{ middleCell(m) }}</code>
                        </div>
                    </div>

                    <!-- A field whose strategy errored: a full-width red band
                         aligned to the value columns, between this row and the next. -->
                    <p v-if="m.error" class="influx-detail-field-error"><span>{{ m.error }}</span></p>
                </template>
            </template>

            <p v-else-if="!row.message && !row.error" class="influx-detail-empty light">{{ $t('No mapped fields.') }}</p>
        </div>

        <!-- Raw: the item's payload exactly as it came off the feed. -->
        <pre v-else class="influx-detail-raw">{{ rawJson }}</pre>

        <!-- The "why" popover for a status pill. Teleported to <body> so the
             table's own overflow can't clip it; positioned at the clicked pill
             (viewport coords, hence position: fixed). -->
        <teleport to="body">
            <div
                v-if="info"
                class="influx-detail-info"
                :style="{ top: info.top + 'px', left: info.left + 'px' }"
                @click.stop
            >{{ info.text }}</div>
        </teleport>
    </div>
</template>

<script>
import ActionBadge from './ActionBadge.vue';

/**
 * The right pane of the split debug inspector: the drill-down for one selected
 * item. Header (element chip or match-value title + action tag + Parsed /
 * Raw JSON switch) over either the field-comparison table or the raw payload.
 * Renders the same `row` shape DebugService::debugItem() produces, so it's
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
 */
export default {
    name: 'DebugItemDetail',

    components: { ActionBadge },

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
    },

    data() {
        return {
            view: 'parsed',
            // The open status-pill popover: { key, text, top, left } (viewport
            // coords), or null when none is open. Only one is open at a time.
            info: null,
        };
    },

    beforeUnmount() {
        this.closeInfo();
    },

    computed: {
        // Header label when there's no element chip (a would-create or
        // would-skip item): the match value, else blank.
        title() {
            return (this.row.element && this.row.element.title)
                || this.row.matchValue
                || '';
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

        // Values arrive already stringified + truncated by describeValue();
        // a genuine null/undefined renders as a blank cell.
        isNullish(v) {
            return v === null || v === undefined;
        },

        // Open (or, on the same pill, close) the "why" popover for a status
        // pill, anchored just under the clicked pill. One open at a time; a
        // click anywhere else, Escape, or a scroll dismisses it (listeners are
        // added only while open, and torn down in closeInfo / beforeUnmount).
        toggleInfo(event, key, text) {
            if (this.info && this.info.key === key) {
                this.closeInfo();

                return;
            }

            const rect = event.currentTarget.getBoundingClientRect();
            this.info = { key, text, top: rect.bottom + 6, left: rect.left };

            document.addEventListener('click', this.closeInfo);
            document.addEventListener('keydown', this.onInfoKeydown);
            window.addEventListener('scroll', this.closeInfo, true);
        },

        closeInfo() {
            if (! this.info) {
                return;
            }

            this.info = null;
            document.removeEventListener('click', this.closeInfo);
            document.removeEventListener('keydown', this.onInfoKeydown);
            window.removeEventListener('scroll', this.closeInfo, true);
        },

        onInfoKeydown(event) {
            if (event.key === 'Escape') {
                this.closeInfo();
            }
        },
    },
};
</script>

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
    min-height: 62px;
    padding: 10px 18px;
    background: var(--gray-050);
    border-bottom: 1px solid var(--hairline-color);
}

/* Only the field table / raw payload scrolls; the header above stays put. */
.influx-detail-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
}

.influx-detail-chip { min-width: 0; }
.influx-detail-title { font-size: 13px; font-weight: 600; }
.influx-detail-badge { flex: none; }
.influx-detail-toggle { margin-left: auto; flex: none; }

/* Field-comparison grid — three columns shared by the headings, rows and the
   per-field error band so everything lines up. */
.influx-detail-headings,
.influx-detail-row,
.influx-detail-field-error {
    display: grid;
    grid-template-columns: minmax(130px, .8fr) minmax(160px, 1.2fr) minmax(160px, 1.2fr);
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

.influx-detail-field { min-width: 0; }

.influx-detail-field-name {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
}

/* Status pills beside the field label. They're buttons (clicking opens the
   "why" popover), so reset the native chrome and share one shape; each variant
   only differs in palette. The trailing info glyph marks them as "click for
   more". */
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

/* The status-pill "why" popover, teleported to <body> (so the table overflow
   can't clip it) and fixed at the clicked pill. Self-contained dark card, so it
   reads as an overlay without needing light/dark theming. */
.influx-detail-info {
    position: fixed;
    z-index: 100;
    max-width: 260px;
    padding: 8px 10px;
    background: #29333d;
    color: #fff;
    border-radius: 5px;
    font-size: 12px;
    line-height: 1.4;
    box-shadow: 0 4px 14px rgba(0, 0, 0, .22);
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
