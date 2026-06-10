<template>
    <!-- Grouped options (relation/author match-by): a custom dropdown
         reusing the token picker's visual language — h6 group headings and
         kind-colored chip items — instead of a plain optgroup select. -->
    <div
        v-if="grouped"
        ref="root"
        class="influx-searchable-select influx-grouped-select"
        :class="{ open, disabled: readOnly, 'has-value': hasValue }"
    >
        <button
            type="button"
            class="influx-searchable-select-trigger"
            :class="{ active: open }"
            :disabled="readOnly"
            aria-haspopup="listbox"
            :aria-expanded="open ? 'true' : 'false'"
            @click="open = !open"
        >
            <span class="value" :class="{ placeholder: !hasValue }">{{ selectedLabel }}</span>
            <svg class="caret" width="10" height="10" viewBox="0 0 10 10" aria-hidden="true">
                <path d="M2 3.5l3 3 3-3" stroke="currentColor" stroke-width="1.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>

        <div v-if="open" class="influx-tokenized-picker-menu" role="listbox">
            <template v-for="(group, gi) in groups" :key="gi">
                <h6 v-if="group.label">{{ group.label }}</h6>
                <ul class="influx-token-group" :data-kind="group.kind || 'custom'">
                    <li v-for="opt in group.options" :key="opt.value">
                        <button
                            type="button"
                            class="influx-tokenized-picker-item"
                            :class="{ selected: opt.value === modelValue }"
                            role="option"
                            :aria-selected="opt.value === modelValue ? 'true' : 'false'"
                            @click="pick(opt)"
                        >
                            <span class="influx-tokenized-chip-inline" :data-kind="group.kind || 'custom'">{{ opt.label }}</span>
                        </button>
                    </li>
                </ul>
            </template>
        </div>
    </div>

    <!-- Flat options: the plain native select. -->
    <div v-else class="select">
        <select :value="modelValue ?? ''" :disabled="readOnly" @change="$emit('update:modelValue', $event.target.value)">
            <option v-for="opt in groups[0].options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
    </div>
</template>

<script>
/**
 * Schema select node. Accepts both option shapes PHP ships:
 *
 *   - flat [{value, label}] — rendered as a native select;
 *   - grouped [{label, kind?, options: [...]}] — rendered as a custom
 *     dropdown borrowing the token picker's group/chip visuals; the
 *     group's `kind` drives the chip colors (element / fields / ...).
 */
export default {
    name: 'SelectInput',

    props: {
        node: { type: Object, required: true },
        modelValue: { type: [String, Number], default: '' },
        readOnly: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            open: false,
        };
    },

    computed: {
        grouped() {
            const options = this.node.options || [];
            return options.length > 0 && Array.isArray(options[0]?.options);
        },

        groups() {
            const options = this.node.options || [];
            return this.grouped ? options : [{ label: null, kind: null, options }];
        },

        hasValue() {
            return this.modelValue !== '' && this.modelValue != null;
        },

        selectedLabel() {
            for (const group of this.groups) {
                const hit = (group.options || []).find(opt => opt.value === this.modelValue);
                if (hit) return hit.label;
            }
            return this.hasValue ? String(this.modelValue) : '—';
        },
    },

    mounted() {
        this._outsideHandler = (e) => {
            if (this.open && this.$refs.root && !this.$refs.root.contains(e.target)) {
                this.open = false;
            }
        };
        this._escHandler = (e) => {
            if (this.open && e.key === 'Escape') {
                this.open = false;
            }
        };
        document.addEventListener('mousedown', this._outsideHandler);
        document.addEventListener('keydown', this._escHandler);
    },

    beforeUnmount() {
        document.removeEventListener('mousedown', this._outsideHandler);
        document.removeEventListener('keydown', this._escHandler);
    },

    methods: {
        pick(opt) {
            this.$emit('update:modelValue', opt.value);
            this.open = false;
        },
    },
};
</script>
