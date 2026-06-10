<template>
    <div class="value-map">
        <div class="value-map-row" v-for="(row, idx) in rows" :key="idx">
            <input
                type="text"
                class="text"
                :value="row.remote"
                :placeholder="$t('Remote value')"
                :disabled="readOnly"
                @input="updateRow(idx, 'remote', $event.target.value)"
            >
            <div class="value-map-target">
                <span class="arrow">→</span>
                <div class="select">
                    <select :value="row.local" :disabled="readOnly" @change="updateRow(idx, 'local', $event.target.value)">
                        <option value="">{{ $t('— pick —') }}</option>
                        <option v-for="(label, value) in (node.localOptions || {})" :key="value" :value="value">
                            {{ label }}
                        </option>
                    </select>
                </div>
            </div>
            <button
                type="button"
                class="remove-row"
                :title="$t('Remove row')"
                :disabled="readOnly"
                @click="removeRow(idx)"
            >×</button>
        </div>
        <div class="row-actions">
            <button type="button" class="btn small" :disabled="readOnly" @click="addRow">
                {{ $t('Add value map') }}
            </button>
        </div>
    </div>
</template>

<script>
/**
 * Schema valueMapTable node: remote value → local option rewrite rows
 * (Dropdown/Radio/Checkboxes/MultiSelect). The wire value is a plain
 * `{remote: local}` map; incomplete rows live only in local state and
 * fall out of the emitted map until both sides are filled.
 */
export default {
    name: 'ValueMapTable',

    props: {
        node: { type: Object, required: true },
        modelValue: { type: Object, default: () => ({}) },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            rows: Object.keys(this.modelValue).map((remote) => ({ remote, local: this.modelValue[remote] })),
        };
    },

    methods: {
        updateRow(idx, key, value) {
            this.rows[idx][key] = value;
            this.commit();
        },

        addRow() {
            this.rows.push({ remote: '', local: '' });
        },

        removeRow(idx) {
            this.rows.splice(idx, 1);
            this.commit();
        },

        commit() {
            const map = {};
            for (const row of this.rows) {
                if (row.remote && row.local) {
                    map[row.remote] = row.local;
                }
            }
            this.$emit('update:modelValue', map);
        },
    },
};
</script>
