<template>
    <div class="influx-debug-fields-body">
        <!-- Headings always render — an item with no mapping rows (a swept
             missing-element, a payload-less legacy row) still reads as the
             same table, with the message band spanning its full width. -->
        <div class="influx-mapping-headings influx-debug-fields">
            <div>{{ $t('Field') }}</div>
            <div>{{ $t('Source node') }}</div>
            <div>{{ $t('Raw') }}</div>
            <div>{{ $t('Parsed') }}</div>
            <div>{{ $t('Current') }}</div>
            <div>{{ $t('Changed?') }}</div>
        </div>

        <!-- Item-level message / error: full-width bands under the headings,
             spanning every column (the per-field error bands further down keep
             their column-2 alignment — they belong to a specific row). -->
        <p v-if="row.message" class="influx-debug-note influx-debug-item-note">{{ row.message }}</p>
        <pre v-if="row.error" class="influx-debug-note influx-debug-item-error">{{ row.error }}</pre>

        <template v-if="row.mappings && row.mappings.length">
            <template v-for="m in row.mappings" :key="m.handle">
            <div
                class="influx-mapping-row influx-debug-field-row"
                :data-changed="m.changed ? 'true' : null"
            >
                <div class="meta">
                    <span class="name">{{ m.label }}</span>
                    <code class="handle light">{{ m.handle }}<template v-if="m.native"> ({{ $t('native') }})</template></code>
                </div>

                <div>
                    <code v-if="m.node">{{ m.node }}</code>
                    <template v-if="!isNullish(m.default) && m.default !== ''">
                        <br><span class="light">{{ $t('default') }}:</span> <code>{{ m.default }}</code>
                    </template>
                </div>

                <div>
                    <code v-if="!isNullish(m.rawValue)" class="influx-debug-value">{{ m.rawValue }}</code>
                </div>

                <div>
                    <span v-if="m.native" class="light">{{ $t('n/a') }}</span>
                    <code v-else-if="!isNullish(m.parsedValue)" class="influx-debug-value">{{ m.parsedValue }}</code>
                </div>

                <div>
                    <code v-if="!isNullish(m.currentValue)" class="influx-debug-value">{{ m.currentValue }}</code>
                </div>

                <div>
                    <span v-if="m.changed === null || m.changed === undefined" class="light">?</span>
                    <template v-else-if="m.changed"><span class="status live"></span> {{ $t('yes') }}</template>
                    <template v-else><span class="status"></span> {{ $t('no') }}</template>
                    <template v-if="m.note"><br><span class="light">{{ m.note }}</span></template>
                </div>
            </div>

            <!-- A field whose strategy errored gets its message inlined as a
                 full-width band between this row and the next — the red
                 counterpart to the green "changed" tint. The band carries the
                 row's own column grid so the message lines up with the
                 source-node column, not the field-name column. -->
            <p v-if="m.error" class="influx-debug-field-error"><span>{{ m.error }}</span></p>
            </template>
        </template>

        <details v-if="row.raw" class="influx-debug-raw">
            <summary class="light">{{ $t('Raw item JSON') }}</summary>
            <pre>{{ rawJson }}</pre>
        </details>
    </div>
</template>

<script>
/**
 * The body of an inspected item — the message/error notes, the six-column
 * field-comparison grid (Field/Source/Raw/Parsed/Current/Changed) and the
 * raw-JSON disclosure — split out from DebugItem so both the debug inspector
 * (DebugItem) and the run-log viewer (LogItem) render the exact same detail
 * from a DebugService::debugItem() `row`. The header (element chip + action
 * tag) is the consumer's responsibility, since it differs between the two.
 */
export default {
    name: 'DebugFields',

    props: {
        row: { type: Object, required: true },
    },

    computed: {
        rawJson() {
            try {
                return JSON.stringify(this.row.raw ?? {}, null, 2);
            } catch (e) {
                return String(this.row.raw);
            }
        },
    },

    methods: {
        // Values arrive already stringified + truncated by describeValue();
        // a genuine null/undefined renders as a blank cell.
        isNullish(v) {
            return v === null || v === undefined;
        },
    },
};
</script>

<style scoped>
/* The field-comparison grid: the shared mapping-group card + the global base
   .influx-mapping-row grid, widened to the inspector's six comparison columns.
   All targets here are this component's own elements, so scoped is safe. */
.influx-mapping-headings.influx-debug-fields,
.influx-mapping-row.influx-debug-field-row,
.influx-debug-field-error {
    grid-template-columns:
        minmax(110px, 1fr)
        minmax(120px, 1.1fr)
        minmax(120px, 1.2fr)
        minmax(120px, 1.2fr)
        minmax(120px, 1.2fr)
        minmax(70px, .6fr);
}

/* No chevron gutter in the debug rows. */
.influx-mapping-headings.influx-debug-fields > div:first-child,
.influx-mapping-row.influx-debug-field-row .meta { padding-left: 0; }

.influx-debug-field-row > div { min-width: 0; }

.influx-debug-value {
    white-space: pre-wrap;
    word-break: break-all;
}

/* Fields that will change get the same tinted-row + inset rail treatment the
   missing-mapping rows use, in the green "changed/active" palette (amber is
   reserved for the missing/needs-attention signal). */
.influx-debug-field-row[data-changed="true"] {
    background: #eef9f1;
    box-shadow: inset 3px 0 0 #45a35e;
}

/* The red counterpart, for a field whose strategy errored: a full-width band
   between this row and the next, carrying the message — mirroring the green
   "changed" tint + inset rail. Same column grid + gap + padding as the rows, so
   the message (placed from column 2) aligns with the source-node column. */
.influx-debug-field-error {
    display: grid;
    gap: 12px;
    margin: 0;
    padding: 7px 12px;
    background: #fde2e2;
    box-shadow: inset 3px 0 0 #d64242;
    color: #8a1f1f;
}

.influx-debug-field-error > span {
    grid-column: 2 / -1;
    white-space: pre-wrap;
    word-break: break-word;
}

/* Item-level message: a neutral full-width band under the headings — the
   grey sibling of the red error band below. */
.influx-debug-item-note {
    margin: 0;
    padding: 7px 12px;
    background: #f3f7fc;
    box-shadow: inset 3px 0 0 #9aa5b1;
    color: #596673;
}

/* Item-level error: same band, red — spans every column (unlike the
   per-field .influx-debug-field-error, which aligns to its row's columns). */
.influx-debug-item-error {
    margin: 0;
    padding: 7px 12px;
    background: #fde2e2;
    box-shadow: inset 3px 0 0 #d64242;
    color: #8a1f1f;
    white-space: pre-wrap;
    word-break: break-word;
}

.influx-debug-raw { padding: 8px 12px 10px; }
.influx-debug-raw pre {
    max-height: 400px;
    overflow: auto;
    background: #f3f7fc;
    padding: 8px;
    margin: 6px 0 0;
}
</style>
