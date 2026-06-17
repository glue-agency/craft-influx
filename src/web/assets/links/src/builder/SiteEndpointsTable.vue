<template>
    <div class="influx-site-endpoints">
        <table class="editable fullwidth" :class="{ hidden: !rows.length }">
            <col class="thin">
            <col>
            <col>
            <col>
            <thead>
                <tr>
                    <th class="thin">&nbsp;</th>
                    <th class="select-cell" scope="col">{{ $t('Site') }}</th>
                    <th class="singleline-cell textual" scope="col">{{ $t('Endpoint URL') }}</th>
                    <th class="thin">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(row, idx) in rows"
                    :key="row._id"
                    :class="{ 'influx-dragging': dragIndex === idx }"
                    @dragover.prevent="onDragOver(idx)"
                    @drop.prevent
                >
                    <td class="thin action">
                        <a
                            class="move icon"
                            :title="$t('Reorder')"
                            draggable="true"
                            @dragstart="onDragStart(idx, $event)"
                            @dragend="onDragEnd"
                        ></a>
                    </td>
                    <td class="select-cell">
                        <div class="select small">
                            <select v-model="row.handle" @change="onChange">
                                <option value="">{{ $t('— select a site —') }}</option>
                                <option
                                    v-for="o in sites"
                                    :key="o.value"
                                    :value="o.value"
                                    :disabled="isTakenElsewhere(o.value, idx)"
                                >{{ o.label }}</option>
                            </select>
                        </div>
                    </td>
                    <td class="singleline-cell textual">
                        <tokenized-input
                            :model-value="row.url"
                            :token-groups="tokenGroups"
                            placeholder="https://…"
                            @update:model-value="onUrlInput(row, $event)"
                        />
                    </td>
                    <td class="thin action">
                        <button
                            type="button"
                            class="delete icon"
                            :title="$t('Delete row {idx}', { idx: idx + 1 })"
                            @click="removeRow(idx)"
                        ></button>
                    </td>
                </tr>
            </tbody>
        </table>

        <button
            type="button"
            class="btn dashed add icon"
            :disabled="allSitesTaken"
            @click="addRow"
        >{{ $t('Add a site endpoint') }}</button>
    </div>
</template>

<script>
/**
 * Editor for `link.siteEndpoints` — an ordered list of {site, endpoint}
 * rows rendered in Craft's standard editable-table shell. Site options come from the
 * bootstrap payload so the user can only pick handles that exist, and a
 * site already used by another row is disabled so the same site can't be
 * defined twice. URLs get the same tokenized input (env vars / aliases)
 * as the base endpoint.
 *
 * Rows are drag-sortable by the leading `.move` handle: a link runs once
 * per configured site in row order, and that order round-trips because
 * {@link toValue} rebuilds the map in row order and both JS objects and
 * PHP assoc arrays preserve insertion order through Project Config.
 */
import TokenizedInput from './TokenizedInput.vue';

// Stable per-row key so Vue tracks the dragged <tr> by identity (not index)
// while the rows array is reordered live during a drag.
let nextRowId = 0;

export default {
    name: 'SiteEndpointsTable',

    components: { TokenizedInput },

    props: {
        modelValue:  { type: Array, default: () => [] },
        sites:       { type: Array, default: () => [] },
        tokenGroups: { type: Array, default: () => [] },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            rows: this.fromValue(this.modelValue),
            dragIndex: null,
        };
    },

    computed: {
        allSitesTaken() {
            return this.sites.length > 0
                && this.sites.every(o => this.rows.some(r => r.handle === o.value));
        },
    },

    watch: {
        modelValue: {
            deep: true,
            handler(next) {
                if (this.toValue(this.rows).serialized === JSON.stringify(next || [])) return;
                this.rows = this.fromValue(next);
            },
        },
    },

    methods: {
        fromValue(value) {
            // The wire shape is an ordered list of {site, endpoint}; tolerate a
            // legacy {handle: url} map in case one is ever fed in programmatically.
            const list = Array.isArray(value)
                ? value.map((row) => ({ handle: row.site ?? '', url: row.endpoint ?? '' }))
                : Object.entries(value || {}).map(([handle, url]) => ({ handle, url: url ?? '' }));

            return list.map((row) => ({ _id: nextRowId++, ...row }));
        },

        toValue(rows) {
            const out = [];
            for (const row of rows) {
                const site = (row.handle || '').trim();
                const url = (row.url || '').trim();
                if (!site || !url) continue;
                out.push({ site, endpoint: url });
            }
            return { value: out, serialized: JSON.stringify(out) };
        },

        isTakenElsewhere(handle, rowIdx) {
            return this.rows.some((r, i) => i !== rowIdx && r.handle === handle);
        },

        addRow() {
            if (this.allSitesTaken) return;
            this.rows.push({ _id: nextRowId++, handle: '', url: '' });
        },

        onDragStart(idx, e) {
            this.dragIndex = idx;
            e.dataTransfer.effectAllowed = 'move';
            // Firefox only starts a drag once some data is set.
            e.dataTransfer.setData('text/plain', String(idx));
            // Drag the whole row, not just the grabbed handle.
            const tr = e.target.closest('tr');
            if (tr) e.dataTransfer.setDragImage(tr, 0, 0);
        },

        onDragOver(idx) {
            if (this.dragIndex === null || this.dragIndex === idx) return;
            const [moved] = this.rows.splice(this.dragIndex, 1);
            this.rows.splice(idx, 0, moved);
            this.dragIndex = idx;
        },

        onDragEnd() {
            // Persist the reordered map only when a drag actually moved a row.
            if (this.dragIndex !== null) this.onChange();
            this.dragIndex = null;
        },

        removeRow(idx) {
            this.rows.splice(idx, 1);
            this.onChange();
        },

        onUrlInput(row, value) {
            row.url = value;
            this.onChange();
        },

        onChange() {
            this.$emit('update:modelValue', this.toValue(this.rows).value);
        },
    },
};
</script>

<style scoped>
.influx-site-endpoints .influx-dragging {
    opacity: 0.4;
}

.influx-site-endpoints .move.icon {
    cursor: grab;
}
</style>
