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

        <p v-if="row.message" class="light influx-debug-note">{{ row.message }}</p>
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
    </mapping-group-card>
</template>

<script>
import MappingGroupCard from './MappingGroupCard.vue';
import { actionColor } from '../lib/actionColors.js';

/**
 * One inspected item — the dry-run/log drill-down row. Renders the JSON `row`
 * produced by DebugService::debugItem() inside the shared mapping-group card:
 * a static header (element link + match + status-coloured action tag) over a
 * field-comparison grid (Field/Source/Raw/Parsed/Current/Changed) and a
 * raw-JSON disclosure. Shared verbatim by the debug inspector and the log
 * viewer's per-item drill-down.
 */
export default {
    name: 'DebugItem',

    components: { MappingGroupCard },

    props: {
        row: { type: Object, required: true },
    },

    computed: {
        color() {
            return actionColor(this.row.action);
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
        // Values arrive already stringified + truncated by describeValue();
        // only a genuine null/undefined renders as an em dash.
        isNullish(v) {
            return v === null || v === undefined;
        },
    },
};
</script>

<style scoped>
/* Debug item: reuses the mapping-group card + the global base .influx-mapping-row
   grid, widened to the inspector's six comparison columns. All targets here are
   this component's own elements (header slot + body), so scoped is safe. */
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
