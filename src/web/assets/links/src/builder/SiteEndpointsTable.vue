<template>
    <div>
        <table class="editable fullwidth" :class="{ hidden: !rows.length }">
            <col>
            <col>
            <col>
            <thead>
                <tr>
                    <th class="select-cell" scope="col">{{ $t('Site') }}</th>
                    <th class="singleline-cell textual" scope="col">{{ $t('Endpoint URL') }}</th>
                    <th class="thin">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, idx) in rows" :key="idx">
                    <td class="select-cell">
                        <div class="select small">
                            <select v-model="row.handle" @change="onChange">
                                <option value="">{{ $t('— select a site —') }}</option>
                                <option v-for="o in sites" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                        </div>
                    </td>
                    <td class="singleline-cell textual">
                        <textarea rows="1" v-model="row.url" placeholder="https://…" @input="onChange"></textarea>
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

        <button type="button" class="btn dashed add icon" @click="addRow">{{ $t('Add a site endpoint') }}</button>
    </div>
</template>

<script>
/**
 * Editor for `link.siteEndpoints` — a {siteHandle: url} map rendered in
 * Craft's standard editable-table shell. Site options come from the
 * bootstrap payload so the user can only pick handles that exist.
 */
export default {
    name: 'SiteEndpointsTable',

    props: {
        modelValue: { type: Object, default: () => ({}) },
        sites:      { type: Array, default: () => [] },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            rows: this.fromValue(this.modelValue),
        };
    },

    watch: {
        modelValue: {
            deep: true,
            handler(next) {
                if (this.toValue(this.rows).serialized === JSON.stringify(next || {})) return;
                this.rows = this.fromValue(next);
            },
        },
    },

    methods: {
        fromValue(value) {
            return Object.entries(value || {}).map(([handle, url]) => ({ handle, url: url ?? '' }));
        },

        toValue(rows) {
            const out = {};
            for (const row of rows) {
                const handle = (row.handle || '').trim();
                const url = (row.url || '').trim();
                if (!handle || !url) continue;
                out[handle] = url;
            }
            return { value: out, serialized: JSON.stringify(out) };
        },

        addRow() {
            this.rows.push({ handle: '', url: '' });
        },

        removeRow(idx) {
            this.rows.splice(idx, 1);
            this.onChange();
        },

        onChange() {
            this.$emit('update:modelValue', this.toValue(this.rows).value);
        },
    },
};
</script>
