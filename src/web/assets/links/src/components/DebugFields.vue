<template>
    <div class="influx-debug-fields-body">
        <p v-if="showMessage && row.message" class="light influx-debug-note">{{ row.message }}</p>
        <pre v-if="row.error" class="error influx-debug-note">{{ row.error }}</pre>

        <template v-if="row.mappings && row.mappings.length">
            <div class="influx-mapping-headings influx-debug-fields">
                <div>{{ $t('Field') }}</div>
                <div>{{ $t('Source node') }}</div>
                <div>{{ $t('Raw') }}</div>
                <div>{{ $t('Parsed') }}</div>
                <div>{{ $t('Current') }}</div>
                <div>{{ $t('Changed?') }}</div>
            </div>

            <div
                v-for="m in row.mappings"
                :key="m.handle"
                class="influx-mapping-row influx-debug-field-row"
                :data-changed="m.changed ? 'true' : null"
            >
                <div class="meta">
                    <span class="name">{{ m.label }}</span>
                    <code class="handle light">{{ m.handle }}<template v-if="m.native"> ({{ $t('native') }})</template></code>
                </div>

                <div>
                    <code v-if="m.node">{{ m.node }}</code>
                    <span v-else class="light">—</span>
                    <template v-if="!isNullish(m.default) && m.default !== ''">
                        <br><span class="light">{{ $t('default') }}:</span> <code>{{ m.default }}</code>
                    </template>
                </div>

                <div>
                    <code v-if="!isNullish(m.rawValue)" class="influx-debug-value">{{ m.rawValue }}</code>
                    <span v-else class="light">—</span>
                </div>

                <div>
                    <span v-if="m.native" class="light">{{ $t('n/a') }}</span>
                    <template v-else>
                        <code v-if="!isNullish(m.parsedValue)" class="influx-debug-value">{{ m.parsedValue }}</code>
                        <span v-else class="light">—</span>
                    </template>
                    <pre v-if="m.error" class="error">{{ m.error }}</pre>
                </div>

                <div>
                    <code v-if="!isNullish(m.currentValue)" class="influx-debug-value">{{ m.currentValue }}</code>
                    <span v-else class="light">—</span>
                </div>

                <div>
                    <span v-if="m.changed === null || m.changed === undefined" class="light">?</span>
                    <template v-else-if="m.changed"><span class="status live"></span> {{ $t('yes') }}</template>
                    <template v-else><span class="status"></span> {{ $t('no') }}</template>
                    <template v-if="m.note"><br><span class="light">{{ m.note }}</span></template>
                </div>
            </div>
        </template>

        <details class="influx-debug-raw">
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
        // The log puts the item message in its card header instead, so it
        // suppresses the in-body note to avoid showing it twice.
        showMessage: { type: Boolean, default: true },
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
        // only a genuine null/undefined renders as an em dash.
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
.influx-mapping-row.influx-debug-field-row {
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

.influx-debug-note { margin: 0; padding: 8px 12px 0; }

.influx-debug-raw { padding: 8px 12px 10px; }
.influx-debug-raw pre {
    max-height: 400px;
    overflow: auto;
    background: #f3f7fc;
    padding: 8px;
    margin: 6px 0 0;
}
</style>
