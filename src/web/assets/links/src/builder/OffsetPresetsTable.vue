<template>
    <div>
        <table class="editable fullwidth" :class="{ hidden: !rows.length }">
            <col v-for="i in 4" :key="i">
            <col>
            <thead>
                <tr>
                    <th class="singleline-cell textual" scope="col">{{ $t('Handle') }}</th>
                    <th class="singleline-cell textual has-info" scope="col">
                        {{ $t('Since') }}
                        <span class="info" v-html="$t('Anything <code>DateTime::modify</code> accepts.')"></span>
                    </th>
                    <th class="singleline-cell textual" scope="col">{{ $t('Query param') }}</th>
                    <th class="singleline-cell textual has-info" scope="col">
                        {{ $t('Date format') }}
                        <span class="info" v-html="$t('Anything <code>DateTime::format</code> accepts.')"></span>
                    </th>
                    <th class="thin">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, idx) in rows" :key="idx">
                    <td class="singleline-cell textual code">
                        <textarea rows="1" v-model="row.handle" :placeholder="$t('e.g. last24h')" @input="onChange"></textarea>
                    </td>
                    <td class="singleline-cell textual code">
                        <textarea rows="1" v-model="row.since" placeholder="-1 day" @input="onChange"></textarea>
                    </td>
                    <td class="singleline-cell textual code">
                        <textarea rows="1" v-model="row.queryParam" placeholder="updated_since" @input="onChange"></textarea>
                    </td>
                    <td class="singleline-cell textual code">
                        <textarea rows="1" v-model="row.format" placeholder="ATOM" @input="onChange"></textarea>
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

        <button type="button" class="btn dashed add icon" @click="addRow">{{ $t('Add a preset') }}</button>
    </div>
</template>

<script>
/**
 * Editor for `link.offset` — the sliding-window preset map. Renders inside
 * Craft's standard `table.editable.fullwidth` so the CP CSS handles cell
 * borders, focus rings, and dashed add button styling. Rows are kept as a
 * positional list internally and round-tripped to the `{handle: {...}}` shape
 * the link payload uses on emit.
 */
export default {
    name: 'OffsetPresetsTable',

    props: {
        modelValue: { type: Object, default: () => ({}) },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            rows: this.fromValue(this.modelValue),
        };
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
            return Object.entries(value || {}).map(([handle, preset]) => ({
                handle,
                since:      preset?.since      ?? '',
                queryParam: preset?.queryParam ?? '',
                format:     preset?.format     ?? '',
            }));
        },

        toValue(rows) {
            const out = {};
            for (const row of rows) {
                const handle = (row.handle || '').trim();
                const since = (row.since || '').trim();
                const queryParam = (row.queryParam || '').trim();
                if (!handle || !since || !queryParam) continue;
                const entry = { since, queryParam };
                const format = (row.format || '').trim();
                if (format) entry.format = format;
                out[handle] = entry;
            }
            return { value: out, serialized: JSON.stringify(out) };
        },

        addRow() {
            this.rows.push({ handle: '', since: '', queryParam: '', format: '' });
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
