<template>
    <div>
        <table class="editable fullwidth" :class="{ hidden: ! rows.length }">
            <col v-for="i in 3" :key="i">
            <col>
            <thead>
                <tr>
                    <th class="singleline-cell textual" scope="col" v-text="$t('Handle')"></th>
                    <th class="singleline-cell textual" scope="col" v-text="$t('Query param')"></th>
                    <th class="singleline-cell textual has-info" scope="col">
                        {{ $t('Value') }}
                        <span class="info" v-text="$t('Supports Twig syntax. Evaluated on every run.')"></span>
                    </th>
                    <th class="thin">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, idx) in rows" :key="idx">
                    <td class="singleline-cell textual code">
                        <textarea rows="1" v-model="row.handle" :disabled="disabled" :placeholder="$t('e.g. last24h')" @input="onChange"></textarea>
                    </td>
                    <td class="singleline-cell textual code">
                        <textarea rows="1" v-model="row.queryParam" :disabled="disabled" placeholder="updated_since" @input="onChange"></textarea>
                    </td>
                    <td class="singleline-cell textual code">
                        <textarea rows="1" v-model="row.value" :disabled="disabled" :placeholder="valuePlaceholder" @input="onChange"></textarea>
                    </td>
                    <td class="thin action">
                        <button
                            v-if="! disabled"
                            type="button"
                            class="delete icon"
                            :title="$t('Delete row {idx}', { idx: idx + 1 })"
                            @click="removeRow(idx)"
                        />
                    </td>
                </tr>
            </tbody>
        </table>

        <button
            v-if="! disabled"
            type="button"
            class="btn dashed add icon"
            @click="addRow"
            v-text="$t('Add a preset')"
        />
    </div>
</template>

<script>
/**
 * Editor for `link.offset` — the partial-import preset map. Renders inside
 * Craft's standard `table.editable.fullwidth` so the CP CSS handles cell
 * borders, focus rings, and dashed add button styling. Rows are kept as a
 * positional list internally and round-tripped to the `{handle: {...}}` shape
 * the link payload uses on emit.
 *
 * The value is a Twig template the server renders per run rather than a date
 * format it derives, so the timezone and shape the remote API expects are stated
 * here and nowhere else — see the OffsetPreset model for why. A row missing any
 * of its three cells is dropped on emit rather than half-saved: the server
 * treats an unfinished preset as a failed run, not a full fetch.
 */
export default {
    name: 'OffsetPresetsTable',

    emits: ['update:modelValue'],

    props: {
        modelValue: { type: Object, default: () => ({}) },
        disabled:   { type: Boolean, default: false },
    },

    data() {
        return {
            rows: this.fromValue(this.modelValue),
            // Not translated: it's the literal Twig to type, not prose about it.
            valuePlaceholder: "{{ now|date_modify('-1 day')|date('c', 'UTC') }}",
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

    mounted() {
        // Craft initializes `.info` icons via a jQuery plugin on document
        // ready — that pass happened before Vue mounted these headers, so
        // we wire them up now. `nextTick` ensures the spans are painted
        // before we hand them to the plugin.
        this.$nextTick(() => {
            const $ = window.jQuery;
            if ($ && $.fn.infoicon) {
                $(this.$el).find('.info').infoicon();
            }
        });
    },

    methods: {
        fromValue(value) {
            return Object.entries(value || {}).map(([handle, preset]) => ({
                handle,
                queryParam: preset?.queryParam ?? '',
                value:      preset?.value      ?? '',
            }));
        },

        toValue(rows) {
            const out = {};
            for (const row of rows) {
                const handle = (row.handle || '').trim();
                const queryParam = (row.queryParam || '').trim();
                const value = (row.value || '').trim();
                if (! handle || ! queryParam || ! value) continue;
                out[handle] = { queryParam, value };
            }
            return { value: out, serialized: JSON.stringify(out) };
        },

        addRow() {
            this.rows.push({ handle: '', queryParam: '', value: '' });
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
