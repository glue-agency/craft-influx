<template>
    <div class="influx-mapping-extras" v-if="kind" :data-expanded="expanded ? 'true' : 'false'">
        <div class="extras-header">
            <button
                type="button"
                class="extras-toggle"
                :aria-expanded="expanded ? 'true' : 'false'"
                @click="expanded = !expanded"
            >
                <span class="chevron">{{ expanded ? '▼' : '▶' }}</span>
                {{ expanded ? labels.hideOptions : labels.configure }}
            </button>
        </div>

        <div v-show="expanded" class="extras-body">
            <!-- Asset: URL vs ID + sub-element fields (alt/title) -->
            <template v-if="kind === 'asset'">
                <div class="row">
                    <label>{{ labels.valueIs }}</label>
                    <div class="select">
                        <select v-model="options.mode" @change="commit" :disabled="readOnly">
                            <option
                                v-for="opt in (field.fieldMeta?.modeOptions || [])"
                                :key="opt.value"
                                :value="opt.value"
                            >{{ opt.label }}</option>
                        </select>
                    </div>
                </div>
                <template v-if="options.mode === 'url'">
                    <div class="row">
                        <label class="inline-toggle">
                            <input type="checkbox" v-model="options.upload" @change="commit" :disabled="readOnly">
                            {{ labels.uploadToggle }}
                        </label>
                    </div>
                    <div class="row" v-if="options.upload">
                        <label>{{ labels.targetVolume }}</label>
                        <input type="text" class="text" v-model="options.volume" :placeholder="labels.targetVolumePh" @input="commit" :disabled="readOnly">
                    </div>
                    <div class="row" v-if="options.upload">
                        <label>{{ labels.subFolder }}</label>
                        <input type="text" class="text" v-model="options.folderPath" :placeholder="labels.subFolderPh" @input="commit" :disabled="readOnly">
                    </div>
                    <div class="row" v-if="options.upload">
                        <label>{{ labels.onConflict }}</label>
                        <div class="select">
                            <select v-model="options.conflict" @change="commit" :disabled="readOnly">
                                <option
                                    v-for="opt in (field.fieldMeta?.conflictOptions || [])"
                                    :key="opt.value"
                                    :value="opt.value"
                                >{{ opt.label }}</option>
                            </select>
                        </div>
                    </div>
                </template>

                <div class="sub-fields">
                    <p class="sub-fields-title">{{ labels.subFieldsTitle }}</p>
                    <p class="light">{{ labels.subFieldsHint }}</p>
                    <div class="sub-field-row" v-for="sub in subFieldList" :key="sub.handle">
                        <label>{{ sub.label }}</label>
                        <div class="select">
                            <select
                                v-model="nativeFields[sub.handle].node"
                                @change="commit"
                                :disabled="readOnly"
                            >
                                <option value="">{{ labels.noMapping }}</option>
                                <option v-for="opt in nodeOptions" :key="opt.value" :value="opt.value">
                                    {{ opt.label }}
                                </option>
                            </select>
                        </div>
                        <input
                            type="text"
                            class="text"
                            v-model="nativeFields[sub.handle].default"
                            :placeholder="labels.defaultPh"
                            @input="commit"
                            :disabled="readOnly"
                        >
                    </div>
                </div>
            </template>

            <!-- Lightswitch: truthy strings -->
            <template v-else-if="kind === 'boolean'">
                <div class="row">
                    <label>{{ labels.truthyLabel }}</label>
                    <input
                        type="text"
                        class="text"
                        v-model="truthyText"
                        :placeholder="labels.truthyPlaceholder"
                        @input="commitTruthy"
                        :disabled="readOnly"
                    >
                </div>
                <p class="light hint">{{ labels.truthyHint }}</p>
            </template>

            <!-- Relation (entries/users/categories/tags): match + create -->
            <template v-else-if="kind === 'relation'">
                <div class="row">
                    <label>{{ labels.matchBy }}</label>
                    <div class="select">
                        <select v-model="options.match" @change="commit" :disabled="readOnly">
                            <template v-for="(group, gi) in matchOptions" :key="gi">
                                <optgroup v-if="group.label" :label="group.label">
                                    <option
                                        v-for="opt in group.options"
                                        :key="opt.value"
                                        :value="opt.value"
                                    >{{ opt.label }}</option>
                                </optgroup>
                                <template v-else>
                                    <option
                                        v-for="opt in group.options"
                                        :key="opt.value"
                                        :value="opt.value"
                                    >{{ opt.label }}</option>
                                </template>
                            </template>
                        </select>
                    </div>
                </div>
                <div class="row" v-if="field.fieldMeta?.allowCreate !== false">
                    <label class="inline-toggle">
                        <input type="checkbox" v-model="options.create" @change="commit" :disabled="readOnly">
                        {{ labels.createMissing }}
                    </label>
                </div>
            </template>

            <!-- Dropdown / Radio / Checkbox / MultiSelect: optional value-map -->
            <template v-else-if="kind === 'options'">
                <p class="light hint">{{ labels.valueMapHint }}</p>
                <div class="value-map">
                    <div class="value-map-row" v-for="(_, idx) in valueMap" :key="idx">
                        <input
                            type="text"
                            class="text"
                            v-model="valueMap[idx].remote"
                            :placeholder="labels.remoteValue"
                            @input="commitValueMap"
                            :disabled="readOnly"
                        >
                        <div class="value-map-target">
                            <span class="arrow">→</span>
                            <div class="select">
                                <select v-model="valueMap[idx].local" @change="commitValueMap" :disabled="readOnly">
                                    <option value="">{{ labels.pickLocal }}</option>
                                    <option
                                        v-for="(label, value) in (field.fieldMeta?.options || {})"
                                        :key="value"
                                        :value="value"
                                    >{{ label }}</option>
                                </select>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="remove-row"
                            @click="removeValueRow(idx)"
                            :title="labels.removeRow"
                            :disabled="readOnly"
                        >×</button>
                    </div>
                </div>
                <div class="row-actions">
                    <button type="button" class="btn small" @click="addValueRow" :disabled="readOnly">
                        {{ labels.addRow }}
                    </button>
                </div>
            </template>

            <!-- Date: PHP date format picker -->
            <template v-else-if="kind === 'date'">
                <div class="row">
                    <label>{{ labels.formatLabel }}</label>
                    <div class="select-with-hint">
                        <div class="select">
                            <select v-model="options.format" @change="commit" :disabled="readOnly">
                                <option
                                    v-for="opt in (field.fieldMeta?.formatOptions || [])"
                                    :key="opt.value"
                                    :value="opt.value"
                                >{{ opt.label }}</option>
                            </select>
                        </div>
                        <p class="light hint">{{ labels.formatHint }}</p>
                    </div>
                </div>
            </template>

            <!-- Matrix: stub until we model blocks -->
            <template v-else-if="kind === 'matrix'">
                <p class="light">{{ labels.placeholder }}</p>
            </template>
        </div>

    </div>
</template>

<script>
export default {
    name: 'MappingExtras',

    props: {
        field: { type: Object, required: true },
        saved: { type: Object, required: true },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:options', 'update:nativeFields'],

    data() {
        return {
            expanded: true,
            options: this.buildInitialOptions(),
            // Sub-element native attrs (alt/title for assets, etc.). Stored
            // separately from `options` so they round-trip into the recursive
            // `mappings[handle][nativeFields][sub]` server shape.
            nativeFields: this.buildInitialNativeFields(),
            valueMap: this.buildInitialValueMap(),
            truthyText: this.buildInitialTruthy(),
            nodeOptions: [],
            _sampleHandler: null,
        };
    },

    computed: {
        kind() {
            return (this.field.fieldMeta && this.field.fieldMeta.kind) || null;
        },

        /**
         * Per-kind UI strings + the shared toggle copy, all translated
         * server-side via `Craft::t('influx', …)` and shipped through
         * `fieldMeta.labels`. Centralising the lookup here means the
         * template stays free of literal user-facing strings — any missing
         * key shows up as `undefined`, which is the clearest possible
         * signal that a label needs to be added in PHP.
         */
        labels() {
            return (this.field.fieldMeta && this.field.fieldMeta.labels) || {};
        },

        subFieldList() {
            const subs = (this.field.fieldMeta && this.field.fieldMeta.subFields) || {};
            return Object.keys(subs).map((h) => ({ handle: h, label: subs[h] }));
        },

        /**
         * "Match by" options for the relation extras. PHP ships the grouped
         * shape `[{ label, options: [{value,label}] }]`.
         */
        matchOptions() {
            return (this.field.fieldMeta && this.field.fieldMeta.matchOptions) || [];
        },

    },

    created() {
        // Seed source-node options with any saved sub-field paths so the
        // dropdowns can render before the user fetches a fresh sample.
        const seen = new Set();
        for (const sub of this.subFieldList) {
            const saved = this.options.subFields?.[sub.handle]?.node;
            if (saved && !seen.has(saved)) {
                this.nodeOptions.push({ value: saved, label: saved.replace(/\./g, ' → ') });
                seen.add(saved);
            }
        }
    },

    watch: {
        // Push internal state up to the parent so the link store stays
        // in sync. We emit the trimmed shape that ends up in Project Config.
        options: {
            deep: true,
            handler() {
                const trimmed = {};
                for (const k of Object.keys(this.options)) {
                    const v = this.options[k];
                    if (v === '' || v === null || v === undefined || v === false) continue;
                    if (typeof v === 'object' && !Object.keys(v).length) continue;
                    trimmed[k] = v;
                }
                this.$emit('update:options', trimmed);
            },
        },
        nativeFields: {
            deep: true,
            handler() {
                const out = {};
                for (const h of Object.keys(this.nativeFields)) {
                    const row = this.nativeFields[h];
                    if (!row || (row.node === '' && row.default === '')) continue;
                    out[h] = {};
                    if (row.node) out[h].node = row.node;
                    if (row.default) out[h].default = row.default;
                }
                this.$emit('update:nativeFields', out);
            },
        },
    },

    mounted() {
        this._sampleHandler = (e) => this.onSampleFetched(e.detail || {});
        window.addEventListener('influx:sample-fetched', this._sampleHandler);
    },

    beforeUnmount() {
        if (this._sampleHandler) {
            window.removeEventListener('influx:sample-fetched', this._sampleHandler);
        }
    },

    methods: {
        buildInitialOptions() {
            const saved = (this.saved && this.saved.options) || {};
            const base = { ...saved };

            if (this.kind === 'asset') {
                base.mode = saved.mode || 'id';
                base.upload = !!saved.upload;
                base.volume = saved.volume || '';
                base.folderPath = saved.folderPath || '';
                base.conflict = saved.conflict || 'index';
            }

            if (this.kind === 'relation') {
                base.match = saved.match || saved.mode || 'id';
                base.create = !!saved.create;
            }

            return base;
        },

        buildInitialNativeFields() {
            const saved = (this.saved && this.saved.nativeFields) || {};
            const handles = (this.field.fieldMeta && this.field.fieldMeta.subFields) || {};
            const out = {};
            for (const h of Object.keys(handles)) {
                const entry = saved[h] || {};
                out[h] = { node: entry.node || '', default: entry.default || '' };
            }
            return out;
        },

        buildInitialValueMap() {
            const saved = (this.saved && this.saved.options && this.saved.options.valueMap) || {};
            return Object.keys(saved).map((remote) => ({ remote, local: saved[remote] }));
        },

        buildInitialTruthy() {
            const raw = (this.saved && this.saved.options && this.saved.options.truthy) || null;
            if (Array.isArray(raw)) return raw.join(', ');
            return raw || 'true, 1, yes, on';
        },

        onSampleFetched(detail) {
            const flatNodes = (detail.flatNodes || []).map((n) =>
                typeof n === 'string'
                    ? { value: n, label: n.replace(/\./g, ' → ') }
                    : { value: n.value, label: n.label || String(n.value).replace(/\./g, ' → ') },
            );
            // Merge with what we have so saved sub-field paths stay listed.
            const seen = new Set(this.nodeOptions.map((o) => o.value));
            for (const n of flatNodes) {
                if (!seen.has(n.value)) {
                    this.nodeOptions.push(n);
                    seen.add(n.value);
                }
            }
        },

        commit() {
            // Explicit hook called after mutations. Reactivity already
            // emits `update:options` to the parent; keep this around so
            // future extras can run post-change validation here.
        },

        commitTruthy() {
            const parsed = this.truthyText
                .split(',')
                .map((s) => s.trim())
                .filter((s) => s.length > 0);
            this.options.truthy = parsed.length ? parsed : undefined;
        },

        commitValueMap() {
            const map = {};
            for (const row of this.valueMap) {
                if (row.remote && row.local) {
                    map[row.remote] = row.local;
                }
            }
            this.options.valueMap = Object.keys(map).length ? map : undefined;
        },

        addValueRow() {
            this.valueMap.push({ remote: '', local: '' });
        },

        removeValueRow(idx) {
            this.valueMap.splice(idx, 1);
            this.commitValueMap();
        },
    },
};
</script>

<style scoped>
/* Extras live below a `.influx-mapping-row` and span its full width
   (`grid-column: 1 / -1`). Mirror the parent row's grid so labels and
   controls inside the extras line up with the Field / Source-node /
   Default-value columns above.
   Collapsed: render as a plain "▶ Configure" toggle, nothing else.
   Expanded: the parent row is tinted via `:has()` in links.css; the
   body sits flush below it inheriting that same tint. */

.influx-mapping-extras {
    margin-top: 0;
    background: transparent;
    border-top: 0;
}

.extras-header {
    padding: 4px 0;
}

.extras-toggle {
    background: none;
    border: 0;
    padding: 0;
    cursor: pointer;
    color: #364d6b;
    font-size: 12px;
}

.extras-toggle:hover { color: #1f3454; }

.extras-toggle .chevron { display: inline-block; margin-right: 4px; }

.extras-body { padding: 4px 0; }

/* Same column widths and gap as `.influx-mapping-row` /
   `.influx-mapping-headings` in links.css. The slot already sits inside
   the parent row's padded content area, so no extra L/R padding here —
   that's what makes column 1 of the extras line up flush with column 1
   ("Field") of the parent and column 3's right edge match too. */
.extras-body > .row,
.sub-field-row,
.value-map-row {
    display: grid;
    grid-template-columns: minmax(180px, 1.2fr) minmax(220px, 1.4fr) minmax(180px, 1fr);
    gap: 12px;
    align-items: start;
    padding: 6px 0;
}

.extras-body > .row > label,
.sub-field-row > label {
    font-size: 13px;
    font-weight: normal;
    color: inherit;
    /* Match the input/select baseline so the label tops line up with the
       parent mapping row's "Field" name above. */
    padding-top: 6px;
    line-height: 1.2;
}

.extras-body > .row > .inline-toggle {
    grid-column: 2 / -1;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    /* Compensate for the label-top padding so checkboxes don't drift down. */
    padding-top: 0;
}

/* Match Craft's CP input styling so the bare <select>/<input> inside the
   Vue component reads the same as `forms.text` / `forms.select` elsewhere
   in the link edit screen. */
.extras-body input[type="text"],
.extras-body input[type="number"],
.extras-body .select select {
    width: 100%;
}

.sub-fields {
    margin-top: 6px;
    border-top: 1px dashed rgba(0, 0, 0, .08);
    padding-top: 6px;
}

.sub-fields-title {
    padding: 0 0 4px;
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #777;
}

.sub-fields > .light {
    padding: 0 0 6px;
    font-size: 12px;
}

/* Date-format extras: the hint sits directly under the select in the same
   grid cell, so the explanatory text reads as a caption rather than a
   full-width footnote. */
.select-with-hint > .hint {
    margin: 4px 0 0;
}

/* Value-map keeps its remote → local layout but lives inside the same
   3-column grid: remote in source-node column, arrow + local in
   default-value column, remove-row floats at the right edge. */
.value-map-row { grid-template-columns: minmax(180px, 1.2fr) minmax(220px, 1.4fr) auto; }

.value-map-target {
    display: grid;
    grid-template-columns: 16px 1fr;
    align-items: center;
    gap: 6px;
}

.value-map-row .arrow { color: #888; text-align: center; }

.row-actions { padding: 6px 0 4px; }

.hint { padding: 0; font-size: 12px; margin: 2px 0 6px; }

.remove-row {
    justify-self: end;
    background: none;
    border: 1px solid #ccc;
    border-radius: 4px;
    width: 24px;
    height: 24px;
    cursor: pointer;
    color: #888;
}

.remove-row:hover { background: #f3f5f7; color: #cf1124; border-color: #cf1124; }
</style>
