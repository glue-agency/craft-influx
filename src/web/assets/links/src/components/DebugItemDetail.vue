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
            <span v-else-if="row.action === 'would-create'" class="influx-debug-ghost-chip">
                <span class="influx-debug-ghost-chip-glyph" aria-hidden="true">+</span>
                {{ $t('New element') }}
            </span>
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

        <!-- Parsed: incoming vs current, one row per mapped field. -->
        <div v-if="view === 'parsed'" class="influx-detail-body">
            <p v-if="row.message" class="influx-debug-item-note">{{ row.message }}</p>
            <pre v-if="row.error" class="influx-debug-item-error">{{ row.error }}</pre>

            <template v-if="row.mappings && row.mappings.length">
                <div class="influx-detail-headings">
                    <div>{{ $t('Field') }}</div>
                    <div>{{ $t('Incoming') }}</div>
                    <div>{{ $t('Current') }}</div>
                    <div>{{ $t('Changed?') }}</div>
                </div>

                <template v-for="m in row.mappings" :key="m.handle">
                    <div class="influx-detail-row" :data-changed="m.changed ? 'true' : null">
                        <div class="influx-detail-field">
                            <span class="influx-detail-field-name">
                                {{ m.label }}
                                <span v-if="isMatch(m)" class="influx-detail-matchtag">{{ $t('Match by') }}</span>
                            </span>
                            <code v-if="m.node" class="influx-detail-node">{{ m.node }}</code>
                            <code v-else-if="m.native" class="influx-detail-node">{{ $t('native') }}</code>
                        </div>

                        <div class="influx-detail-val">
                            <code v-if="!isNullish(incoming(m))">{{ incoming(m) }}</code>
                        </div>

                        <div class="influx-detail-val influx-detail-val--current">
                            <code v-if="!isNullish(m.currentValue)">{{ m.currentValue }}</code>
                        </div>

                        <div class="influx-detail-changed">
                            <span v-if="m.changed === null || m.changed === undefined" class="light">?</span>
                            <template v-else-if="m.changed"><span class="status live"></span> {{ $t('yes') }}</template>
                            <template v-else><span class="status"></span> {{ $t('no') }}</template>
                            <div v-if="m.note" class="light influx-detail-note">{{ m.note }}</div>
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
    </div>
</template>

<script>
import ActionBadge from './ActionBadge.vue';

/**
 * The right pane of the split debug inspector: the drill-down for one selected
 * item. Header (element chip / would-create ghost chip + action tag + Parsed /
 * Raw JSON switch) over either the incoming-vs-current field table or the raw
 * payload. Renders the same `row` shape DebugService::debugItem() produces, so
 * it stays reusable for the (upcoming) split log-detail view.
 *
 * Deliberately separate from the still-stacked DebugFields/DebugItem, which the
 * log viewer (LogApp) keeps using until its own redesign.
 */
export default {
    name: 'DebugItemDetail',

    components: { ActionBadge },

    props: {
        row: { type: Object, required: true },
        // The link's match attribute handle, so the field it reads from gets a
        // "Match by" tag.
        matchAttribute: { type: String, default: '' },
    },

    data() {
        return {
            view: 'parsed',
        };
    },

    computed: {
        // Header label when there's no element chip and it isn't a would-create
        // ghost (e.g. a would-skip item): the match value, else a placeholder.
        title() {
            return (this.row.element && this.row.element.title)
                || this.row.matchValue
                || this.$t('(no match value)');
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

        isMatch(m) {
            return this.matchAttribute !== '' && m.handle === this.matchAttribute;
        },

        // Values arrive already stringified + truncated by describeValue();
        // a genuine null/undefined renders as a blank cell.
        isNullish(v) {
            return v === null || v === undefined;
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

/* Field-comparison grid — four columns shared by the headings, rows and the
   per-field error band so everything lines up. */
.influx-detail-headings,
.influx-detail-row,
.influx-detail-field-error {
    display: grid;
    grid-template-columns: minmax(130px, .8fr) minmax(160px, 1.2fr) minmax(160px, 1.2fr) 90px;
    gap: 12px;
}

.influx-detail-headings {
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

.influx-detail-matchtag {
    padding: 0 7px;
    border-radius: 9px;
    background: #e1eefc;
    border: 1px solid #b9d3f0;
    color: #1c4f8a;
    font-size: 10px;
    font-weight: 600;
    line-height: 16px;
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

.influx-detail-changed { font-size: 13px; }
.influx-detail-note { margin-top: 2px; }

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

/* "Ghost chip" for would-create, matching DebugItem's (scoped styles don't
   cross components, so it's redefined here). */
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
