<template>
    <div class="influx-site-endpoints">
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
 * Editor for `link.siteEndpoints` — a {siteHandle: url} map rendered in
 * Craft's standard editable-table shell. Site options come from the
 * bootstrap payload so the user can only pick handles that exist, and a
 * site already used by another row is disabled so the same site can't be
 * defined twice. URLs get the same tokenized input (env vars / aliases)
 * as the base endpoint.
 */
import TokenizedInput from './TokenizedInput.vue';

export default {
    name: 'SiteEndpointsTable',

    components: { TokenizedInput },

    props: {
        modelValue:  { type: Object, default: () => ({}) },
        sites:       { type: Array, default: () => [] },
        tokenGroups: { type: Array, default: () => [] },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            rows: this.fromValue(this.modelValue),
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

        isTakenElsewhere(handle, rowIdx) {
            return this.rows.some((r, i) => i !== rowIdx && r.handle === handle);
        },

        addRow() {
            if (this.allSitesTaken) return;
            this.rows.push({ handle: '', url: '' });
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
