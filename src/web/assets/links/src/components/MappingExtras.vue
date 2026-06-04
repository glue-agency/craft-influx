<template>
    <div class="influx-mapping-extras" v-if="kind">
        <div class="extras-header">
            <span class="kind-badge">{{ kindLabel }}</span>
            <button
                type="button"
                class="extras-toggle"
                :aria-expanded="expanded ? 'true' : 'false'"
                @click="expanded = !expanded"
            >
                <span class="chevron">{{ expanded ? '▼' : '▶' }}</span>
                {{ expanded ? t('Hide options') : t('Configure') }}
            </button>
        </div>

        <div v-show="expanded" class="extras-body">
            <!-- Asset: URL vs ID + sub-element fields (alt/title) -->
            <template v-if="kind === 'asset'">
                <div class="row">
                    <label>{{ t('Value is') }}</label>
                    <select v-model="options.mode" @change="commit">
                        <option value="id">{{ t('Asset ID') }}</option>
                        <option value="url">{{ t('URL (lookup or download)') }}</option>
                    </select>
                </div>
                <template v-if="options.mode === 'url'">
                    <div class="row">
                        <label>
                            <input type="checkbox" v-model="options.upload" @change="commit">
                            {{ t('Download & upload missing files') }}
                        </label>
                    </div>
                    <div class="row" v-if="options.upload">
                        <label>{{ t('Target volume') }}</label>
                        <input type="text" v-model="options.volume" :placeholder="t('Volume handle')" @input="commit">
                    </div>
                    <div class="row" v-if="options.upload">
                        <label>{{ t('Sub-folder') }}</label>
                        <input type="text" v-model="options.folderPath" :placeholder="t('e.g. imports/2024')" @input="commit">
                    </div>
                    <div class="row" v-if="options.upload">
                        <label>{{ t('On conflict') }}</label>
                        <select v-model="options.conflict" @change="commit">
                            <option value="index">{{ t('Reuse existing') }}</option>
                            <option value="keepBoth">{{ t('Keep both (rename)') }}</option>
                            <option value="replace">{{ t('Replace') }}</option>
                        </select>
                    </div>
                </template>

                <div class="sub-fields">
                    <p class="sub-fields-title">{{ t('Asset sub-fields') }}</p>
                    <p class="light">
                        {{ t('Mapped values are written back to the asset itself (alt/title).') }}
                    </p>
                    <div class="sub-field-row" v-for="sub in subFieldList" :key="sub.handle">
                        <label>{{ sub.label }}</label>
                        <select
                            v-model="nativeFields[sub.handle].node"
                            @change="commit"
                        >
                            <option value="">{{ t('— No mapping —') }}</option>
                            <option v-for="opt in nodeOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                        <input
                            type="text"
                            class="sub-field-default"
                            v-model="nativeFields[sub.handle].default"
                            :placeholder="t('Default')"
                            @input="commit"
                        >
                    </div>
                </div>
            </template>

            <!-- Lightswitch: truthy strings -->
            <template v-else-if="kind === 'boolean'">
                <div class="row">
                    <label>{{ t('Truthy values') }}</label>
                    <input
                        type="text"
                        v-model="truthyText"
                        :placeholder="t('true, 1, yes, on')"
                        @input="commitTruthy"
                    >
                </div>
                <p class="light">
                    {{ t('Comma-separated. Anything else (incl. null) maps to false.') }}
                </p>
            </template>

            <!-- Relation (entries/users/categories/tags): match + create -->
            <template v-else-if="kind === 'relation'">
                <div class="row">
                    <label>{{ t('Match by') }}</label>
                    <select v-model="options.match" @change="commit">
                        <option value="id">{{ t('Element ID') }}</option>
                        <option value="slug">{{ t('Slug') }}</option>
                        <option value="title">{{ t('Title') }}</option>
                    </select>
                </div>
                <div class="row">
                    <label>
                        <input type="checkbox" v-model="options.create" @change="commit">
                        {{ t('Create when not found') }}
                    </label>
                </div>
            </template>

            <!-- Dropdown / Radio / Checkbox / MultiSelect: optional value-map -->
            <template v-else-if="kind === 'options'">
                <p class="light">
                    {{ t('Remote → local value map. Leave empty rows to fall through.') }}
                </p>
                <div class="value-map">
                    <div class="value-map-row" v-for="(_, idx) in valueMap" :key="idx">
                        <input
                            type="text"
                            v-model="valueMap[idx].remote"
                            :placeholder="t('Remote value')"
                            @input="commitValueMap"
                        >
                        <span class="arrow">→</span>
                        <select v-model="valueMap[idx].local" @change="commitValueMap">
                            <option value="">{{ t('— Pick —') }}</option>
                            <option
                                v-for="(label, value) in (field.fieldMeta?.options || {})"
                                :key="value"
                                :value="value"
                            >{{ label }}</option>
                        </select>
                        <button
                            type="button"
                            class="remove-row"
                            @click="removeValueRow(idx)"
                            :title="t('Remove row')"
                        >×</button>
                    </div>
                </div>
                <button type="button" class="btn small" @click="addValueRow">
                    {{ t('Add value map') }}
                </button>
            </template>

            <!-- Matrix: stub until we model blocks -->
            <template v-else-if="kind === 'matrix'">
                <p class="light">
                    {{ t('Matrix block mapping is not yet supported. Map remote sub-arrays here in a future update.') }}
                </p>
            </template>
        </div>

        <!--
            All extras state lives in two hidden JSON blobs — options (per-
            field-type config) and nativeFields (sub-element native attrs).
            The controller decodes both into the recursive mapping shape.
        -->
        <input type="hidden" :name="optionsInputName" :value="serializedOptions">
        <input type="hidden" :name="nativeFieldsInputName" :value="serializedNativeFields">
    </div>
</template>

<script>
export default {
    name: 'MappingExtras',

    props: {
        field: { type: Object, required: true },
        saved: { type: Object, required: true },
        namespace: { type: String, default: '' },
        readOnly: { type: Boolean, default: false },
    },

    data() {
        return {
            expanded: false,
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

        kindLabel() {
            const map = {
                asset: this.t('Asset'),
                boolean: this.t('Lightswitch'),
                relation: this.t('Relation'),
                options: this.t('Dropdown'),
                matrix: this.t('Matrix'),
            };
            return map[this.kind] || this.kind;
        },

        subFieldList() {
            const subs = (this.field.fieldMeta && this.field.fieldMeta.subFields) || {};
            return Object.keys(subs).map((h) => ({ handle: h, label: subs[h] }));
        },

        optionsInputName() {
            const base = `mappings[${this.field.handle}][options]`;
            return this.namespace ? `${this.namespace}[${base}]` : base;
        },

        nativeFieldsInputName() {
            const base = `mappings[${this.field.handle}][nativeFields]`;
            return this.namespace ? `${this.namespace}[${base}]` : base;
        },

        serializedOptions() {
            // Keep payload compact: drop empties so saved Project Config
            // doesn't fill up with noise.
            const out = {};
            for (const k of Object.keys(this.options)) {
                const v = this.options[k];
                if (v === '' || v === null || v === undefined || v === false) continue;
                if (typeof v === 'object' && !Object.keys(v).length) continue;
                out[k] = v;
            }
            return Object.keys(out).length ? JSON.stringify(out) : '';
        },

        serializedNativeFields() {
            // Server side decodes this and merges back into the recursive
            // mapping tree, so each sub-handle becomes its own `nativeFields`
            // entry with the normal node/default shape.
            const out = {};
            for (const h of Object.keys(this.nativeFields)) {
                const row = this.nativeFields[h];
                if (!row || (row.node === '' && row.default === '')) continue;
                out[h] = {};
                if (row.node) out[h].node = row.node;
                if (row.default) out[h].default = row.default;
            }
            return Object.keys(out).length ? JSON.stringify(out) : '';
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
        t(msg) {
            return window.Craft ? Craft.t('influx', msg) : msg;
        },

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
            // Reactivity drives the hidden-input via :value="serializedOptions".
            // This method exists as an explicit hook in case future extras
            // need to do post-change validation.
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
.influx-mapping-extras {
    margin-top: 8px;
    padding: 8px 10px;
    background: #f8f9fb;
    border: 1px solid rgba(0, 0, 0, .06);
    border-radius: 4px;
    font-size: 12px;
}

.extras-header {
    display: flex;
    align-items: center;
    gap: 8px;
}

.kind-badge {
    background: #e7ecf3;
    color: #364d6b;
    padding: 1px 7px;
    border-radius: 9px;
    font-size: 11px;
    font-weight: 600;
}

.extras-toggle {
    background: none;
    border: 0;
    padding: 0;
    cursor: pointer;
    color: #364d6b;
    font-size: 11px;
}

.extras-toggle .chevron { display: inline-block; margin-right: 4px; }

.extras-body { margin-top: 8px; }

.extras-body .row {
    display: grid;
    grid-template-columns: 120px 1fr;
    gap: 8px;
    align-items: center;
    margin-bottom: 6px;
}

.extras-body label {
    font-size: 11px;
    font-weight: 600;
    color: #555;
}

.sub-fields {
    margin-top: 10px;
    border-top: 1px dashed rgba(0,0,0,.08);
    padding-top: 8px;
}

.sub-fields-title {
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #555;
    margin-bottom: 4px;
}

.sub-field-row {
    display: grid;
    grid-template-columns: 80px 1.4fr 1fr;
    gap: 6px;
    align-items: center;
    margin-bottom: 4px;
}

.sub-field-row label { font-weight: 500; color: #444; }

.value-map-row {
    display: grid;
    grid-template-columns: 1fr 24px 1fr 24px;
    gap: 6px;
    align-items: center;
    margin-bottom: 4px;
}

.value-map-row .arrow { color: #888; text-align: center; }

.remove-row {
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
