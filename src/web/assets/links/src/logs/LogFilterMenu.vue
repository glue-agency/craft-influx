<template>
    <div class="influx-log-filter">
        <button
            type="button"
            class="btn menubtn influx-log-filter-btn"
            :class="{ active: open }"
            aria-haspopup="true"
            :aria-expanded="open ? 'true' : 'false'"
            @click.stop="open = !open"
        >
            <span class="status" :class="selectedColor"></span>{{ selectedLabel }}
        </button>

        <!-- Craft element-index-style status menu: pick All or a single action;
             a checkmark marks the active row, the dot carries its colour. -->
        <div v-if="open" class="influx-log-filter-menu">
            <ul>
                <li>
                    <button
                        type="button"
                        class="influx-log-filter-option"
                        :class="{ 'is-active': modelValue === null }"
                        @click.stop="select(null)"
                    >
                        <span class="status"></span>
                        <span class="influx-log-filter-label">{{ $t('All') }}</span>
                    </button>
                </li>
                <li v-for="f in filterDefs" :key="f.action">
                    <button
                        type="button"
                        class="influx-log-filter-option"
                        :class="{ 'is-active': modelValue === f.action }"
                        @click.stop="select(f.action)"
                    >
                        <span class="status" :class="f.color"></span>
                        <span class="influx-log-filter-label">{{ $t(f.action) }}</span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>

<script>
/**
 * The run-log action filter, as a Craft element-index-style status menu: a
 * pill button (native `.status` dot + the active label) opening a single-select
 * menu of All + each action with its status dot and a checkmark on the active
 * row. Emits `update:model-value` with the chosen action, or null for "All".
 */
export default {
    name: 'LogFilterMenu',

    props: {
        filterDefs: { type: Array, required: true },
        // The active action value, or null for "All".
        modelValue: { type: String, default: null },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            open: false,
        };
    },

    computed: {
        selectedLabel() {
            return this.modelValue ? this.$t(this.modelValue) : this.$t('All');
        },

        // The native Craft `.status` colour for the active action; the bare
        // (hollow) status for "All".
        selectedColor() {
            return this.filterDefs.find((f) => f.action === this.modelValue)?.color ?? '';
        },
    },

    mounted() {
        this._onDocClick = (e) => {
            if (this.open && ! this.$el.contains(e.target)) {
                this.open = false;
            }
        };
        this._onKey = (e) => {
            if (e.key === 'Escape') {
                this.open = false;
            }
        };
        document.addEventListener('click', this._onDocClick);
        document.addEventListener('keydown', this._onKey);
    },

    beforeUnmount() {
        document.removeEventListener('click', this._onDocClick);
        document.removeEventListener('keydown', this._onKey);
    },

    methods: {
        select(action) {
            this.open = false;

            if (action !== this.modelValue) {
                this.$emit('update:modelValue', action);
            }
        },
    },
};
</script>

<style scoped>
.influx-log-filter {
    position: relative;
    display: inline-block;
}

.influx-log-filter-btn .status { margin-right: 5px; }

/* Menu panel — Craft-menu chrome (white card, soft shadow, rounded). */
.influx-log-filter-menu {
    position: absolute;
    top: calc(100% + 3px);
    left: 0;
    z-index: 100;
    min-width: 200px;
    padding: 4px;
    background: var(--white);
    border-radius: var(--menu-border-radius, 5px);
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.15), 0 0 1px rgba(0, 0, 0, 0.25);
}

.influx-log-filter-menu ul {
    margin: 0;
    padding: 0;
    list-style: none;
}

.influx-log-filter-option {
    position: relative;
    display: flex;
    align-items: center;
    gap: 7px;
    width: 100%;
    padding: 6px 10px 6px 24px;
    border: 0;
    border-radius: 4px;
    background: none;
    font-size: 14px;
    text-align: left;
    cursor: pointer;
}

.influx-log-filter-option:hover { background: var(--gray-100); }

/* Checkmark in the left gutter for the active row. */
.influx-log-filter-option.is-active::before {
    content: '✓';
    position: absolute;
    left: 8px;
    font-size: 11px;
    color: var(--enabled-color);
}

.influx-log-filter-label { color: var(--text-color); }
</style>
